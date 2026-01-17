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
     * Answer callback query
     *
     * @param string $callbackQueryId
     * @param string|null $text
     * @param bool $showAlert
     * @return bool
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): bool
    {
        try {
            $payload = [
                'callback_query_id' => $callbackQueryId,
            ];
            
            if ($text !== null) {
                $payload['text'] = $text;
            }
            
            if ($showAlert) {
                $payload['show_alert'] = true;
            }

            $response = Http::timeout(5)->post("{$this->apiUrl}{$this->botToken}/answerCallbackQuery", $payload);

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => 'answerCallbackQuery',
                    'callback_query_id' => $callbackQueryId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to answer callback query', [
                'callback_query_id' => $callbackQueryId,
                'error' => $e->getMessage(),
            ]);
            return false;
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
                Log::debug('Converting WebP to JPG before sending', ['path' => basename($photoPath)]);
                $convertedPath = $this->convertWebpToJpg($photoPath);
                if ($convertedPath !== null && file_exists($convertedPath)) {
                    $finalPhotoPath = $convertedPath;
                    Log::info('Using converted JPG instead of WebP', [
                        'original' => basename($photoPath),
                        'converted' => basename($convertedPath),
                    ]);
                } else {
                    Log::warning('Failed to convert webp to jpg, using original', [
                        'path' => $photoPath,
                        'converted_path' => $convertedPath,
                    ]);
                    // Keep original path - Telegram might accept webp
                }
            }

            // 5. Send photo using file_get_contents (like sendVideo)
            try {
                $response = Http::timeout(30)->attach(
                    'photo',
                    file_get_contents($finalPhotoPath),
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

                Log::info('Photo sent successfully', [
                    'chat_id' => $chatId,
                    'photo' => basename($finalPhotoPath),
                ]);
                
                return true;
            } finally {
                // Clean up converted file if different from original
                if ($finalPhotoPath !== $photoPath && file_exists($finalPhotoPath)) {
                    @unlink($finalPhotoPath);
                    Log::debug('Cleaned up converted photo file', ['path' => basename($finalPhotoPath)]);
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
            // Validate input file
            if (!file_exists($webpPath)) {
                Log::warning('WebP file does not exist for conversion', ['path' => $webpPath]);
                return null;
            }

            if (!is_readable($webpPath)) {
                Log::warning('WebP file is not readable', ['path' => $webpPath]);
                return null;
            }

            // Check if GD extension is available
            if (!extension_loaded('gd')) {
                Log::warning('GD extension not available, cannot convert webp', ['path' => $webpPath]);
                return null;
            }

            // Check if webp support is available
            if (!function_exists('imagecreatefromwebp')) {
                Log::warning('WebP support not available in GD', ['path' => $webpPath]);
                return null;
            }

            if (!function_exists('imagejpeg')) {
                Log::warning('JPEG support not available in GD', ['path' => $webpPath]);
                return null;
            }

            Log::debug('Starting WebP to JPG conversion', [
                'path' => basename($webpPath),
                'size' => filesize($webpPath),
            ]);

            // Create image from webp
            $image = @imagecreatefromwebp($webpPath);
            if ($image === false) {
                Log::warning('Failed to create image from webp', [
                    'path' => $webpPath,
                    'error' => error_get_last()['message'] ?? 'Unknown error',
                ]);
                return null;
            }

            // Generate output path (replace .webp extension with .jpg)
            $outputPath = preg_replace('/\.webp$/i', '.jpg', $webpPath);
            
            // Ensure output path is different from input
            if ($outputPath === $webpPath) {
                $outputPath = $webpPath . '.jpg';
            }

            // Convert to JPG with 90% quality
            $success = @imagejpeg($image, $outputPath, 90);
            imagedestroy($image);

            if (!$success) {
                Log::warning('imagejpeg failed', [
                    'output_path' => $outputPath,
                    'error' => error_get_last()['message'] ?? 'Unknown error',
                ]);
                return null;
            }

            if (!file_exists($outputPath)) {
                Log::warning('Converted JPG file was not created', ['path' => $outputPath]);
                return null;
            }

            // Verify converted file is readable and has content
            $outputSize = filesize($outputPath);
            if ($outputSize === false || $outputSize === 0) {
                Log::warning('Converted JPG file is empty or invalid', [
                    'path' => $outputPath,
                    'size' => $outputSize,
                ]);
                @unlink($outputPath);
                return null;
            }

            Log::info('WebP converted to JPG successfully', [
                'original' => basename($webpPath),
                'converted' => basename($outputPath),
                'original_size' => filesize($webpPath),
                'converted_size' => $outputSize,
            ]);

            return $outputPath;
        } catch (\Exception $e) {
            Log::error('Exception converting webp to jpg', [
                'path' => $webpPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Send document (image as document - no resize/crop)
     *
     * @param int|string $chatId
     * @param string $filePath
     * @param string|null $caption
     * @param int|null $replyToMessageId
     * @return bool
     */
    public function sendDocument(int|string $chatId, string $filePath, ?string $caption = null, ?int $replyToMessageId = null): bool
    {
        try {
            // 1. File existence check
            if (!file_exists($filePath)) {
                Log::error('Document file does not exist', ['path' => $filePath]);
                return false;
            }

            // 2. Check file size (Telegram limit: 50MB for documents)
            $fileSize = filesize($filePath);
            $maxFileSize = 50 * 1024 * 1024; // 50MB
            
            if ($fileSize > $maxFileSize) {
                Log::warning('Document file too large for Telegram', [
                    'path' => $filePath,
                    'size' => $fileSize,
                    'size_mb' => round($fileSize / 1024 / 1024, 2),
                ]);
                return false;
            }

            // 3. Convert webp to jpg ONLY (keep original size, no resize/crop)
            $finalFilePath = $filePath;
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($extension === 'webp') {
                Log::debug('Converting WebP to JPG before sending as document', ['path' => basename($filePath)]);
                $convertedPath = $this->convertWebpToJpg($filePath);
                if ($convertedPath !== null && file_exists($convertedPath)) {
                    $finalFilePath = $convertedPath;
                    Log::info('Using converted JPG instead of WebP for document', [
                        'original' => basename($filePath),
                        'converted' => basename($convertedPath),
                    ]);
                } else {
                    Log::warning('Failed to convert webp to jpg, using original', [
                        'path' => $filePath,
                        'converted_path' => $convertedPath,
                    ]);
                    // Keep original path - Telegram might accept webp
                }
            }

            // 4. Use file handle for memory efficiency
            $fileHandle = fopen($finalFilePath, 'r');
            if ($fileHandle === false) {
                Log::error('Failed to open document file', ['path' => $finalFilePath]);
                return false;
            }

            try {
                $response = Http::timeout(60)->attach(
                    'document',
                    $fileHandle,
                    basename($finalFilePath)
                )->post("{$this->apiUrl}{$this->botToken}/sendDocument", [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $replyToMessageId,
                ]);

                if (!$response->successful()) {
                    $responseBody = $response->body();
                    $responseData = $response->json();
                    
                    Log::error('Telegram API error', [
                        'method' => 'sendDocument',
                        'chat_id' => $chatId,
                        'status' => $response->status(),
                        'body' => $responseBody,
                        'response_data' => $responseData,
                    ]);
                    
                    return false;
                }

                Log::info('Document sent successfully', [
                    'chat_id' => $chatId,
                    'document' => basename($finalFilePath),
                ]);
                
                return true;
            } finally {
                // Always close file handle
                if (isset($fileHandle) && is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
                
                // Clean up converted file if different from original
                if ($finalFilePath !== $filePath && file_exists($finalFilePath)) {
                    @unlink($finalFilePath);
                    Log::debug('Cleaned up converted document file', ['path' => basename($finalFilePath)]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram document', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            Log::info('sendVideo called', [
                'chat_id' => $chatId,
                'video_path' => $videoPath,
                'file_exists' => file_exists($videoPath),
            ]);
            
            if (!file_exists($videoPath)) {
                Log::error('Video file does not exist', ['path' => $videoPath]);
                return false;
            }

            $fileSize = filesize($videoPath);
            $maxFileSize = 50 * 1024 * 1024; // 50MB Telegram limit

            Log::debug('Video file info', [
                'chat_id' => $chatId,
                'video_path' => $videoPath,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'max_size' => $maxFileSize,
            ]);

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

            Log::info('Sending video to Telegram', [
                'chat_id' => $chatId,
                'video_path' => $videoPath,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            ]);

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
            
            Log::info('Telegram API response received', [
                'chat_id' => $chatId,
                'video_path' => basename($videoPath),
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if (!$response->successful()) {
                $responseBody = $response->body();
                $responseData = $response->json();
                
                Log::error('Telegram API error sending video', [
                    'method' => 'sendVideo',
                    'chat_id' => $chatId,
                    'video_path' => basename($videoPath),
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

            Log::info('Video sent successfully', [
                'chat_id' => $chatId,
                'video_path' => basename($videoPath),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram video', [
                'chat_id' => $chatId,
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
        $fileHandles = [];
        $convertedFiles = [];
        
        try {
            if (empty($photoPaths)) {
                Log::warning('sendMediaGroup called with empty photo paths', ['chat_id' => $chatId]);
                return false;
            }

            // Telegram allows max 10 media in a group
            $photoPaths = array_slice($photoPaths, 0, 10);
            
            Log::info('Preparing media group', [
                'chat_id' => $chatId,
                'photos_count' => count($photoPaths),
                'photo_paths' => array_map('basename', $photoPaths),
            ]);

            // Process files: validate, convert webp, prepare paths and media array
            $processedFiles = [];
            $media = [];
            
            foreach ($photoPaths as $originalPath) {
                if (!file_exists($originalPath)) {
                    Log::warning('Photo file does not exist in media group', [
                        'chat_id' => $chatId,
                        'path' => $originalPath,
                    ]);
                    continue;
                }

                // Verify it's actually an image
                $imageInfo = @getimagesize($originalPath);
                if ($imageInfo === false) {
                    Log::warning('File is not a valid image in media group', [
                        'chat_id' => $chatId,
                        'path' => $originalPath,
                    ]);
                    continue;
                }

                // Check file size (Telegram limit: 10MB for photos)
                $fileSize = filesize($originalPath);
                $maxFileSize = 10 * 1024 * 1024; // 10MB
                
                if ($fileSize > $maxFileSize) {
                    Log::warning('Photo file too large for Telegram in media group', [
                        'chat_id' => $chatId,
                        'path' => $originalPath,
                        'size_mb' => round($fileSize / 1024 / 1024, 2),
                    ]);
                    continue;
                }

                // Convert webp to jpg if needed
                $finalPath = $originalPath;
                $extension = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
                if ($extension === 'webp') {
                    $converted = $this->convertWebpToJpg($originalPath);
                    if ($converted !== null && file_exists($converted)) {
                        $finalPath = $converted;
                        $convertedFiles[] = $converted;
                        Log::debug('WebP converted to JPG in media group', [
                            'original' => basename($originalPath),
                            'converted' => basename($converted),
                        ]);
                    } else {
                        Log::warning('Failed to convert webp in media group, using original', [
                            'path' => $originalPath,
                        ]);
                    }
                }

                // Store processed file with its index for multipart
                $mediaIndex = count($processedFiles);
                $processedFiles[] = [
                    'path' => $finalPath,
                    'index' => $mediaIndex,
                ];

                $media[] = [
                    'type' => 'photo',
                    'media' => 'attach://photo_' . $mediaIndex,
                ];
            }

            if (empty($media) || empty($processedFiles)) {
                Log::error('No valid photos found for media group', [
                    'chat_id' => $chatId,
                    'original_count' => count($photoPaths),
                ]);
                return false;
            }

            Log::info('Media group prepared', [
                'chat_id' => $chatId,
                'media_count' => count($media),
                'processed_files' => count($processedFiles),
            ]);

            // Add caption to first media item
            if ($caption) {
                $media[0]['caption'] = $caption;
                $media[0]['parse_mode'] = 'HTML';
            }

            // Prepare multipart data for Laravel HTTP client
            $multipart = [
                ['name' => 'chat_id', 'contents' => (string) $chatId],
                ['name' => 'media', 'contents' => json_encode($media)],
            ];

            // Add each photo file with correct index
            foreach ($processedFiles as $processed) {
                $finalPath = $processed['path'];
                $mediaIndex = $processed['index'];
                
                if (!file_exists($finalPath)) {
                    Log::warning('Processed file does not exist', [
                        'chat_id' => $chatId,
                        'path' => $finalPath,
                        'index' => $mediaIndex,
                    ]);
                    continue;
                }

                $fileHandle = fopen($finalPath, 'r');
                if ($fileHandle === false) {
                    Log::error('Failed to open file for media group', [
                        'chat_id' => $chatId,
                        'path' => $finalPath,
                        'index' => $mediaIndex,
                    ]);
                    continue;
                }

                $fileHandles[] = $fileHandle;

                $multipart[] = [
                    'name' => 'photo_' . $mediaIndex,
                    'contents' => $fileHandle,
                    'filename' => basename($finalPath),
                ];
            }

            if (count($fileHandles) !== count($processedFiles)) {
                Log::error('File handle count mismatch in media group', [
                    'chat_id' => $chatId,
                    'handles' => count($fileHandles),
                    'processed' => count($processedFiles),
                ]);
                // Close opened handles
                foreach ($fileHandles as $handle) {
                    @fclose($handle);
                }
                return false;
            }

            Log::info('Sending media group to Telegram', [
                'chat_id' => $chatId,
                'media_count' => count($media),
            ]);

            $response = Http::timeout(60)->asMultipart()->post(
                "{$this->apiUrl}{$this->botToken}/sendMediaGroup",
                $multipart
            );

            if (!$response->successful()) {
                $responseBody = $response->body();
                $responseData = $response->json();
                
                Log::error('Telegram API error sending media group', [
                    'method' => 'sendMediaGroup',
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $responseBody,
                    'response_data' => $responseData,
                ]);
                
                return false;
            }

            Log::info('Media group sent successfully', [
                'chat_id' => $chatId,
                'media_count' => count($media),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram media group', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        } finally {
            // Always close file handles
            foreach ($fileHandles as $handle) {
                if (is_resource($handle)) {
                    @fclose($handle);
                }
            }

            // Clean up converted files
            foreach ($convertedFiles as $convertedFile) {
                if (file_exists($convertedFile)) {
                    @unlink($convertedFile);
                    Log::debug('Cleaned up converted file', ['path' => basename($convertedFile)]);
                }
            }
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
            Log::debug('Sending message with keyboard', [
                'chat_id' => $chatId,
                'text_length' => strlen($text),
                'keyboard_buttons' => count($keyboard),
            ]);
            
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
            $messageId = $data['result']['message_id'] ?? null;
            
            Log::info('Message with keyboard sent successfully', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            
            return $messageId;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message with keyboard', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            'uz' => "🔒 <b>Kanalga a'zo bo'lish majburiy!</b>{$missingChannelsText}\n\n📋 <b>Qanday ishlatiladi:</b>\n1. Kanallarga a'zo bo'ling\n2. <b>✅ Tekshirish</b> tugmasini bosing\n3. Instagram yoki TikTok linkini yuboring",
            'ru' => "🔒 <b>Подписка на канал обязательна!</b>{$missingChannelsText}\n\n📋 <b>Как использовать:</b>\n1. Подпишитесь на каналы\n2. Нажмите <b>✅ Проверить</b>\n3. Отправьте ссылку Instagram или TikTok",
            'en' => "🔒 <b>Channel subscription required!</b>{$missingChannelsText}\n\n📋 <b>How to use:</b>\n1. Subscribe to channels\n2. Press <b>✅ Check</b>\n3. Send Instagram or TikTok link",
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
