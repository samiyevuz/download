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
        public ?int $messageId = null
    ) {
        //
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
            // Send "Downloading..." message at the start
            $telegramService->sendMessage(
                $this->chatId,
                "⏳ Downloading, please wait...",
                $this->messageId
            );

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
            ]);

            // Download media
            $downloadedFiles = $ytDlpService->download($this->url, $tempDir);

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

            $caption = "📥 Downloaded successfully\n⚡ Fast & Stable Bot";

            // Send videos
            foreach ($videos as $videoPath) {
                $telegramService->sendVideo(
                    $this->chatId,
                    $videoPath,
                    $caption,
                    $this->messageId
                );
            }

            // Send images
            if (!empty($images)) {
                // If multiple images, send as media group (max 10)
                if (count($images) > 1) {
                    $telegramService->sendMediaGroup(
                        $this->chatId,
                        array_slice($images, 0, 10),
                        $caption
                    );
                    
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
                    $telegramService->sendPhoto(
                        $this->chatId,
                        $images[0],
                        $caption,
                        $this->messageId
                    );
                }
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
            Log::error('Media download job failed', [
                'chat_id' => $this->chatId,
                'url' => $this->url,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Determine if this error should be retried
            $shouldRetry = $this->shouldRetryException($e);

            // Send error message to user only if we're not retrying
            if (!$shouldRetry || $this->attempts() >= $this->tries) {
                try {
                    $telegramService->sendMessage(
                        $this->chatId,
                        "❌ Download failed. The content may be private or unavailable.",
                        $this->messageId
                    );
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
        // Exponential backoff: 5 seconds, then 15 seconds
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
            $telegramService = app(TelegramService::class);
            $telegramService->sendMessage(
                $this->chatId,
                "❌ Download failed. The content may be private or unavailable.",
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
