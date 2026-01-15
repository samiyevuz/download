<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to clean up orphaned temporary files and directories
 * Should run periodically (e.g., every hour via scheduler)
 */
class CleanupOrphanedFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes for cleanup
    public int $tries = 1; // Don't retry cleanup jobs

    /**
     * Maximum age of temp directories in hours
     */
    private int $maxAgeHours = 2;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tempBasePath = config('telegram.temp_storage_path');
        
        if (!is_dir($tempBasePath)) {
            return;
        }

        Log::info('Starting orphaned files cleanup', [
            'base_path' => $tempBasePath,
        ]);

        $cleanedCount = 0;
        $totalSize = 0;
        $maxAge = time() - ($this->maxAgeHours * 3600);

        try {
            $iterator = new \DirectoryIterator($tempBasePath);

            foreach ($iterator as $item) {
                if ($item->isDot() || !$item->isDir()) {
                    continue;
                }

                $dirPath = $item->getPathname();
                $dirMtime = $item->getMTime();

                // Check if directory is older than max age
                if ($dirMtime < $maxAge) {
                    $size = $this->getDirectorySize($dirPath);
                    $totalSize += $size;

                    if ($this->removeDirectory($dirPath)) {
                        $cleanedCount++;
                        Log::info('Cleaned up orphaned directory', [
                            'directory' => $dirPath,
                            'age_hours' => round((time() - $dirMtime) / 3600, 2),
                            'size_mb' => round($size / 1024 / 1024, 2),
                        ]);
                    }
                }
            }

            Log::info('Orphaned files cleanup completed', [
                'directories_cleaned' => $cleanedCount,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Orphaned files cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Get directory size in bytes
     *
     * @param string $directory
     * @return int
     */
    private function getDirectorySize(string $directory): int
    {
        $size = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to calculate directory size', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
        }

        return $size;
    }

    /**
     * Remove directory and all contents
     *
     * @param string $directory
     * @return bool
     */
    private function removeDirectory(string $directory): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }

            return @rmdir($directory);
        } catch (\Exception $e) {
            // Fallback to system command
            @exec("rm -rf " . escapeshellarg($directory) . " 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }
    }
}
