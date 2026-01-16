<?php

namespace App\Jobs;

use App\Services\TelegramService;
use App\Services\YtDlpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job to download media from Instagram/TikTok and send to user
 */
class DownloadMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 2;
    public int $maxExceptions = 1;
    
    /**
     * Maximum memory usage in MB
     * Job will fail if memory exceeds this limit
     */
    public int $memory = 512; // 512MB per job

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int|string $chatId,
        public string $url,
        public ?int $messageId = null,
        public ?string $language = null,
        public ?int $downloadingMessageId = null,
        public ?int $userId = null // User ID for fallback to private chat
    ) {
        // If language not provided, try to get from cache
        if ($this->language === null) {
            $this->language = \Illuminate\Support\Facades\Cache::get("user_lang_{$this->chatId}", 'en');
        }
    }

    /**
     * Execute the job.
     */
    public function handle(TelegramService $telegramService, YtDlpService $ytDlpService): void
    {
        $tempDir = null;
        $downloadedFiles = [];
        $startMemory = memory_get_usage(true);

        try {
            // Set memory limit for this job
            ini_set('memory_limit', $this->memory . 'M');
            
            // Register shutdown function to ensure cleanup even on fatal errors
            register_shutdown_function(function () use (&$tempDir) {
                if ($tempDir && is_dir($tempDir)) {
                    $this->cleanup($tempDir);
                }
            });
            
            // Add delay for Instagram to avoid rate limiting
            // Only delay on retry attempts (not first attempt)
            $isInstagram = str_contains($this->url, 'instagram.com');
            if ($isInstagram && $this->attempts() > 1) {
                $delaySeconds = min(5 * $this->attempts(), 15); // 5s, 10s, 15s max
                Log::info('Adding delay for Instagram retry to avoid rate limiting', [
                    'chat_id' => $this->chatId,
                    'url' => $this->url,
                    'attempt' => $this->attempts(),
                    'delay_seconds' => $delaySeconds,
                ]);
                sleep($delaySeconds);
            }
            
            // Use downloading message ID passed from webhook (already sent immediately)
            // If not provided, send it here as fallback
            if ($this->downloadingMessageId === null) {
                $downloadingMessages = [
                    'uz' => "⏳ <b>Yuklanmoqda...</b>\n\nIltimos, kuting. Media fayli tayyorlanmoqda.",
                    'ru' => "⏳ <b>Загрузка...</b>\n\nПожалуйста, подождите. Медиа файл готовится.",
                    'en' => "⏳ <b>Downloading...</b>\n\nPlease wait. Media file is being prepared.",
                ];
                
                $downloadingMessage = $downloadingMessages[$this->language] ?? $downloadingMessages['en'];
                
                $this->downloadingMessageId = $telegramService->sendMessage(
                    $this->chatId,
                    $downloadingMessage,
                    $this->messageId
                );
            }

            // Create unique temporary directory
            $tempDir = config('telegram.temp_storage_path') . '/' . Str::uuid();
            
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            Log::info('Starting media download job', [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'temp_dir' => $tempDir,
                'attempt' => $this->attempts(),
                'language' => $this->language,
            ]);

            // Download media
            Log::info('Calling ytDlpService->download', [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'temp_dir' => $tempDir,
            ]);
            
            $downloadedFiles = $ytDlpService->download($this->url, $tempDir);
            
            Log::info('ytDlpService->download completed', [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'downloaded_files_count' => count($downloadedFiles),
                'downloaded_files' => array_map('basename', $downloadedFiles),
            ]);

            if (empty($downloadedFiles)) {
                throw new \RuntimeException('No files were downloaded');
            }

            // Separate videos and images
            $videos = [];
            $images = [];

            foreach ($downloadedFiles as $file) {
                if ($ytDlpService->isVideo($file)) {
                    $videos[] = $file;
                } elseif ($ytDlpService->isImage($file)) {
                    $images[] = $file;
                }
            }
            
            // Log what was downloaded
            Log::info('Downloaded files separated', [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'total_files' => count($downloadedFiles),
                'videos_count' => count($videos),
                'images_count' => count($images),
                'video_files' => array_map('basename', $videos),
                'image_files' => array_map('basename', $images),
            ]);
            
            // If both videos and images are downloaded, prefer the primary type
            // (This should not happen if media type detection works correctly)
            if (!empty($videos) && !empty($images)) {
                Log::warning('Both videos and images downloaded, this should not happen', [
                    'chat_id' => $this->chatId,
                    'url' => $this->url,
                    'videos_count' => count($videos),
                    'images_count' => count($images),
                ]);
                
                // Determine primary type based on file count or size
                // If more videos, use videos; if more images, use images
                if (count($videos) >= count($images)) {
                    Log::info('Preferring videos over images', [
                        'chat_id' => $this->chatId,
                        'url' => $this->url,
                    ]);
                    $images = []; // Remove images, keep only videos
                } else {
                    Log::info('Preferring images over videos', [
                        'chat_id' => $this->chatId,
                        'url' => $this->url,
                    ]);
                    $videos = []; // Remove videos, keep only images
                }
            }

            // Caption for all media
            $caption = "📥 Downloaded successfully";

            // Send videos
            foreach ($videos as $videoPath) {
                $fileSize = filesize($videoPath);
                $maxFileSize = 50 * 1024 * 1024; // 50MB Telegram limit
                
                if ($fileSize > $maxFileSize) {
                    // Video is too large for Telegram
                    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                    
                    $largeVideoMessages = [
                        'uz' => "❌ <b>Video juda katta!</b>\n\n📹 Video hajmi: <b>{$fileSizeMB} MB</b>\n\n⚠️ Telegram maksimal <b>50 MB</b> videolarni qabul qiladi.\n\n💡 Kichikroq video yoki boshqa formatni yuklab olishga harakat qiling.",
                        'ru' => "❌ <b>Видео слишком большое!</b>\n\n📹 Размер видео: <b>{$fileSizeMB} MB</b>\n\n⚠️ Telegram принимает видео максимум <b>50 MB</b>.\n\n💡 Попробуйте скачать видео меньшего размера или в другом формате.",
                        'en' => "❌ <b>Video is too large!</b>\n\n📹 Video size: <b>{$fileSizeMB} MB</b>\n\n⚠️ Telegram accepts videos up to <b>50 MB</b> maximum.\n\n💡 Try downloading a smaller video or in a different format.",
                    ];
                    
                    $errorMessage = $largeVideoMessages[$this->language] ?? $largeVideoMessages['en'];
                    
                    $telegramService->sendMessage($this->chatId, $errorMessage);
                    
                    Log::warning('Video too large for Telegram', [
                        'chat_id' => $this->chatId,
                        'url' => $this->url,
                        'file_size' => $fileSize,
                        'file_size_mb' => $fileSizeMB,
                        'max_size' => $maxFileSize,
                    ]);
                    
                    continue;
                }
                
                $success = $telegramService->sendVideo(
                    $this->chatId,
                    $videoPath,
                    $caption,
                    $this->messageId
                );
                
                if (!$success) {
                    Log::warning('Failed to send video to group', [
                        'chat_id' => $this->chatId,
                        'user_id' => $this->userId,
                        'video_path' => $videoPath,
                        'file_exists' => file_exists($videoPath),
                        'file_size' => file_exists($videoPath) ? filesize($videoPath) : null,
                    ]);
                    
                    // Fallback: Try to send to user's private chat if bot is not admin in group
                    if ($this->userId && $this->userId != $this->chatId) {
                        Log::info('Attempting to send video to user private chat as fallback', [
                            'user_id' => $this->userId,
                            'group_chat_id' => $this->chatId,
                        ]);
                        
                        $fallbackSuccess = $telegramService->sendVideo(
                            $this->userId,
                            $videoPath,
                            $caption,
                            null
                        );
                        
                        if ($fallbackSuccess) {
                            Log::info('Successfully sent video to user private chat', [
                                'user_id' => $this->userId,
                            ]);
                            
                            // Notify user in group that media was sent to private chat
                            $notifyMessages = [
                                'uz' => "✅ <b>Video shaxsiy xabarga yuborildi</b>\n\n📱 Bot guruhda admin bo'lmaganligi sababli, video shaxsiy xabarga yuborildi.",
                                'ru' => "✅ <b>Видео отправлено в личные сообщения</b>\n\n📱 Поскольку бот не является администратором группы, видео было отправлено в личные сообщения.",
                                'en' => "✅ <b>Video sent to private chat</b>\n\n📱 Since bot is not admin in group, video was sent to private chat.",
                            ];
                            
                            $notifyMessage = $notifyMessages[$this->language] ?? $notifyMessages['en'];
                            $telegramService->sendMessage($this->chatId, $notifyMessage, $this->messageId);
                            continue; // Success, move to next video
                        }
                    }
                    
                    // If fallback also failed, show error message
                    $errorMessages = [
                        'uz' => "❌ <b>Video yuborib bo'lmadi</b>\n\n⚠️ Bot guruhda admin bo'lishi yoki 'Send Messages' permission bo'lishi kerak.\n\n💡 Guruh adminiga murojaat qiling yoki botga shaxsiy xabar yuboring.",
                        'ru' => "❌ <b>Не удалось отправить видео</b>\n\n⚠️ Бот должен быть администратором группы или иметь разрешение 'Send Messages'.\n\n💡 Обратитесь к администратору группы или отправьте боту личное сообщение.",
                        'en' => "❌ <b>Failed to send video</b>\n\n⚠️ Bot must be admin or have 'Send Messages' permission in the group.\n\n💡 Please contact group admin or send bot a private message.",
                    ];
                    
                    $errorMessage = $errorMessages[$this->language] ?? $errorMessages['en'];
                    $telegramService->sendMessage($this->chatId, $errorMessage, $this->messageId);
                }
            }

            // Send images
            if (!empty($images)) {
                Log::info('Sending images to user', [
                    'chat_id' => $this->chatId,
                    'images_count' => count($images),
                    'image_paths' => array_map('basename', $images),
                ]);
                
                // If multiple images, send as media group (max 10)
                if (count($images) > 1) {
                    $success = $telegramService->sendMediaGroup(
                        $this->chatId,
                        array_slice($images, 0, 10),
                        $caption
                    );
                    
                    if (!$success) {
                        Log::warning('Failed to send media group, trying individual photos', [
                            'chat_id' => $this->chatId,
                            'images_count' => count($images),
                        ]);
                        
                        // Fallback: send images individually
                        $individualSuccess = false;
                        foreach (array_slice($images, 0, 10) as $index => $imagePath) {
                            $photoCaption = ($index === 0) ? $caption : null;
                            if ($telegramService->sendPhoto(
                                $this->chatId,
                                $imagePath,
                                $photoCaption,
                                $this->messageId
                            )) {
                                $individualSuccess = true;
                            }
                        }
                        
                        // If all individual sends failed, it's likely a permission issue
                        if (!$individualSuccess) {
                            $errorMessages = [
                                'uz' => "❌ <b>Rasmlar yuborib bo'lmadi</b>\n\n⚠️ Bot guruhda admin bo'lishi yoki 'Send Messages' permission bo'lishi kerak.\n\n💡 Guruh adminiga murojaat qiling.",
                                'ru' => "❌ <b>Не удалось отправить изображения</b>\n\n⚠️ Бот должен быть администратором группы или иметь разрешение 'Send Messages'.\n\n💡 Обратитесь к администратору группы.",
                                'en' => "❌ <b>Failed to send photos</b>\n\n⚠️ Bot must be admin or have 'Send Messages' permission in the group.\n\n💡 Please contact group admin.",
                            ];
                            
                            $errorMessage = $errorMessages[$this->language] ?? $errorMessages['en'];
                            $telegramService->sendMessage($this->chatId, $errorMessage, $this->messageId);
                        }
                    }
                    
                    // If more than 10 images, send remaining individually
                    if (count($images) > 10) {
                        foreach (array_slice($images, 10) as $imagePath) {
                            $telegramService->sendPhoto(
                                $this->chatId,
                                $imagePath,
                                null,
                                $this->messageId
                            );
                        }
                    }
                } else {
                    // Single image
                    $success = $telegramService->sendPhoto(
                        $this->chatId,
                        $images[0],
                        $caption,
                        $this->messageId
                    );
                    
                    if (!$success) {
                        Log::error('Failed to send single photo', [
                            'chat_id' => $this->chatId,
                            'image_path' => $images[0],
                            'file_exists' => file_exists($images[0]),
                            'file_size' => file_exists($images[0]) ? filesize($images[0]) : null,
                        ]);
                        
                        // Check if it's a permission issue in group
                        $errorMessages = [
                            'uz' => "❌ <b>Rasm yuborib bo'lmadi</b>\n\n⚠️ Bot guruhda admin bo'lishi yoki 'Send Messages' permission bo'lishi kerak.\n\n💡 Guruh adminiga murojaat qiling.",
                            'ru' => "❌ <b>Не удалось отправить изображение</b>\n\n⚠️ Бот должен быть администратором группы или иметь разрешение 'Send Messages'.\n\n💡 Обратитесь к администратору группы.",
                            'en' => "❌ <b>Failed to send photo</b>\n\n⚠️ Bot must be admin or have 'Send Messages' permission in the group.\n\n💡 Please contact group admin.",
                        ];
                        
                        $errorMessage = $errorMessages[$this->language] ?? $errorMessages['en'];
                        $telegramService->sendMessage($this->chatId, $errorMessage, $this->messageId);
                    }
                }
            } else {
                Log::warning('No images found after download', [
                    'chat_id' => $this->chatId,
                    'url' => $this->url,
                    'downloaded_files' => $downloadedFiles,
                    'videos_count' => count($videos),
                ]);
            }

            // If only videos were found, that's fine
            // If only images were found, that's fine
            // If neither, log warning
            if (empty($videos) && empty($images)) {
                Log::warning('Downloaded files but no videos or images found', [
                    'chat_id' => $this->chatId,
                    'url' => $this->url,
                    'files' => $downloadedFiles,
                ]);
            }

            // Delete "Downloading..." message after successfully sending media
            if ($this->downloadingMessageId !== null) {
                try {
                    $telegramService->deleteMessage($this->chatId, $this->downloadingMessageId);
                } catch (\Exception $e) {
                    // Log but don't fail the job if message deletion fails
                    Log::warning('Failed to delete downloading message', [
                        'chat_id' => $this->chatId,
                        'message_id' => $this->downloadingMessageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $endMemory = memory_get_usage(true);
            $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // MB

            Log::info('Media download job completed successfully', [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'videos_count' => count($videos),
                'images_count' => count($images),
                'memory_used_mb' => round($memoryUsed, 2),
            ]);
            
            // Check memory usage
            if ($memoryUsed > ($this->memory * 0.9)) {
                Log::warning('Job memory usage high', [
                    'chat_id' => $this->chatId,
                    'memory_used_mb' => round($memoryUsed, 2),
                    'memory_limit_mb' => $this->memory,
                ]);
            }

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $isInstagram = str_contains($this->url, 'instagram.com');
            
            // Enhanced logging for Instagram errors
            $logContext = [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'error' => $errorMessage,
                'error_class' => get_class($e),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'is_instagram' => $isInstagram,
            ];
            
            // Add specific Instagram error detection
            if ($isInstagram) {
                $errorLower = strtolower($errorMessage);
                if (str_contains($errorLower, 'rate limit') || str_contains($errorLower, 'too many requests')) {
                    $logContext['error_type'] = 'rate_limit';
                } elseif (str_contains($errorLower, 'login required') || str_contains($errorLower, 'private')) {
                    $logContext['error_type'] = 'authentication_required';
                } elseif (str_contains($errorLower, 'extractor')) {
                    $logContext['error_type'] = 'extractor_error';
                } elseif (str_contains($errorLower, 'no files') || str_contains($errorLower, 'not found')) {
                    $logContext['error_type'] = 'content_unavailable';
                } else {
                    $logContext['error_type'] = 'unknown';
                }
            }
            
            Log::error('Media download job failed', $logContext);

            // Determine if this error should be retried
            $shouldRetry = $this->shouldRetryException($e);

            // Send error message to user only if we're not retrying
            if (!$shouldRetry || $this->attempts() >= $this->tries) {
                try {
                    // Simple error message as per requirements
                    $errorMessage = "❌ Unable to download this content.";
                    
                    $telegramService->sendMessage(
                        $this->chatId,
                        $errorMessage,
                        $this->messageId
                    );
                    
                    // Delete "Downloading..." message if exists
                    if ($this->downloadingMessageId !== null) {
                        try {
                            $telegramService->deleteMessage($this->chatId, $this->downloadingMessageId);
                        } catch (\Exception $deleteError) {
                            // Ignore delete errors
                        }
                    }
                } catch (\Exception $sendError) {
                    Log::error('Failed to send error message', [
                        'chat_id' => $this->chatId,
                        'error' => $sendError->getMessage(),
                    ]);
                }
            }

            // Re-throw to mark job as failed or trigger retry
            throw $e;
        } finally {
            // Cleanup: Delete temporary files and directory
            $this->cleanup($tempDir);
        }
    }

    /**
     * Clean up temporary files and directory
     * Guaranteed to execute even on errors
     * Also cleans up converted JPG files from WebP conversion
     *
     * @param string|null $directory
     * @return void
     */
    private function cleanup(?string $directory): void
    {
        if (empty($directory) || !is_dir($directory)) {
            return;
        }

        $maxAttempts = 3;
        $attempt = 0;
        $cleaned = false;

        while ($attempt < $maxAttempts && !$cleaned) {
            $attempt++;
            
            try {
                // First, try to remove all files
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

                $errors = [];
                foreach ($iterator as $file) {
                    try {
                        if ($file->isDir()) {
                            @rmdir($file->getPathname());
                        } else {
                            @unlink($file->getPathname());
                        }
                    } catch (\Exception $e) {
                        $errors[] = $file->getPathname() . ': ' . $e->getMessage();
                    }
                }

                // Try to remove the directory itself
                if (@rmdir($directory)) {
                    $cleaned = true;
                    Log::info('Temporary files cleaned up', [
                        'directory' => $directory,
                        'attempt' => $attempt,
                    ]);
                } else {
                    // Directory might still have files, try again
                    if ($attempt < $maxAttempts) {
                        usleep(100000); // Wait 0.1 seconds
                    }
                }

                // Log any errors but don't fail
                if (!empty($errors) && $attempt === $maxAttempts) {
                    Log::warning('Some files could not be cleaned up', [
                        'directory' => $directory,
                        'errors' => $errors,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Cleanup attempt failed', [
                    'directory' => $directory,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                
                if ($attempt < $maxAttempts) {
                    usleep(200000); // Wait 0.2 seconds before retry
                }
            }
        }

        // Final fallback: try to remove directory even if not empty (system-dependent)
        if (!$cleaned && is_dir($directory)) {
            @exec("rm -rf " . escapeshellarg($directory) . " 2>&1", $output, $returnCode);
            if ($returnCode === 0) {
                Log::info('Directory removed using fallback method', [
                    'directory' => $directory,
                ]);
            } else {
                Log::error('Failed to cleanup directory after all attempts', [
                    'directory' => $directory,
                    'output' => $output,
                ]);
            }
        }
    }

    /**
     * Determine if the exception should trigger a retry
     * Only retry network/process-related errors, not validation errors
     *
     * @param \Exception $exception
     * @return bool
     */
    private function shouldRetryException(\Exception $exception): bool
    {
        // Don't retry if we've exceeded max attempts
        if ($this->attempts() >= $this->tries) {
            return false;
        }

        $exceptionMessage = strtolower($exception->getMessage());
        $exceptionClass = get_class($exception);

        // Retry network-related errors
        if (str_contains($exceptionMessage, 'timeout') ||
            str_contains($exceptionMessage, 'network') ||
            str_contains($exceptionMessage, 'connection') ||
            str_contains($exceptionMessage, 'dns') ||
            $exceptionClass === \Symfony\Component\Process\Exception\ProcessTimedOutException::class) {
            return true;
        }

        // Retry process-related errors that might be transient
        if (str_contains($exceptionMessage, 'process') ||
            str_contains($exceptionMessage, 'execution') ||
            str_contains($exceptionMessage, 'temporary')) {
            return true;
        }

        // Retry rate-limit errors (Instagram sometimes blocks temporarily)
        if (str_contains($exceptionMessage, 'rate-limit') ||
            str_contains($exceptionMessage, 'rate limit') ||
            str_contains($exceptionMessage, 'too many requests') ||
            (str_contains($exceptionMessage, 'login required') && str_contains($exceptionMessage, 'instagram'))) {
            return true;
        }
        
        // Retry Instagram extractor errors (API might be temporarily unavailable)
        if (str_contains($exceptionMessage, 'extractor') && 
            str_contains($exceptionMessage, 'instagram')) {
            return true;
        }
        
        // Retry "No video formats" errors - might be image post, try different format
        if (str_contains($exceptionMessage, 'no video formats') && 
            str_contains($exceptionMessage, 'instagram')) {
            return true; // Retry with image format
        }

        // Don't retry validation errors, invalid URLs, or content errors
        if (str_contains($exceptionMessage, 'invalid') ||
            str_contains($exceptionMessage, 'private') ||
            str_contains($exceptionMessage, 'unavailable') ||
            str_contains($exceptionMessage, 'not found') ||
            str_contains($exceptionMessage, 'no files')) {
            return false;
        }

        // Default: retry for unknown errors (might be transient)
        return true;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function backoff(): array
    {
        // Exponential backoff with longer delays for Instagram
        $isInstagram = str_contains($this->url, 'instagram.com');
        
        if ($isInstagram) {
            // Longer delays for Instagram to avoid rate limiting
            // 10 seconds, then 30 seconds
            return [10, 30];
        }
        
        // Standard backoff for other platforms: 5 seconds, then 15 seconds
        return [5, 15];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('DownloadMediaJob failed permanently', [
            'chat_id' => $this->chatId,
            'url' => $this->url,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Try to send error message to user
        try {
            // Get user's language preference
            $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$this->chatId}", 'en');
            
            $errorMessages = [
                'uz' => "❌ <b>Yuklab olish muvaffaqiyatsiz</b>\n\n⚠️ Kontent maxfiy bo'lishi yoki mavjud bo'lmasligi mumkin.\n\n🔗 Iltimos, boshqa link yuborib ko'ring.",
                'ru' => "❌ <b>Загрузка не удалась</b>\n\n⚠️ Контент может быть приватным или недоступным.\n\n🔗 Пожалуйста, попробуйте другую ссылку.",
                'en' => "❌ <b>Download failed</b>\n\n⚠️ The content may be private or unavailable.\n\n🔗 Please try another link.",
            ];
            
            $errorMessage = $errorMessages[$language] ?? $errorMessages['en'];
            
            $telegramService = app(TelegramService::class);
            $telegramService->sendMessage(
                $this->chatId,
                $errorMessage,
                $this->messageId
            );
        } catch (\Exception $e) {
            Log::error('Failed to send error message to user', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
