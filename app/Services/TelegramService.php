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
            $response = Http::timeout(10)->post("{$this->apiUrl}{$this->botToken}/sendMessage", [
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
            if (!file_exists($photoPath)) {
                Log::error('Photo file does not exist', ['path' => $photoPath]);
                return false;
            }

            $response = Http::timeout(30)->attach(
                'photo',
                file_get_contents($photoPath),
                basename($photoPath)
            )->post("{$this->apiUrl}{$this->botToken}/sendPhoto", [
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $replyToMessageId,
            ]);

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => 'sendPhoto',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram photo', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return false;
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
                Log::error('Video file too large', [
                    'path' => $videoPath,
                    'size' => $fileSize,
                    'max' => $maxFileSize,
                ]);
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
                Log::error('Telegram API error', [
                    'method' => 'sendVideo',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
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

            $media = [];
            foreach ($photoPaths as $index => $photoPath) {
                if (!file_exists($photoPath)) {
                    continue;
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
            }

            // Prepare multipart data for Laravel HTTP client
            $multipart = [
                ['name' => 'chat_id', 'contents' => (string) $chatId],
                ['name' => 'media', 'contents' => json_encode($media)],
                ['name' => 'parse_mode', 'contents' => 'HTML'],
            ];

            // Add each photo file
            foreach ($photoPaths as $index => $photoPath) {
                if (file_exists($photoPath)) {
                    $multipart[] = [
                        'name' => 'photo_' . $index,
                        'contents' => fopen($photoPath, 'r'),
                        'filename' => basename($photoPath),
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
     *
     * @return array
     */
    private function getRequiredChannels(): array
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
     * @return bool
     */
    public function checkChannelMembership(int|string $userId): bool
    {
        $channels = $this->getRequiredChannels();

        // If no channel is configured, allow access
        if (empty($channels)) {
            return true;
        }

        // Check all channels - user must be member of ALL channels
        foreach ($channels as $channel) {
            try {
                $chatId = is_numeric($channel) ? $channel : '@' . $channel;

                $response = Http::timeout(10)->post("{$this->apiUrl}{$this->botToken}/getChatMember", [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                ]);

                if (!$response->successful()) {
                    Log::warning('Failed to check channel membership', [
                        'user_id' => $userId,
                        'channel' => $channel,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return false;
                }

                $data = $response->json();
                $status = $data['result']['status'] ?? null;

                // User is member if status is 'member', 'administrator', or 'creator'
                if (!in_array($status, ['member', 'administrator', 'creator'])) {
                    return false;
                }
            } catch (\Exception $e) {
                Log::error('Error checking channel membership', [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Send subscription required message with channel button(s)
     *
     * @param int|string $chatId
     * @param string $language
     * @return int|null Message ID if successful, null otherwise
     */
    public function sendSubscriptionRequiredMessage(int|string $chatId, string $language = 'en'): ?int
    {
        $channels = $this->getRequiredChannels();

        if (empty($channels)) {
            return null;
        }

        $messages = [
            'uz' => "🔒 <b>Kanalga a'zo bo'lish majburiy!</b>\n\n📢 Botdan foydalanish uchun quyidagi kanallarga a'zo bo'ling va <b>✅ Tekshirish</b> tugmasini bosing.",
            'ru' => "🔒 <b>Подписка на канал обязательна!</b>\n\n📢 Чтобы использовать бота, подпишитесь на следующие каналы и нажмите <b>✅ Проверить</b>.",
            'en' => "🔒 <b>Channel subscription required!</b>\n\n📢 To use the bot, please subscribe to the following channels and press <b>✅ Check</b>.",
        ];

        $text = $messages[$language] ?? $messages['en'];

        // Create keyboard with channel buttons
        $keyboard = [];
        
        // Add channel buttons (max 2 per row for better layout)
        $channelButtons = [];
        foreach ($channels as $channel) {
            $channelLink = ltrim($channel, '@');
            // Format channel name: capitalize first letter (TheUzSoft, Samiyev_blog)
            $channelButtonText = ucfirst($channelLink);
            $channelUrl = "https://t.me/{$channelLink}";
            
            $channelButtons[] = ['text' => "📢 {$channelButtonText}", 'url' => $channelUrl];
            
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
            ['text' => '✅ Tekshirish / Check', 'callback_data' => 'check_subscription'],
        ];

        return $this->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }
}
