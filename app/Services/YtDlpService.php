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
                // First, try to get media info to determine if it's an image or video post
                $mediaInfo = null;
                $isImagePost = false;
                $isVideoPost = false;
                
                try {
                    $mediaInfo = $this->getMediaInfo($url);
                    $isImagePost = $this->isImagePost($mediaInfo);
                    $isVideoPost = $this->isVideoPost($mediaInfo);
                    
                    Log::info('Instagram media type detected', [
                        'url' => $url,
                        'is_image' => $isImagePost,
                        'is_video' => $isVideoPost,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to get media info, trying URL-based detection', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Fallback: Try to detect from URL pattern
                    // Instagram /p/ URLs are usually image posts, /reel/ are videos
                    if (str_contains($url, '/p/') || str_contains($url, '/post/')) {
                        $isImagePost = true;
                        Log::info('Detected as image post from URL pattern', ['url' => $url]);
                    } elseif (str_contains($url, '/reel/') || str_contains($url, '/tv/')) {
                        $isVideoPost = true;
                        Log::info('Detected as video post from URL pattern', ['url' => $url]);
                    }
                }
                
                // Use specific download method based on detection
                if ($isImagePost && !$isVideoPost) {
                    // For image-only posts, use image-specific format
                    Log::info('Using Instagram image download method', ['url' => $url]);
                    return $this->downloadInstagramImage($url, $outputDir);
                } elseif ($isVideoPost && !$isImagePost) {
                    // For video-only posts, use video-specific format
                    Log::info('Using Instagram video download method', ['url' => $url]);
                    return $this->downloadInstagramVideo($url, $outputDir);
                }
                
                // If unclear or both, try image first (most Instagram posts are images)
                // Then fallback to default method
                Log::info('Media type unclear, trying image download first', ['url' => $url]);
                try {
                    $result = $this->downloadInstagramImage($url, $outputDir);
                    if (!empty($result)) {
                        return $result;
                    }
                } catch (\Exception $e) {
                    Log::warning('Image download failed, trying default method', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                // Default Instagram download method
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
            $cookiesPath = config('telegram.instagram_cookies_path');
            $arguments = [
                $this->ytDlpPath,
                '--dump-json',
                '--no-playlist',
                '--no-warnings',
                '--quiet',
            ];
            
            // Add cookies if available
            if ($cookiesPath && file_exists($cookiesPath)) {
                $arguments[] = '--cookies';
                $arguments[] = $cookiesPath;
            }
            
            $arguments[] = $url; // URL is already validated and sanitized

            $process = new Process($arguments);
            $process->setTimeout(30);
            $process->setIdleTimeout(30);
            $process->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

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
     * Check if Instagram post is an image post (not video)
     *
     * @param array $mediaInfo
     * @return bool
     */
    private function isImagePost(array $mediaInfo): bool
    {
        // Check ext in info - most reliable
        if (isset($mediaInfo['ext']) && in_array(strtolower($mediaInfo['ext']), ['jpg', 'jpeg', 'png', 'webp'])) {
            return true;
        }
        
        // Check if there are video formats
        $hasVideoFormats = false;
        if (!empty($mediaInfo['formats'])) {
            foreach ($mediaInfo['formats'] as $format) {
                if (isset($format['vcodec']) && $format['vcodec'] !== 'none' && $format['vcodec'] !== null) {
                    $hasVideoFormats = true;
                    break;
                }
            }
        }
        
        // If no video formats, it's likely an image
        if (!$hasVideoFormats) {
            // Check if it's a carousel (multiple images)
            $isCarousel = isset($mediaInfo['_type']) && $mediaInfo['_type'] === 'playlist';
            if ($isCarousel) {
                return true;
            }
            
            // Check if format_id suggests image
            if (isset($mediaInfo['format_id']) && str_contains(strtolower($mediaInfo['format_id']), 'jpg')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if Instagram post is a video post
     *
     * @param array $mediaInfo
     * @return bool
     */
    private function isVideoPost(array $mediaInfo): bool
    {
        // Check ext in info - most reliable
        if (isset($mediaInfo['ext']) && in_array(strtolower($mediaInfo['ext']), ['mp4', 'webm', 'mkv', 'mov'])) {
            return true;
        }
        
        // Check if there are video formats
        if (!empty($mediaInfo['formats'])) {
            foreach ($mediaInfo['formats'] as $format) {
                if (isset($format['vcodec']) && $format['vcodec'] !== 'none' && $format['vcodec'] !== null) {
                    return true;
                }
            }
        }
        
        // Check if format_id suggests video
        if (isset($mediaInfo['format_id']) && str_contains(strtolower($mediaInfo['format_id']), 'mp4')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Download Instagram image post (specific method for images)
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramImage(string $url, string $outputDir): array
    {
        $cookiesPath = config('telegram.instagram_cookies_path');
        $allErrors = [];
        
        // Method 1: Try with cookies (most reliable)
        if ($cookiesPath && file_exists($cookiesPath)) {
            try {
                $result = $this->downloadInstagramImageWithCookies($url, $outputDir, $cookiesPath);
                if (!empty($result)) {
                    Log::info('Instagram image downloaded successfully with cookies', [
                        'url' => $url,
                        'files_count' => count($result),
                    ]);
                    return $result;
                }
            } catch (\Exception $e) {
                $allErrors[] = 'Cookies method: ' . $e->getMessage();
                Log::warning('Instagram image download with cookies failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Method 2: Try direct image URL extraction (most reliable for images)
        try {
            $result = $this->downloadInstagramImageDirect($url, $outputDir);
            if (!empty($result)) {
                Log::info('Instagram image downloaded successfully with direct method', [
                    'url' => $url,
                    'files_count' => count($result),
                ]);
                return $result;
            }
        } catch (\Exception $e) {
            $allErrors[] = 'Direct method: ' . $e->getMessage();
            Log::warning('Instagram direct image download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Method 3: Try without cookies with enhanced headers
        try {
            $result = $this->downloadInstagramImageWithoutCookies($url, $outputDir);
            if (!empty($result)) {
                Log::info('Instagram image downloaded successfully without cookies', [
                    'url' => $url,
                    'files_count' => count($result),
                ]);
                return $result;
            }
        } catch (\Exception $e) {
            $allErrors[] = 'Enhanced headers method: ' . $e->getMessage();
            Log::warning('Instagram image download without cookies failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Method 4: Try alternative format selector
        try {
            $result = $this->downloadInstagramImageAlternative($url, $outputDir);
            if (!empty($result)) {
                Log::info('Instagram image downloaded successfully with alternative format', [
                    'url' => $url,
                    'files_count' => count($result),
                ]);
                return $result;
            }
        } catch (\Exception $e) {
            $allErrors[] = 'Alternative format method: ' . $e->getMessage();
            Log::warning('Instagram alternative image download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Method 5: Last resort - try with minimal arguments
        try {
            $result = $this->downloadInstagramImageMinimal($url, $outputDir);
            if (!empty($result)) {
                Log::info('Instagram image downloaded successfully with minimal method', [
                    'url' => $url,
                    'files_count' => count($result),
                ]);
                return $result;
            }
        } catch (\Exception $e) {
            $allErrors[] = 'Minimal method: ' . $e->getMessage();
            Log::warning('Instagram minimal image download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        // All methods failed
        Log::error('All Instagram image download methods failed', [
            'url' => $url,
            'errors' => $allErrors,
        ]);
        
        throw new \RuntimeException('Instagram rasm yuklab olinmadi. Barcha metodlar muvaffaqiyatsiz. Xatolar: ' . implode('; ', array_slice($allErrors, 0, 3)));
    }
    
    /**
     * Download Instagram video post (specific method for videos)
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramVideo(string $url, string $outputDir): array
    {
        $cookiesPath = config('telegram.instagram_cookies_path');
        
        // Method 1: Try with cookies
        if ($cookiesPath && file_exists($cookiesPath)) {
            try {
                return $this->downloadInstagramVideoWithCookies($url, $outputDir, $cookiesPath);
            } catch (\Exception $e) {
                Log::warning('Instagram video download with cookies failed, trying fallback', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Method 2: Try without cookies
        return $this->downloadInstagramVideoWithoutCookies($url, $outputDir);
    }
    
    /**
     * Download Instagram image with cookies
     *
     * @param string $url
     * @param string $outputDir
     * @param string $cookiesPath
     * @return array
     */
    private function downloadInstagramImageWithCookies(string $url, string $outputDir, string $cookiesPath): array
    {
        // Try multiple format selectors for better compatibility
        // For Instagram images, we need to use format selectors that work with image posts
        $formatSelectors = [
            'best',  // Let yt-dlp choose the best format (works for images)
            'worst', // Sometimes works when best doesn't
            'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
        ];
        
        $lastException = null;
        
        foreach ($formatSelectors as $format) {
            try {
                $arguments = [
                    $this->ytDlpPath,
                    '--no-playlist',
                    '--no-warnings',
                    '--quiet',
                    '--no-progress',
                    '--ignore-errors',
                    '--cookies', $cookiesPath,
                    '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                    '--referer', 'https://www.instagram.com/',
                    '--output', $outputDir . '/%(title)s.%(ext)s',
                    '--format', $format,
                    '--extractor-args', 'instagram:skip_auth=True',
                    '--no-check-certificate',
                    $url,
                ];

                $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                
                // Filter to only return image files (exclude videos)
                $images = array_filter($downloadedFiles, function($file) {
                    return $this->isImage($file);
                });
                
                if (!empty($images)) {
                    Log::info('Instagram image downloaded successfully with cookies', [
                        'url' => $url,
                        'format' => $format,
                        'files_count' => count($images),
                    ]);
                    return array_values($images);
                }
            } catch (\Exception $e) {
                $lastException = $e;
                $errorMsg = strtolower($e->getMessage());
                
                // If "No video formats" error, this is expected for image posts, try next format
                if (str_contains($errorMsg, 'no video formats')) {
                    Log::debug('Instagram image post - no video formats (expected), trying next format', [
                        'url' => $url,
                        'format' => $format,
                    ]);
                    continue;
                }
                
                Log::debug('Instagram image download with cookies attempt failed', [
                    'url' => $url,
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }
        
        // If all format selectors failed with "No video formats", try without format selector
        if ($lastException && str_contains(strtolower($lastException->getMessage()), 'no video formats')) {
            Log::info('All format selectors failed with "No video formats", trying without format selector', [
                'url' => $url,
            ]);
            
            try {
                $arguments = [
                    $this->ytDlpPath,
                    '--no-playlist',
                    '--no-warnings',
                    '--quiet',
                    '--no-progress',
                    '--ignore-errors',
                    '--cookies', $cookiesPath,
                    '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                    '--referer', 'https://www.instagram.com/',
                    '--output', $outputDir . '/%(title)s.%(ext)s',
                    '--extractor-args', 'instagram:skip_auth=True',
                    '--no-check-certificate',
                    $url,
                ];

                $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                
                // Filter to only return image files
                $images = array_filter($downloadedFiles, function($file) {
                    return $this->isImage($file);
                });
                
                if (!empty($images)) {
                    Log::info('Instagram image downloaded successfully without format selector', [
                        'url' => $url,
                        'files_count' => count($images),
                    ]);
                    return array_values($images);
                }
            } catch (\Exception $e) {
                $lastException = $e;
            }
        }
        
        if ($lastException) {
            throw $lastException;
        }
        
        throw new \RuntimeException('No image files were downloaded with cookies');
    }
    
    /**
     * Direct Instagram image download method (most reliable for images)
     * Uses --write-thumbnail and --skip-download to get image URLs, then downloads them
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramImageDirect(string $url, string $outputDir): array
    {
        $cookiesPath = config('telegram.instagram_cookies_path');
        
        // Build arguments for direct image download
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
        ];
        
        // Add cookies if available
        if ($cookiesPath && file_exists($cookiesPath)) {
            $arguments[] = '--cookies';
            $arguments[] = $cookiesPath;
        }
        
        $arguments = array_merge($arguments, [
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            '--no-check-certificate',
            '--write-thumbnail',
            '--convert-thumbnails', 'jpg',
            $url,
        ]);

        try {
            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Filter to only return image files (exclude videos and other files)
            $images = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            
            if (!empty($images)) {
                return array_values($images);
            }
        } catch (\Exception $e) {
            Log::debug('Direct Instagram image download failed, trying without thumbnail', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Fallback: try without thumbnail options
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
        ];
        
        if ($cookiesPath && file_exists($cookiesPath)) {
            $arguments[] = '--cookies';
            $arguments[] = $cookiesPath;
        }
        
        $arguments = array_merge($arguments, [
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            '--no-check-certificate',
            $url,
        ]);
        
        $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
        
        // Filter to only return image files
        $images = array_filter($downloadedFiles, function($file) {
            return $this->isImage($file);
        });
        
        if (empty($images)) {
            throw new \RuntimeException('No image files were downloaded with direct method');
        }
        
        return array_values($images);
    }
    
    /**
     * Minimal Instagram image download method (last resort)
     * Uses minimal arguments to avoid any potential issues
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramImageMinimal(string $url, string $outputDir): array
    {
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best',
            $url,
        ];

        $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
        
        // Filter to only return image files
        $images = array_filter($downloadedFiles, function($file) {
            return $this->isImage($file);
        });
        
        if (empty($images)) {
            throw new \RuntimeException('No image files were downloaded with minimal method');
        }
        
        return array_values($images);
    }
    
    /**
     * Download Instagram image without cookies
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramImageWithoutCookies(string $url, string $outputDir): array
    {
        // Try multiple user agents and format selectors for better compatibility
        $userAgents = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];
        
        $formatSelectors = [
            'best',  // Try without specific format first
            'worst', // Sometimes works when best doesn't
            'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
        ];
        
        $lastException = null;
        
        foreach ($userAgents as $userAgent) {
            foreach ($formatSelectors as $format) {
                try {
                    $arguments = [
                        $this->ytDlpPath,
                        '--no-playlist',
                        '--no-warnings',
                        '--quiet',
                        '--no-progress',
                        '--ignore-errors',
                        '--user-agent', $userAgent,
                        '--referer', 'https://www.instagram.com/',
                        '--add-header', 'Accept-Language:en-US,en;q=0.9',
                        '--add-header', 'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                        '--output', $outputDir . '/%(title)s.%(ext)s',
                        '--format', $format,
                        '--extractor-args', 'instagram:skip_auth=True',
                        $url,
                    ];

                    $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                    
                    // Filter to only return image files (exclude videos)
                    $images = array_filter($downloadedFiles, function($file) {
                        return $this->isImage($file);
                    });
                    
                    if (!empty($images)) {
                        Log::info('Instagram image downloaded successfully without cookies', [
                            'url' => $url,
                            'user_agent' => substr($userAgent, 0, 50) . '...',
                            'format' => $format,
                            'files_count' => count($images),
                        ]);
                        return array_values($images);
                    }
                } catch (\Exception $e) {
                    $errorMsg = strtolower($e->getMessage());
                    
                    // If "No video formats" error, this is expected for image posts, try next format
                    if (str_contains($errorMsg, 'no video formats')) {
                        Log::debug('Instagram image post - no video formats (expected), trying next format', [
                            'url' => $url,
                            'format' => $format,
                        ]);
                        continue;
                    }
                    
                    $lastException = $e;
                    Log::debug('Instagram image download attempt failed', [
                        'url' => $url,
                        'user_agent' => substr($userAgent, 0, 50) . '...',
                        'format' => $format,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }
        
        // If all attempts failed, throw the last exception
        if ($lastException) {
            throw $lastException;
        }
        
        throw new \RuntimeException('No image files were downloaded');
    }
    
    /**
     * Alternative Instagram image download method with different format selectors
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramImageAlternative(string $url, string $outputDir): array
    {
        // Try different format selectors
        $formatSelectors = [
            'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
            'best[height<=1080][ext=jpg]/best[height<=1080][ext=jpeg]/best[height<=1080][ext=png]/best[ext=jpg]/best',
            'best',
        ];
        
        $lastException = null;
        
        foreach ($formatSelectors as $format) {
            try {
                $arguments = [
                    $this->ytDlpPath,
                    '--no-playlist',
                    '--no-warnings',
                    '--quiet',
                    '--no-progress',
                    '--ignore-errors',
                    '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                    '--referer', 'https://www.instagram.com/',
                    '--add-header', 'Accept-Language:en-US,en;q=0.9',
                    '--add-header', 'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    '--output', $outputDir . '/%(title)s.%(ext)s',
                    '--format', $format,
                    '--extractor-args', 'instagram:skip_auth=True',
                    $url,
                ];

                $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                
                // Filter to only return image files (exclude videos)
                $images = array_filter($downloadedFiles, function($file) {
                    return $this->isImage($file);
                });
                
                if (!empty($images)) {
                    Log::info('Instagram image downloaded with alternative format', [
                        'url' => $url,
                        'format' => $format,
                        'files_count' => count($images),
                    ]);
                    return array_values($images);
                }
            } catch (\Exception $e) {
                $lastException = $e;
                Log::debug('Instagram alternative image download attempt failed', [
                    'url' => $url,
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }
        
        // If all attempts failed, throw the last exception
        if ($lastException) {
            throw $lastException;
        }
        
        throw new \RuntimeException('No image files were downloaded');
    }
    
    /**
     * Download Instagram video with cookies
     *
     * @param string $url
     * @param string $outputDir
     * @param string $cookiesPath
     * @return array
     */
    private function downloadInstagramVideoWithCookies(string $url, string $outputDir, string $cookiesPath): array
    {
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--cookies', $cookiesPath,
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=mp4]/best[ext=webm]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            $url,
        ];

        $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
        
        // Filter to only return video files (exclude images)
        return array_filter($downloadedFiles, function($file) {
            return $this->isVideo($file);
        });
    }
    
    /**
     * Download Instagram video without cookies
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramVideoWithoutCookies(string $url, string $outputDir): array
    {
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--add-header', 'Accept-Language:en-US,en;q=0.9',
            '--add-header', 'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=mp4]/best[ext=webm]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            $url,
        ];

        $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
        
        // Filter to only return video files (exclude images)
        return array_filter($downloadedFiles, function($file) {
            return $this->isVideo($file);
        });
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
                if (in_array($extension, ['json', 'description', 'info'])) {
                    continue;
                }
                
                // Skip .webp thumbnails but allow other webp images
                if ($extension === 'webp' && (str_contains(strtolower($filename), 'thumb') || str_contains(strtolower($filename), 'thumbnail'))) {
                    continue;
                }
                
                // Filter by extension if specified
                if ($allowedExtensions !== null) {
                    if (!in_array($extension, $allowedExtensions)) {
                        continue;
                    }
                    
                    // For images, also check if file is actually an image by checking MIME type
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
                        try {
                            $mimeType = @mime_content_type($file->getPathname());
                            if ($mimeType && !str_starts_with($mimeType, 'image/')) {
                                // Skip if MIME type doesn't match (but allow if mime_content_type fails)
                                Log::debug('Skipping file with non-image MIME type', [
                                    'file' => $file->getFilename(),
                                    'extension' => $extension,
                                    'mime_type' => $mimeType,
                                ]);
                                continue;
                            }
                        } catch (\Exception $e) {
                            // If MIME type check fails, still include the file (might be valid image)
                            Log::debug('MIME type check failed, including file anyway', [
                                'file' => $file->getFilename(),
                                'error' => $e->getMessage(),
                            ]);
                        }
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
        if ($cookiesPath && file_exists($cookiesPath) && filesize($cookiesPath) > 100) {
            try {
                $result = $this->downloadWithCookies($url, $outputDir, $cookiesPath);
                
                // If we got files, return them
                if (!empty($result)) {
                    return $result;
                }
            } catch (\Exception $e) {
                Log::warning('Instagram download with cookies failed, trying fallback', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'cookies_path' => $cookiesPath,
                    'cookies_file_exists' => file_exists($cookiesPath),
                    'cookies_file_size' => file_exists($cookiesPath) ? filesize($cookiesPath) : 0,
                ]);
            }
        } else {
            Log::warning('Instagram cookies file not available or invalid', [
                'cookies_path' => $cookiesPath,
                'file_exists' => $cookiesPath && file_exists($cookiesPath),
                'file_size' => ($cookiesPath && file_exists($cookiesPath)) ? filesize($cookiesPath) : 0,
            ]);
        }

        // Method 2: Try with enhanced headers
        try {
            return $this->downloadWithEnhancedHeaders($url, $outputDir);
        } catch (\Exception $e) {
            Log::warning('Instagram download with enhanced headers failed, trying alternative method', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            // Method 3: Try with alternative format selector for images
            try {
                return $this->downloadInstagramAlternative($url, $outputDir);
            } catch (\Exception $e2) {
                Log::error('All Instagram download methods failed', [
                    'url' => $url,
                    'cookies_error' => $e->getMessage(),
                    'alternative_error' => $e2->getMessage(),
                ]);
                throw $e2;
            }
        }
    }
    
    /**
     * Alternative Instagram download method (for images)
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramAlternative(string $url, string $outputDir): array
    {
        // Try with image format first
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            $url,
        ];

        try {
            return $this->executeDownload($arguments, $url, $outputDir);
        } catch (\Exception $e) {
            Log::warning('Instagram alternative download failed, trying with best format', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            // Try with just 'best' format (let yt-dlp decide)
            $arguments[array_search('best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best', $arguments)] = 'best';
            return $this->executeDownload($arguments, $url, $outputDir);
        }
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
        // Check if cookies file is valid
        if (!file_exists($cookiesPath) || filesize($cookiesPath) < 100) {
            Log::warning('Cookies file is missing or too small, trying without cookies', [
                'cookies_path' => $cookiesPath,
                'file_exists' => file_exists($cookiesPath),
                'file_size' => file_exists($cookiesPath) ? filesize($cookiesPath) : 0,
            ]);
            throw new \RuntimeException('Cookies file is invalid');
        }
        
        // Check if cookies file contains instagram.com
        $cookiesContent = @file_get_contents($cookiesPath);
        if ($cookiesContent && !str_contains($cookiesContent, 'instagram.com')) {
            Log::warning('Cookies file does not contain instagram.com', [
                'cookies_path' => $cookiesPath,
            ]);
        }
        
        // Try image format first (for image posts)
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--cookies', $cookiesPath,
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best[ext=mp4]/best[ext=webm]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            $url,
        ];

        try {
            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Separate images and videos
            $imageFiles = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            $videoFiles = array_filter($downloadedFiles, function($file) {
                return $this->isVideo($file);
            });
            
            // If we have both, prefer the primary type based on URL
            if (!empty($imageFiles) && !empty($videoFiles)) {
                // Check URL to determine primary type
                if (str_contains($url, '/reel/')) {
                    // Reel is usually video
                    Log::info('Both images and videos downloaded from reel, preferring videos', [
                        'url' => $url,
                        'images_count' => count($imageFiles),
                        'videos_count' => count($videoFiles),
                    ]);
                    return array_values($videoFiles);
                } elseif (str_contains($url, '/p/')) {
                    // Post can be either, prefer images if more images
                    if (count($imageFiles) >= count($videoFiles)) {
                        Log::info('Both images and videos downloaded from post, preferring images', [
                            'url' => $url,
                            'images_count' => count($imageFiles),
                            'videos_count' => count($videoFiles),
                        ]);
                        return array_values($imageFiles);
                    } else {
                        Log::info('Both images and videos downloaded from post, preferring videos', [
                            'url' => $url,
                            'images_count' => count($imageFiles),
                            'videos_count' => count($videoFiles),
                        ]);
                        return array_values($videoFiles);
                    }
                }
            }
            
            // Return what we have
            return !empty($imageFiles) ? array_values($imageFiles) : (!empty($videoFiles) ? array_values($videoFiles) : $downloadedFiles);
        } catch (\Exception $e) {
            // If image format fails, try video format
            if (str_contains(strtolower($e->getMessage()), 'no video formats') || 
                str_contains(strtolower($e->getMessage()), 'no formats found')) {
                Log::info('Image format failed, trying video format', ['url' => $url]);
                $arguments[array_search('best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best[ext=mp4]/best[ext=webm]/best', $arguments)] = 'best[ext=mp4]/best[ext=webm]/best';
                $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                
                // Filter to only return video files
                $videoFiles = array_filter($downloadedFiles, function($file) {
                    return $this->isVideo($file);
                });
                
                return !empty($videoFiles) ? array_values($videoFiles) : $downloadedFiles;
            }
            throw $e;
        }
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
        // Try image format first (for image posts)
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            '--referer', 'https://www.instagram.com/',
            '--add-header', 'Accept-Language:en-US,en;q=0.9',
            '--add-header', 'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            '--add-header', 'Accept-Encoding:gzip, deflate, br',
            '--add-header', 'DNT:1',
            '--add-header', 'Connection:keep-alive',
            '--add-header', 'Upgrade-Insecure-Requests:1',
            '--add-header', 'Sec-Fetch-Dest:document',
            '--add-header', 'Sec-Fetch-Mode:navigate',
            '--add-header', 'Sec-Fetch-Site:none',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best[ext=mp4]/best[ext=webm]/best',
            '--extractor-args', 'instagram:skip_auth=True',
            $url,
        ];

        try {
            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Separate images and videos
            $imageFiles = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            $videoFiles = array_filter($downloadedFiles, function($file) {
                return $this->isVideo($file);
            });
            
            // If we have both, prefer images (since this is enhanced headers method, might be image post)
            if (!empty($imageFiles) && !empty($videoFiles)) {
                Log::info('Both images and videos downloaded, returning only images', [
                    'url' => $url,
                    'images_count' => count($imageFiles),
                    'videos_count' => count($videoFiles),
                ]);
                return array_values($imageFiles);
            }
            
            // Return what we have
            return $downloadedFiles;
        } catch (\Exception $e) {
            // If image format fails with "No video formats", try video-only format
            if (str_contains(strtolower($e->getMessage()), 'no video formats') || 
                str_contains(strtolower($e->getMessage()), 'no formats found')) {
                Log::info('Image format failed, trying video format', ['url' => $url]);
                $arguments[array_search('best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best[ext=mp4]/best[ext=webm]/best', $arguments)] = 'best[ext=mp4]/best[ext=webm]/best';
                $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                
                // Filter to only return video files
                $videoFiles = array_filter($downloadedFiles, function($file) {
                    return $this->isVideo($file);
                });
                
                return !empty($videoFiles) ? array_values($videoFiles) : $downloadedFiles;
            }
            
            // Try alternative user agent
            Log::warning('Instagram download with first user agent failed, trying alternative', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            $arguments[array_search('Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1', $arguments)] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Filter based on what we get
            $imageFiles = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            $videoFiles = array_filter($downloadedFiles, function($file) {
                return $this->isVideo($file);
            });
            
            // Prefer images if both exist
            if (!empty($imageFiles) && !empty($videoFiles)) {
                return array_values($imageFiles);
            }
            
            return $downloadedFiles;
        }
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
                
                // Check for specific Instagram errors
                $isInstagram = str_contains($url, 'instagram.com');
                $errorText = strtolower($errorOutput . ' ' . $stdOutput);
                
                $specificError = null;
                if (str_contains($errorText, 'no video formats') && $isInstagram) {
                    // For Instagram image posts, "No video formats" is expected
                    // Don't throw error, let the caller handle it (might be image post)
                    $specificError = 'Instagram rasm post - video format topilmadi (kutilgan), rasm format qidirilmoqda';
                    Log::warning('Instagram image post detected - no video formats (expected)', [
                        'url' => $url,
                        'error' => $errorOutput,
                    ]);
                    // Don't throw here - let the caller try alternative methods
                    throw new \RuntimeException($specificError);
                } elseif (str_contains($errorText, 'private') || str_contains($errorText, 'login required')) {
                    $specificError = 'Instagram post is private or requires login';
                } elseif (str_contains($errorText, 'not found') || str_contains($errorText, 'unavailable')) {
                    $specificError = 'Instagram post not found or unavailable';
                } elseif (str_contains($errorText, 'rate limit') || str_contains($errorText, 'too many requests')) {
                    $specificError = 'Instagram rate limit - too many requests';
                } elseif (str_contains($errorText, 'extractor') && $isInstagram) {
                    $specificError = 'Instagram extractor error - API may have changed';
                }
                
                Log::error('yt-dlp download failed', [
                    'url' => $url,
                    'exit_code' => $process->getExitCode(),
                    'error' => $errorOutput,
                    'output' => substr($stdOutput, 0, 500),
                    'full_command' => implode(' ', array_slice($arguments, 0, 5)) . '...',
                    'is_instagram' => $isInstagram,
                    'specific_error' => $specificError,
                ]);
                
                $errorMessage = $specificError ?: ('Download failed: ' . ($errorOutput ?: $stdOutput ?: 'Unknown error'));
                throw new \RuntimeException($errorMessage);
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
                            'mime_type' => mime_content_type($file->getPathname()),
                        ];
                    }
                }
            }
            
            // Check if this is Instagram and provide more specific error
            $isInstagram = str_contains($url, 'instagram.com');
            $errorMessage = $isInstagram 
                ? 'Instagram rasm yuklab olinmadi. Kontent maxfiy bo\'lishi yoki Instagram API o\'zgargan bo\'lishi mumkin.'
                : 'No files were downloaded';
            
            Log::error('No files were downloaded', [
                'url' => $url,
                'output_dir' => $outputDir,
                'directory_contents' => $dirContents,
                'is_instagram' => $isInstagram,
                'error_message' => $errorMessage,
            ]);
            
            throw new \RuntimeException($errorMessage);
        }

        Log::info('Download completed', [
            'url' => $url,
            'files_count' => count($downloadedFiles),
            'files' => array_map('basename', $downloadedFiles),
        ]);

        return $downloadedFiles;
    }
}
