<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API Service
 */
class TelegramService
{
    private string $botToken;
    private string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('telegram.bot_token');
        $this->apiUrl = config('telegram.api_url');
        
        if (empty($this->botToken)) {
            throw new \RuntimeException('Telegram bot token is not configured');
        }
    }

    /**
     * Send text message
     *
     * @param int|string $chatId
     * @param string $text
     * @param int|null $replyToMessageId
     * @return int|null Message ID if successful, null otherwise
     */
    public function sendMessage(int|string $chatId, string $text, ?int $replyToMessageId = null): ?int
    {
        try {
            // Reduced timeout from 10 to 5 seconds for faster response
            $response = Http::timeout(5)->post("{$this->apiUrl}{$this->botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $replyToMessageId,
            ]);

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => 'sendMessage',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['result']['message_id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Delete message
     *
     * @param int|string $chatId
     * @param int $messageId
     * @return bool
     */
    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}{$this->botToken}/deleteMessage", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => 'deleteMessage',
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete Telegram message', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send photo
     *
     * @param int|string $chatId
     * @param string $photoPath
     * @param string|null $caption
     * @param int|null $replyToMessageId
     * @return bool
     */
    public function sendPhoto(int|string $chatId, string $photoPath, ?string $caption = null, ?int $replyToMessageId = null): bool
    {
        try {
            // 1. File existence check
            if (!file_exists($photoPath)) {
                Log::error('Photo file does not exist', ['path' => $photoPath]);
                return false;
            }

            // 2. Verify it's actually an image
            $imageInfo = @getimagesize($photoPath);
            if ($imageInfo === false) {
                Log::error('File is not a valid image', ['path' => $photoPath]);
                return false;
            }

            // 3. Check file size (Telegram limit: 10MB for photos)
            $fileSize = filesize($photoPath);
            $maxFileSize = 10 * 1024 * 1024; // 10MB
            
            if ($fileSize > $maxFileSize) {
                Log::warning('Photo file too large for Telegram', [
                    'path' => $photoPath,
                    'size' => $fileSize,
                    'size_mb' => round($fileSize / 1024 / 1024, 2),
                ]);
                return false;
            }

            // 4. Convert webp to jpg for better compatibility
            $finalPhotoPath = $photoPath;
            $extension = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
            if ($extension === 'webp') {
                $finalPhotoPath = $this->convertWebpToJpg($photoPath);
                if ($finalPhotoPath === null) {
                    Log::warning('Failed to convert webp to jpg, trying original', ['path' => $photoPath]);
                    $finalPhotoPath = $photoPath; // Fallback to original
                }
            }

            // 5. Use file handle for memory efficiency (especially for large files)
            $fileHandle = fopen($finalPhotoPath, 'r');
            if ($fileHandle === false) {
                Log::error('Failed to open photo file', ['path' => $finalPhotoPath]);
                return false;
            }

            try {
                $response = Http::timeout(30)->attach(
                    'photo',
                    $fileHandle,
                    basename($finalPhotoPath)
                )->post("{$this->apiUrl}{$this->botToken}/sendPhoto", [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $replyToMessageId,
                ]);

                if (!$response->successful()) {
                    $responseBody = $response->body();
                    $responseData = $response->json();
                    
                    Log::error('Telegram API error', [
                        'method' => 'sendPhoto',
                        'chat_id' => $chatId,
                        'status' => $response->status(),
                        'body' => $responseBody,
                        'response_data' => $responseData,
                    ]);
                    
                    // Check for specific errors
                    if (str_contains($responseBody, 'bot is not a member') || 
                        str_contains($responseBody, 'chat not found') ||
                        str_contains($responseBody, 'not enough rights') ||
                        str_contains($responseBody, 'BOT_IS_NOT_A_MEMBER') ||
                        str_contains($responseBody, 'CHAT_ADMIN_REQUIRED') ||
                        str_contains($responseBody, 'can\'t send media messages')) {
                        Log::error('Bot cannot send media in group - permission issue', [
                            'chat_id' => $chatId,
                            'error' => $responseBody,
                            'solution' => 'Bot must be admin or have "Send Messages" permission in the group',
                        ]);
                    }
                    
                    return false;
                }

                return true;
            } finally {
                fclose($fileHandle);
                
                // Clean up converted file if different from original
                if ($finalPhotoPath !== $photoPath && file_exists($finalPhotoPath)) {
                    @unlink($finalPhotoPath);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram photo', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Convert WebP image to JPG for better Telegram compatibility
     *
     * @param string $webpPath
     * @return string|null Path to converted JPG file, or null on failure
     */
    private function convertWebpToJpg(string $webpPath): ?string
    {
        try {
            // Check if GD extension is available
            if (!extension_loaded('gd')) {
                Log::warning('GD extension not available, cannot convert webp');
                return null;
            }

            // Check if webp support is available
            if (!function_exists('imagecreatefromwebp')) {
                Log::warning('WebP support not available in GD');
                return null;
            }

            // Create image from webp
            $image = @imagecreatefromwebp($webpPath);
            if ($image === false) {
                Log::warning('Failed to create image from webp', ['path' => $webpPath]);
                return null;
            }

            // Generate output path
            $outputPath = str_replace(['.webp', '.WEBP'], '.jpg', $webpPath);
            
            // Convert to JPG with 90% quality
            $success = @imagejpeg($image, $outputPath, 90);
            imagedestroy($image);

            if (!$success || !file_exists($outputPath)) {
                Log::warning('Failed to save converted JPG', ['path' => $outputPath]);
                return null;
            }

            Log::info('WebP converted to JPG', [
                'original' => basename($webpPath),
                'converted' => basename($outputPath),
                'original_size' => filesize($webpPath),
                'converted_size' => filesize($outputPath),
            ]);

            return $outputPath;
        } catch (\Exception $e) {
            Log::error('Exception converting webp to jpg', [
                'path' => $webpPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send video
     *
     * @param int|string $chatId
     * @param string $videoPath
     * @param string|null $caption
     * @param int|null $replyToMessageId
     * @return bool
     */
    public function sendVideo(int|string $chatId, string $videoPath, ?string $caption = null, ?int $replyToMessageId = null): bool
    {
        try {
            if (!file_exists($videoPath)) {
                Log::error('Video file does not exist', ['path' => $videoPath]);
                return false;
            }

            $fileSize = filesize($videoPath);
            $maxFileSize = 50 * 1024 * 1024; // 50MB Telegram limit

            if ($fileSize > $maxFileSize) {
                Log::warning('Video file too large for Telegram', [
                    'path' => $videoPath,
                    'size' => $fileSize,
                    'size_mb' => round($fileSize / 1024 / 1024, 2),
                    'max' => $maxFileSize,
                ]);
                // Return false to indicate file is too large
                // The caller should handle this by sending a message to user
                return false;
            }

            $response = Http::timeout(60)->attach(
                'video',
                file_get_contents($videoPath),
                basename($videoPath)
            )->post("{$this->apiUrl}{$this->botToken}/sendVideo", [
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $replyToMessageId,
            ]);

            if (!$response->successful()) {
                $responseBody = $response->body();
                $responseData = $response->json();
                
                Log::error('Telegram API error', [
                    'method' => 'sendVideo',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $responseBody,
                    'response_data' => $responseData,
                ]);
                
                // Check for specific errors
                if (str_contains($responseBody, 'bot is not a member') || 
                    str_contains($responseBody, 'chat not found') ||
                    str_contains($responseBody, 'not enough rights') ||
                    str_contains($responseBody, 'BOT_IS_NOT_A_MEMBER') ||
                    str_contains($responseBody, 'CHAT_ADMIN_REQUIRED') ||
                    str_contains($responseBody, 'can\'t send media messages')) {
                    Log::error('Bot cannot send media in group - permission issue', [
                        'chat_id' => $chatId,
                        'error' => $responseBody,
                        'solution' => 'Bot must be admin or have "Send Messages" permission in the group',
                    ]);
                }
                
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram video', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send multiple photos as media group
     *
     * @param int|string $chatId
     * @param array $photoPaths
     * @param string|null $caption
     * @return bool
     */
    public function sendMediaGroup(int|string $chatId, array $photoPaths, ?string $caption = null): bool
    {
        try {
            if (empty($photoPaths)) {
                return false;
            }

            // Telegram allows max 10 media in a group
            $photoPaths = array_slice($photoPaths, 0, 10);

            // Convert webp files and prepare media array
            $media = [];
            $convertedFiles = [];
            
            foreach ($photoPaths as $index => $photoPath) {
                if (!file_exists($photoPath)) {
                    continue;
                }

                // Convert webp if needed
                $finalPath = $photoPath;
                $extension = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
                if ($extension === 'webp') {
                    $converted = $this->convertWebpToJpg($photoPath);
                    if ($converted !== null) {
                        $finalPath = $converted;
                        $convertedFiles[] = $converted;
                    }
                }

                $media[] = [
                    'type' => 'photo',
                    'media' => 'attach://photo_' . $index,
                ];
            }

            if (empty($media)) {
                return false;
            }

            // Add caption to first media item
            if ($caption) {
                $media[0]['caption'] = $caption;
                $media[0]['parse_mode'] = 'HTML';
            }

            // Prepare multipart data for Laravel HTTP client
            $multipart = [
                ['name' => 'chat_id', 'contents' => (string) $chatId],
                ['name' => 'media', 'contents' => json_encode($media)],
                ['name' => 'parse_mode', 'contents' => 'HTML'],
            ];

            // Add each photo file (use converted paths if available)
            foreach ($photoPaths as $index => $photoPath) {
                if (!file_exists($photoPath)) {
                    continue;
                }

                // Use converted path if webp was converted
                $finalPath = $photoPath;
                $extension = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
                if ($extension === 'webp') {
                    // Find corresponding converted file
                    foreach ($convertedFiles as $converted) {
                        if (str_replace(['.webp', '.WEBP'], '.jpg', $photoPath) === $converted) {
                            $finalPath = $converted;
                            break;
                        }
                    }
                }

                if (file_exists($finalPath)) {
                    $multipart[] = [
                        'name' => 'photo_' . $index,
                        'contents' => fopen($finalPath, 'r'),
                        'filename' => basename($finalPath),
                    ];
                }
            }

            $response = Http::timeout(60)->asMultipart()->post(
                "{$this->apiUrl}{$this->botToken}/sendMediaGroup",
                $multipart
            );

            // Close file handles
            foreach ($multipart as $part) {
                if (isset($part['contents']) && is_resource($part['contents'])) {
                    fclose($part['contents']);
                }
            }

            // Clean up converted files
            foreach ($convertedFiles as $convertedFile) {
                if (file_exists($convertedFile)) {
                    @unlink($convertedFile);
                }
            }

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => 'sendMediaGroup',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram media group', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send message with inline keyboard (for language selection)
     *
     * @param int|string $chatId
     * @param string $text
     * @param array $keyboard
     * @param int|null $replyToMessageId
     * @return int|null Message ID if successful, null otherwise
     */
    public function sendMessageWithKeyboard(int|string $chatId, string $text, array $keyboard, ?int $replyToMessageId = null): ?int
    {
        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}{$this->botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $replyToMessageId,
                'reply_markup' => [
                    'inline_keyboard' => $keyboard,
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => 'sendMessageWithKeyboard',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['result']['message_id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message with keyboard', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get list of required channels
     * This method is public so it can be called from controller
     *
     * @return array
     */
    public function getRequiredChannels(): array
    {
        $channels = [];
        
        // Check for multiple channels (comma-separated)
        $requiredChannels = config('telegram.required_channels');
        
        // Debug logging
        Log::debug('Getting required channels', [
            'required_channels_config' => $requiredChannels,
            'required_channel_id' => config('telegram.required_channel_id'),
            'required_channel_username' => config('telegram.required_channel_username'),
        ]);
        
        if (!empty($requiredChannels)) {
            $channelList = explode(',', $requiredChannels);
            foreach ($channelList as $channel) {
                $channel = trim($channel);
                if (!empty($channel)) {
                    $channels[] = ltrim($channel, '@');
                }
            }
        }
        
        // Fallback to single channel config
        if (empty($channels)) {
            $channelId = config('telegram.required_channel_id');
            $channelUsername = config('telegram.required_channel_username');
            
            if ($channelId) {
                $channels[] = is_numeric($channelId) ? $channelId : ltrim($channelId, '@');
            } elseif ($channelUsername) {
                $channels[] = ltrim($channelUsername, '@');
            }
        }
        
        Log::debug('Required channels result', ['channels' => $channels]);
        
        return $channels;
    }

    /**
     * Check if user is member of required channel(s)
     *
     * @param int|string $userId
     * @return array ['is_member' => bool, 'missing_channels' => array]
     */
    public function checkChannelMembership(int|string $userId): array
    {
        $channels = $this->getRequiredChannels();
        $missingChannels = [];

        // If no channel is configured, allow access
        if (empty($channels)) {
            return ['is_member' => true, 'missing_channels' => []];
        }

        // Cache key for membership check (cache for 5 minutes to reduce API calls)
        $cacheKey = "channel_membership_{$userId}_" . md5(implode(',', $channels));
        $cachedResult = \Illuminate\Support\Facades\Cache::get($cacheKey);
        
        if ($cachedResult !== null) {
            Log::debug('Using cached channel membership result', [
                'user_id' => $userId,
                'cached_result' => $cachedResult,
            ]);
            return $cachedResult;
        }

        // Check all channels - user must be member of ALL channels
        foreach ($channels as $channel) {
            try {
                $chatId = is_numeric($channel) ? $channel : '@' . $channel;

                Log::info('Checking channel membership', [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'chat_id' => $chatId,
                ]);

                $response = Http::timeout(10)->post("{$this->apiUrl}{$this->botToken}/getChatMember", [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                ]);

                $responseBody = $response->body();
                $responseData = $response->json();
                
                // Log full response for debugging
                Log::info('getChatMember API response', [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'status_code' => $response->status(),
                    'response' => $responseData,
                ]);

                if (!$response->successful()) {
                    Log::warning('Failed to check channel membership', [
                        'user_id' => $userId,
                        'channel' => $channel,
                        'chat_id' => $chatId,
                        'status' => $response->status(),
                        'body' => $responseBody,
                        'response_data' => $responseData,
                    ]);
                    
                    // Check if bot is not admin or channel doesn't exist
                    if (str_contains($responseBody, 'bot is not a member') || 
                        str_contains($responseBody, 'chat not found') ||
                        str_contains($responseBody, 'not enough rights') ||
                        str_contains($responseBody, 'BOT_IS_NOT_A_MEMBER') ||
                        str_contains($responseBody, 'CHAT_ADMIN_REQUIRED')) {
                        Log::error('Bot cannot check channel membership - bot must be admin of the channel', [
                            'channel' => $channel,
                            'error' => $responseBody,
                            'solution' => 'Add bot as administrator to the channel with "View Members" permission',
                        ]);
                    }
                    
                    $missingChannels[] = $channel;
                    continue;
                }

                $status = $responseData['result']['status'] ?? null;
                $user = $responseData['result']['user'] ?? null;

                Log::info('Channel membership check result', [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'status' => $status,
                    'user' => $user ? ['id' => $user['id'] ?? null, 'username' => $user['username'] ?? null] : null,
                ]);

                // User is member if status is 'member', 'administrator', or 'creator'
                // Also check for 'restricted' status (user is restricted but still a member)
                $validStatuses = ['member', 'administrator', 'creator', 'restricted'];
                
                if (!in_array($status, $validStatuses)) {
                    Log::warning('User is not a member of channel', [
                        'user_id' => $userId,
                        'channel' => $channel,
                        'status' => $status,
                        'valid_statuses' => $validStatuses,
                    ]);
                    $missingChannels[] = $channel;
                    continue;
                }

                Log::info('User is a member of channel', [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'status' => $status,
                ]);
            } catch (\Exception $e) {
                Log::error('Error checking channel membership', [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $missingChannels[] = $channel;
            }
        }

        $result = [
            'is_member' => empty($missingChannels),
            'missing_channels' => $missingChannels,
        ];

        // Cache the result for 5 minutes to reduce API calls
        \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 300); // 5 minutes

        return $result;
    }

    /**
     * Send subscription required message with channel button(s)
     *
     * @param int|string $chatId
     * @param string $language
     * @param array $missingChannels List of channels user is not subscribed to
     * @return int|null Message ID if successful, null otherwise
     */
    public function sendSubscriptionRequiredMessage(int|string $chatId, string $language = 'en', array $missingChannels = []): ?int
    {
        $channels = $this->getRequiredChannels();

        if (empty($channels)) {
            return null;
        }

        // If missing channels are provided, show which ones are missing
        $missingChannelsText = '';
        if (!empty($missingChannels)) {
            $missingChannelsList = array_map(function($channel) {
                return "@{$channel}";
            }, $missingChannels);
            
            $missingChannelsStr = implode(', ', $missingChannelsList);
            
            // Localize missing channels text
            $missingTexts = [
                'uz' => "\n\n❌ <b>A'zo bo'lmagan:</b> {$missingChannelsStr}",
                'ru' => "\n\n❌ <b>Не подписаны:</b> {$missingChannelsStr}",
                'en' => "\n\n❌ <b>Not subscribed:</b> {$missingChannelsStr}",
            ];
            
            $missingChannelsText = $missingTexts[$language] ?? $missingTexts['en'];
        }

        $messages = [
            'uz' => "🔒 <b>Kanalga a'zo bo'lish majburiy!</b>{$missingChannelsText}\n\nA'zo bo'ling va <b>✅ Tekshirish</b> tugmasini bosing.",
            'ru' => "🔒 <b>Подписка на канал обязательна!</b>{$missingChannelsText}\n\nПодпишитесь и нажмите <b>✅ Проверить</b>.",
            'en' => "🔒 <b>Channel subscription required!</b>{$missingChannelsText}\n\nSubscribe and press <b>✅ Check</b>.",
        ];

        $text = $messages[$language] ?? $messages['en'];

        // Create keyboard with channel buttons
        // If missing channels are provided, show only those channels
        $channelsToShow = !empty($missingChannels) ? $missingChannels : $channels;
        
        $keyboard = [];
        
        // Add channel buttons (max 2 per row for better layout)
        $channelButtons = [];
        foreach ($channelsToShow as $channel) {
            $channelLink = ltrim($channel, '@');
            // Format channel name: capitalize first letter (TheUzSoft, Samiyev_blog)
            $channelButtonText = ucfirst($channelLink);
            $channelUrl = "https://t.me/{$channelLink}";
            
            $channelButtons[] = ['text' => $channelButtonText, 'url' => $channelUrl];
            
            // Add buttons in rows of 2
            if (count($channelButtons) >= 2) {
                $keyboard[] = $channelButtons;
                $channelButtons = [];
            }
        }
        
        // Add remaining buttons
        if (!empty($channelButtons)) {
            $keyboard[] = $channelButtons;
        }
        
        // Add check button
        $keyboard[] = [
            ['text' => '✅ Tekshirish', 'callback_data' => 'check_subscription'],
        ];

        return $this->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }
}
