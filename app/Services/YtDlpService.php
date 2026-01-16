<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

if (!function_exists('command_exists')) {
    function command_exists($command) {
        $which = shell_exec("which $command 2>/dev/null");
        return !empty($which);
    }
}

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

            // Check if this is an Instagram URL and use enhanced method
            if ($this->isInstagramUrl($url)) {
                return $this->downloadInstagram($url, $outputDir);
            }

            // Build yt-dlp command arguments for TikTok or other platforms
            // Note: URL is already validated and sanitized by UrlValidator
            // Process class handles argument escaping automatically
            $arguments = [
                $this->ytDlpPath,
                '--no-playlist',
                '--no-warnings',
                '--quiet',
                '--no-progress',
                '--ignore-errors',
                '--output', $outputDir . '/%(title)s.%(ext)s',
                '--format', 'best',
                $url, // URL is already validated and sanitized
            ];

            // Verify and resolve yt-dlp path
            $ytDlpPath = $this->ytDlpPath;
            
            // Check if path is absolute and exists
            if (!file_exists($ytDlpPath) || !is_executable($ytDlpPath)) {
                // Try to find yt-dlp in PATH
                $whichYtDlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
                if ($whichYtDlp && file_exists($whichYtDlp) && is_executable($whichYtDlp)) {
                    $ytDlpPath = $whichYtDlp;
                    Log::info('yt-dlp found in PATH', ['path' => $ytDlpPath]);
                } else {
                    // Try common paths
                    $commonPaths = [
                        '/usr/local/bin/yt-dlp',
                        '/usr/bin/yt-dlp',
                        '/snap/bin/yt-dlp',
                        getenv('HOME') . '/bin/yt-dlp',
                        '/var/www/sardor/data/bin/yt-dlp',
                    ];
                    
                    foreach ($commonPaths as $commonPath) {
                        if (file_exists($commonPath) && is_executable($commonPath)) {
                            $ytDlpPath = $commonPath;
                            Log::info('yt-dlp found in common path', ['path' => $ytDlpPath]);
                            break;
                        }
                    }
                }
            }
            
            // Final check
            if (!file_exists($ytDlpPath) || !is_executable($ytDlpPath)) {
                Log::error('yt-dlp not found or not executable', [
                    'configured_path' => $this->ytDlpPath,
                    'resolved_path' => $ytDlpPath,
                ]);
                throw new \RuntimeException("yt-dlp not found or not executable at: {$ytDlpPath}");
            }

            // Update arguments with correct path
            $arguments[0] = $ytDlpPath;

            // Create process with proper isolation
            $process = new Process($arguments);
            $process->setTimeout($this->timeout);
            $process->setIdleTimeout($this->timeout);
            
            // Set working directory to output directory for isolation
            $process->setWorkingDirectory($outputDir);
            
            // Keep PATH environment variable (needed for yt-dlp dependencies)
            $env = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];
            $process->setEnv($env);

            Log::info('Starting yt-dlp download', [
                'url' => $url,
                'output_dir' => $outputDir,
                'timeout' => $this->timeout,
                'yt_dlp_path' => $ytDlpPath,
            ]);

            try {
                // Execute process
                $process->run(function ($type, $buffer) use ($url) {
                    // Log output in real-time for debugging
                    Log::debug('yt-dlp output', [
                        'url' => $url,
                        'type' => $type === Process::ERR ? 'stderr' : 'stdout',
                        'buffer' => substr($buffer, 0, 1000), // Limit log size
                    ]);
                });

                if (!$process->isSuccessful()) {
                    $errorOutput = $process->getErrorOutput();
                    $stdOutput = $process->getOutput();
                    Log::error('yt-dlp download failed', [
                        'url' => $url,
                        'exit_code' => $process->getExitCode(),
                        'error' => $errorOutput,
                        'output' => substr($stdOutput, 0, 500),
                        'command' => implode(' ', array_slice($arguments, 0, 3)) . '...',
                        'full_command' => implode(' ', $arguments),
                    ]);
                    throw new \RuntimeException('Download failed: ' . ($errorOutput ?: $stdOutput ?: 'Unknown error'));
                }
            } catch (ProcessTimedOutException $e) {
                // Force kill the process on timeout
                $this->forceKillProcess($process);
                Log::error('yt-dlp download timeout', [
                    'url' => $url,
                    'timeout' => $this->timeout,
                ]);
                throw new \RuntimeException('Download timeout after ' . $this->timeout . ' seconds');
            } catch (\Exception $e) {
                // Ensure process is terminated on any exception
                $this->forceKillProcess($process);
                Log::error('yt-dlp download exception', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500),
                ]);
                throw $e;
            } finally {
                // Always ensure process is stopped
                if ($process->isRunning()) {
                    $this->forceKillProcess($process);
                }
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
     * Force kill a process and all its children
     * Prevents zombie processes
     *
     * @param Process $process
     * @return void
     */
    private function forceKillProcess(Process $process): void
    {
        try {
            if ($process->isRunning()) {
                $pid = $process->getPid();
                
                if ($pid && $pid > 0) {
                    Log::warning('Force killing yt-dlp process', ['pid' => $pid]);
                    
                    // Kill process group to ensure all children are terminated
                    if (function_exists('posix_kill')) {
                        // Send SIGTERM first (graceful)
                        @posix_kill($pid, SIGTERM);
                        usleep(500000); // Wait 0.5 seconds
                        
                        // Force kill if still running
                        if ($process->isRunning()) {
                            @posix_kill($pid, SIGKILL);
                        }
                    } else {
                        // Fallback for Windows or systems without posix
                        try {
                            $process->stop(5, SIGKILL);
                        } catch (\Exception $e) {
                            // Ignore if process already stopped
                        }
                    }
                } else {
                    // Process not started or already finished
                    try {
                        $process->stop(5, SIGKILL);
                    } catch (\Exception $e) {
                        // Ignore if process already stopped
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to kill process', [
                'error' => $e->getMessage(),
            ]);
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
                '--ignore-errors',
                '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                '--referer', 'https://www.instagram.com/',
                '--add-header', 'Accept-Language:en-US,en;q=0.9',
                '--add-header', 'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                '--output', $outputDir . '/%(title)s.%(ext)s',
                '--format', 'best',
                $url, // URL is already validated and sanitized
            ];

            // Create process with proper isolation
            $process = new Process($arguments);
            $process->setTimeout($this->timeout);
            $process->setIdleTimeout($this->timeout);
            $process->setWorkingDirectory($outputDir);
            $process->setEnv([]);

            Log::info('Starting yt-dlp image download', [
                'url' => $url,
                'output_dir' => $outputDir,
                'timeout' => $this->timeout,
            ]);

            try {
                // Execute process
                $process->run();
            } catch (ProcessTimedOutException $e) {
                $this->forceKillProcess($process);
                throw $e;
            } catch (\Exception $e) {
                $this->forceKillProcess($process);
                throw $e;
            } finally {
                if ($process->isRunning()) {
                    $this->forceKillProcess($process);
                }
            }

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
            $process->setIdleTimeout(30);
            $process->setEnv([]);

            try {
                $process->run();
                
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Failed to get media info');
                }
            } catch (\Exception $e) {
                $this->forceKillProcess($process);
                throw $e;
            } finally {
                if ($process->isRunning()) {
                    $this->forceKillProcess($process);
                }
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
                $filename = $file->getFilename();
                
                // Skip info files and thumbnails
                if (in_array($extension, ['json', 'description', 'info', 'webp'])) {
                    // Skip .webp thumbnails but allow other webp images
                    if ($extension === 'webp' && (str_contains($filename, 'thumb') || str_contains($filename, 'thumbnail'))) {
                        continue;
                    }
                }
                
                // Filter by extension if specified
                if ($allowedExtensions !== null) {
                    // For images, also check if file is actually an image by checking MIME type
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
                        $mimeType = mime_content_type($file->getPathname());
                        if (!str_starts_with($mimeType, 'image/')) {
                            continue;
                        }
                    }
                    
                    if (!in_array($extension, $allowedExtensions)) {
                        continue;
                    }
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

    /**
     * Check if URL is Instagram
     *
     * @param string $url
     * @return bool
     */
    private function isInstagramUrl(string $url): bool
    {
        return str_contains($url, 'instagram.com');
    }

    /**
     * Download Instagram media with enhanced method (cookies + fallback)
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagram(string $url, string $outputDir): array
    {
        $cookiesPath = config('telegram.instagram_cookies_path');
        
        // Method 1: Try with cookies (most reliable)
        if ($cookiesPath && file_exists($cookiesPath)) {
            try {
                return $this->downloadWithCookies($url, $outputDir, $cookiesPath);
            } catch (\Exception $e) {
                Log::warning('Instagram download with cookies failed, trying fallback', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Method 2: Fallback to enhanced headers
        return $this->downloadWithEnhancedHeaders($url, $outputDir);
    }

    /**
     * Download with Instagram cookies (most reliable)
     *
     * @param string $url
     * @param string $outputDir
     * @param string $cookiesPath
     * @return array
     */
    private function downloadWithCookies(string $url, string $outputDir, string $cookiesPath): array
    {
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--cookies', $cookiesPath,
            '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best/bestvideo+bestaudio/best',
            '--write-thumbnail',
            '--skip-download', // Don't download thumbnail separately, just get it if available
            $url,
        ];

        // Remove --skip-download if we want to download media
        // Actually, we want to download, so remove that flag
        $arguments = array_filter($arguments, function($arg) {
            return $arg !== '--skip-download';
        });
        $arguments = array_values($arguments); // Re-index array

        return $this->executeDownload($arguments, $url, $outputDir);
    }

    /**
     * Download with enhanced headers (fallback)
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadWithEnhancedHeaders(string $url, string $outputDir): array
    {
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            '--referer', 'https://www.instagram.com/',
            '--add-header', 'Accept-Language:en-US,en;q=0.9',
            '--add-header', 'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            '--add-header', 'Accept-Encoding:gzip, deflate, br',
            '--add-header', 'DNT:1',
            '--add-header', 'Connection:keep-alive',
            '--add-header', 'Upgrade-Insecure-Requests:1',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best/bestvideo+bestaudio/best',
            $url,
        ];

        return $this->executeDownload($arguments, $url, $outputDir);
    }

    /**
     * Execute download process (extracted from original download method)
     *
     * @param array $arguments
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function executeDownload(array $arguments, string $url, string $outputDir): array
    {
        // Verify and resolve yt-dlp path
        $ytDlpPath = $this->ytDlpPath;
        
        if (!file_exists($ytDlpPath) || !is_executable($ytDlpPath)) {
            $whichYtDlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
            if ($whichYtDlp && file_exists($whichYtDlp) && is_executable($whichYtDlp)) {
                $ytDlpPath = $whichYtDlp;
            } else {
                $commonPaths = [
                    '/usr/local/bin/yt-dlp',
                    '/usr/bin/yt-dlp',
                    '/snap/bin/yt-dlp',
                    getenv('HOME') . '/bin/yt-dlp',
                    '/var/www/sardor/data/bin/yt-dlp',
                ];
                
                foreach ($commonPaths as $commonPath) {
                    if (file_exists($commonPath) && is_executable($commonPath)) {
                        $ytDlpPath = $commonPath;
                        break;
                    }
                }
            }
        }
        
        if (!file_exists($ytDlpPath) || !is_executable($ytDlpPath)) {
            throw new \RuntimeException("yt-dlp not found or not executable at: {$ytDlpPath}");
        }

        $arguments[0] = $ytDlpPath;

        $process = new Process($arguments);
        $process->setTimeout($this->timeout);
        $process->setIdleTimeout($this->timeout);
        $process->setWorkingDirectory($outputDir);
        $process->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

        Log::info('Starting yt-dlp download', [
            'url' => $url,
            'output_dir' => $outputDir,
            'timeout' => $this->timeout,
            'yt_dlp_path' => $ytDlpPath,
            'method' => isset($arguments[array_search('--cookies', $arguments) ?? []]) ? 'with-cookies' : 'enhanced-headers',
        ]);

        try {
            $process->run(function ($type, $buffer) use ($url) {
                Log::debug('yt-dlp output', [
                    'url' => $url,
                    'type' => $type === Process::ERR ? 'stderr' : 'stdout',
                    'buffer' => substr($buffer, 0, 1000),
                ]);
            });

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                $stdOutput = $process->getOutput();
                Log::error('yt-dlp download failed', [
                    'url' => $url,
                    'exit_code' => $process->getExitCode(),
                    'error' => $errorOutput,
                    'output' => substr($stdOutput, 0, 500),
                    'full_command' => implode(' ', $arguments),
                ]);
                throw new \RuntimeException('Download failed: ' . ($errorOutput ?: $stdOutput ?: 'Unknown error'));
            }
        } catch (ProcessTimedOutException $e) {
            $this->forceKillProcess($process);
            throw new \RuntimeException('Download timeout after ' . $this->timeout . ' seconds');
        } catch (\Exception $e) {
            $this->forceKillProcess($process);
            throw $e;
        } finally {
            if ($process->isRunning()) {
                $this->forceKillProcess($process);
            }
        }

        $downloadedFiles = $this->findDownloadedFiles($outputDir);

        if (empty($downloadedFiles)) {
            // Log directory contents for debugging
            $dirContents = [];
            if (is_dir($outputDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($outputDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $dirContents[] = [
                            'name' => $file->getFilename(),
                            'extension' => $file->getExtension(),
                            'size' => $file->getSize(),
                            'path' => $file->getPathname(),
                        ];
                    }
                }
            }
            
            Log::error('No files were downloaded', [
                'url' => $url,
                'output_dir' => $outputDir,
                'directory_contents' => $dirContents,
            ]);
            
            throw new \RuntimeException('No files were downloaded');
        }

        Log::info('Download completed', [
            'url' => $url,
            'files_count' => count($downloadedFiles),
            'files' => array_map('basename', $downloadedFiles),
        ]);

        return $downloadedFiles;
    }
}
