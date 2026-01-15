<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * yt-dlp Service for downloading media from Instagram and TikTok
 */
class YtDlpService
{
    private string $ytDlpPath;
    private int $timeout;
    private string $tempStoragePath;

    public function __construct()
    {
        $this->ytDlpPath = config('telegram.yt_dlp_path', 'yt-dlp');
        $this->timeout = config('telegram.download_timeout', 60);
        $this->tempStoragePath = config('telegram.temp_storage_path');
        
        // Ensure temp directory exists
        if (!is_dir($this->tempStoragePath)) {
            mkdir($this->tempStoragePath, 0755, true);
        }
    }

    /**
     * Download media from URL
     *
     * @param string $url
     * @param string $outputDir
     * @return array Array of downloaded file paths
     */
    public function download(string $url, string $outputDir): array
    {
        $downloadedFiles = [];

        try {
            // Ensure output directory exists
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Build yt-dlp command arguments
            // Note: URL is already validated and sanitized by UrlValidator
            // Process class handles argument escaping automatically
            $arguments = [
                $this->ytDlpPath,
                '--no-playlist',
                '--no-warnings',
                '--quiet',
                '--no-progress',
                '--extract-flat', 'false',
                '--write-info-json', 'false',
                '--write-description', 'false',
                '--write-thumbnail', 'false',
                '--write-subs', 'false',
                '--write-auto-subs', 'false',
                '--embed-subs', 'false',
                '--embed-thumbnail', 'false',
                '--embed-metadata', 'false',
                '--ignore-errors',
                '--output', $outputDir . '/%(title)s.%(ext)s',
                '--format', 'best[ext=mp4]/best[ext=webm]/best/bestvideo+bestaudio/best',
                $url, // URL is already validated and sanitized
            ];

            // Create process
            $process = new Process($arguments);
            $process->setTimeout($this->timeout);
            $process->setIdleTimeout($this->timeout);

            Log::info('Starting yt-dlp download', [
                'url' => $url,
                'output_dir' => $outputDir,
            ]);

            // Execute process
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                Log::error('yt-dlp download failed', [
                    'url' => $url,
                    'exit_code' => $process->getExitCode(),
                    'error' => $errorOutput,
                ]);
                throw new \RuntimeException('Download failed: ' . $errorOutput);
            }

            // Find downloaded files
            $downloadedFiles = $this->findDownloadedFiles($outputDir);

            if (empty($downloadedFiles)) {
                Log::warning('No files downloaded', [
                    'url' => $url,
                    'output_dir' => $outputDir,
                ]);
                throw new \RuntimeException('No files were downloaded');
            }

            Log::info('Download completed', [
                'url' => $url,
                'files_count' => count($downloadedFiles),
            ]);

            return $downloadedFiles;
        } catch (ProcessTimedOutException $e) {
            Log::error('yt-dlp download timeout', [
                'url' => $url,
                'timeout' => $this->timeout,
            ]);
            throw new \RuntimeException('Download timeout after ' . $this->timeout . ' seconds');
        } catch (\Exception $e) {
            Log::error('yt-dlp download exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download images from carousel post
     *
     * @param string $url
     * @param string $outputDir
     * @return array Array of downloaded image file paths
     */
    public function downloadImages(string $url, string $outputDir): array
    {
        $downloadedFiles = [];

        try {
            // Ensure output directory exists
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Build yt-dlp command arguments for images
            // Note: URL is already validated and sanitized by UrlValidator
            $arguments = [
                $this->ytDlpPath,
                '--no-playlist',
                '--no-warnings',
                '--quiet',
                '--no-progress',
                '--extract-flat', 'false',
                '--write-info-json', 'false',
                '--write-description', 'false',
                '--write-thumbnail', 'false',
                '--write-subs', 'false',
                '--write-auto-subs', 'false',
                '--embed-subs', 'false',
                '--embed-thumbnail', 'false',
                '--embed-metadata', 'false',
                '--ignore-errors',
                '--output', $outputDir . '/%(title)s.%(ext)s',
                '--format', 'best',
                '--skip-download', 'false',
                $url, // URL is already validated and sanitized
            ];

            // Create process
            $process = new Process($arguments);
            $process->setTimeout($this->timeout);
            $process->setIdleTimeout($this->timeout);

            Log::info('Starting yt-dlp image download', [
                'url' => $url,
                'output_dir' => $outputDir,
            ]);

            // Execute process
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                Log::error('yt-dlp image download failed', [
                    'url' => $url,
                    'exit_code' => $process->getExitCode(),
                    'error' => $errorOutput,
                ]);
                throw new \RuntimeException('Image download failed: ' . $errorOutput);
            }

            // Find downloaded files
            $downloadedFiles = $this->findDownloadedFiles($outputDir, ['jpg', 'jpeg', 'png', 'webp']);

            if (empty($downloadedFiles)) {
                Log::warning('No image files downloaded', [
                    'url' => $url,
                    'output_dir' => $outputDir,
                ]);
                throw new \RuntimeException('No image files were downloaded');
            }

            Log::info('Image download completed', [
                'url' => $url,
                'files_count' => count($downloadedFiles),
            ]);

            return $downloadedFiles;
        } catch (ProcessTimedOutException $e) {
            Log::error('yt-dlp image download timeout', [
                'url' => $url,
                'timeout' => $this->timeout,
            ]);
            throw new \RuntimeException('Image download timeout after ' . $this->timeout . ' seconds');
        } catch (\Exception $e) {
            Log::error('yt-dlp image download exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get media info to determine if it's video or image
     *
     * @param string $url
     * @return array Media information
     */
    public function getMediaInfo(string $url): array
    {
        try {
            $arguments = [
                $this->ytDlpPath,
                '--dump-json',
                '--no-playlist',
                '--no-warnings',
                '--quiet',
                $url, // URL is already validated and sanitized
            ];

            $process = new Process($arguments);
            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Failed to get media info');
            }

            $output = $process->getOutput();
            $info = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to parse media info');
            }

            return $info;
        } catch (\Exception $e) {
            Log::error('Failed to get media info', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find downloaded files in directory
     *
     * @param string $directory
     * @param array|null $allowedExtensions
     * @return array
     */
    private function findDownloadedFiles(string $directory, ?array $allowedExtensions = null): array
    {
        $files = [];

        if (!is_dir($directory)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                
                // Filter by extension if specified
                if ($allowedExtensions !== null && !in_array($extension, $allowedExtensions)) {
                    continue;
                }

                // Skip info files and thumbnails
                if (in_array($extension, ['json', 'description', 'info'])) {
                    continue;
                }

                $files[] = $file->getPathname();
            }
        }

        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    /**
     * Check if file is a video
     *
     * @param string $filePath
     * @return bool
     */
    public function isVideo(string $filePath): bool
    {
        $videoExtensions = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'm4v'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $videoExtensions);
    }

    /**
     * Check if file is an image
     *
     * @param string $filePath
     * @return bool
     */
    public function isImage(string $filePath): bool
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $imageExtensions);
    }
}
