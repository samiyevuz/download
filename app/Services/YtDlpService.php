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
     * Get Instagram cookies paths (supports multiple cookies for rotation)
     *
     * @return array Array of cookie file paths
     */
    private function getInstagramCookiesPaths(): array
    {
        $paths = [];
        
        // First, check for multiple cookies (comma-separated)
        $multipleCookies = config('telegram.instagram_cookies_paths');
        if (!empty($multipleCookies)) {
            $cookieList = explode(',', $multipleCookies);
            foreach ($cookieList as $cookiePath) {
                $cookiePath = trim($cookiePath);
                if (!empty($cookiePath)) {
                    // Convert to absolute path if relative
                    if (!str_starts_with($cookiePath, '/')) {
                        // Relative path - convert to absolute
                        $cookiePath = base_path($cookiePath);
                    }
                    // Normalize path (resolve .. and .)
                    $cookiePath = realpath($cookiePath) ?: $cookiePath;
                    $paths[] = $cookiePath;
                }
            }
        }
        
        // Fallback to single cookie path
        $singleCookie = config('telegram.instagram_cookies_path');
        if (!empty($singleCookie)) {
            // Convert to absolute path if relative
            if (!str_starts_with($singleCookie, '/')) {
                // Relative path - convert to absolute
                $singleCookie = base_path($singleCookie);
            }
            // Normalize path (resolve .. and .)
            $singleCookie = realpath($singleCookie) ?: $singleCookie;
            // Only add if not already in paths
            if (!in_array($singleCookie, $paths)) {
                $paths[] = $singleCookie;
            }
        }
        
        // Remove duplicates and filter out empty paths
        $paths = array_unique(array_filter($paths));
        
        // Filter out paths that don't exist (but keep them for logging)
        $validPaths = array_filter($paths, function($path) {
            return file_exists($path) && is_readable($path);
        });
        
        Log::debug('Instagram cookies paths', [
            'paths_count' => count($paths),
            'valid_paths_count' => count($validPaths),
            'paths' => array_map('basename', $paths),
            'full_paths' => $paths,
            'valid_full_paths' => array_values($validPaths),
        ]);
        
        // Return valid paths only (as absolute paths)
        return array_values($validPaths);
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
            // Try with first available cookie
            $cookiesPaths = $this->getInstagramCookiesPaths();
            $cookiesPath = !empty($cookiesPaths) ? $cookiesPaths[0] : null;
            
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
        $allErrors = [];
        
        // Method 1: Try with multiple cookies (rotation for reliability)
        $cookiesPaths = $this->getInstagramCookiesPaths();
        
        foreach ($cookiesPaths as $index => $cookiesPath) {
            if ($cookiesPath && file_exists($cookiesPath) && filesize($cookiesPath) > 100) {
                try {
                    Log::info('Trying Instagram image download with cookie file', [
                        'url' => $url,
                        'cookie_index' => $index + 1,
                        'cookie_path' => basename($cookiesPath),
                        'cookie_size' => filesize($cookiesPath),
                    ]);
                    
                    $result = $this->downloadInstagramImageWithCookies($url, $outputDir, $cookiesPath);
                    if (!empty($result)) {
                        Log::info('Instagram image downloaded successfully with cookies', [
                            'url' => $url,
                            'files_count' => count($result),
                            'cookie_index' => $index + 1,
                            'cookie_path' => basename($cookiesPath),
                        ]);
                        return $result;
                    }
                } catch (\Exception $e) {
                    $allErrors[] = "Cookie #{$index} ({$cookiesPath}): " . $e->getMessage();
                    Log::warning('Instagram image download with cookie failed, trying next', [
                        'url' => $url,
                        'cookie_index' => $index + 1,
                        'cookie_path' => basename($cookiesPath),
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to next cookie
                    continue;
                }
            } else {
                Log::debug('Skipping invalid cookie file', [
                    'cookie_index' => $index + 1,
                    'cookie_path' => $cookiesPath,
                    'exists' => $cookiesPath && file_exists($cookiesPath),
                    'size' => ($cookiesPath && file_exists($cookiesPath)) ? filesize($cookiesPath) : 0,
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
        
        // Method 6: Last resort - Try to extract image URL from HTML page directly
        // This is used when yt-dlp cannot get JSON or download files
        Log::info('Method 6: Trying HTML parsing method as last resort', [
            'url' => $url,
            'errors_so_far' => count($allErrors),
        ]);
        try {
            $result = $this->downloadInstagramImageFromHtml($url, $outputDir);
            if (!empty($result)) {
                Log::info('Instagram image downloaded via HTML parsing method', [
                    'url' => $url,
                    'files_count' => count($result),
                ]);
                return $result;
            }
            Log::warning('HTML parsing method returned empty result', ['url' => $url]);
            $allErrors[] = 'HTML parsing method: No files downloaded';
        } catch (\Exception $e) {
            $allErrors[] = 'HTML parsing method: ' . $e->getMessage();
            Log::warning('Instagram HTML parsing method failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
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
        // Try with multiple cookies (rotation for reliability)
        $cookiesPaths = $this->getInstagramCookiesPaths();
        
        foreach ($cookiesPaths as $index => $cookiesPath) {
            if ($cookiesPath && file_exists($cookiesPath) && filesize($cookiesPath) > 100) {
                try {
                    Log::info('Trying Instagram video download with cookie file', [
                        'url' => $url,
                        'cookie_index' => $index + 1,
                        'cookie_path' => basename($cookiesPath),
                    ]);
                    
                    $result = $this->downloadInstagramVideoWithCookies($url, $outputDir, $cookiesPath);
                    if (!empty($result)) {
                        Log::info('Instagram video downloaded successfully with cookies', [
                            'url' => $url,
                            'cookie_index' => $index + 1,
                            'cookie_path' => basename($cookiesPath),
                        ]);
                        return $result;
                    }
                } catch (\Exception $e) {
                    Log::warning('Instagram video download with cookie failed, trying next', [
                        'url' => $url,
                        'cookie_index' => $index + 1,
                        'cookie_path' => basename($cookiesPath),
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to next cookie
                    continue;
                }
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
        // Ensure cookie path is absolute
        if (!str_starts_with($cookiesPath, '/')) {
            $cookiesPath = base_path($cookiesPath);
        }
        $cookiesPath = realpath($cookiesPath) ?: $cookiesPath;
        
        // Verify cookie file exists and is readable
        if (!file_exists($cookiesPath) || !is_readable($cookiesPath)) {
            throw new \RuntimeException("Cookie file not found or not readable: {$cookiesPath}");
        }
        
        Log::debug('Using cookie file for Instagram download', [
            'url' => $url,
            'cookie_path' => $cookiesPath,
            'cookie_exists' => file_exists($cookiesPath),
            'cookie_size' => file_exists($cookiesPath) ? filesize($cookiesPath) : 0,
        ]);
        
        // Method 1: List formats first to see what's available
        // This helps us understand what formats Instagram provides
        try {
            $listArguments = [
                $this->ytDlpPath,
                '--no-playlist',
                '--no-warnings',
                '--list-formats',
                '--cookies', $cookiesPath,
                '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                '--referer', 'https://www.instagram.com/',
                $url,
            ];

            $listProcess = new Process($listArguments);
            $listProcess->setTimeout(30);
            $listProcess->setIdleTimeout(30);
            $listProcess->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

            try {
                $listProcess->run();
                
                if ($listProcess->isSuccessful()) {
                    $formatOutput = $listProcess->getOutput();
                    
                    // Check if there are image formats available
                    if (preg_match('/\d+\s+(\w+)\s+.*?(jpg|jpeg|png|webp)/i', $formatOutput)) {
                        Log::info('Image formats found in format list', ['url' => $url]);
                        
                        // Try downloading with format ID that matches image
                        // Extract format IDs for images
                        if (preg_match_all('/(\d+)\s+(\w+)\s+.*?(jpg|jpeg|png|webp)/i', $formatOutput, $matches)) {
                            $imageFormatIds = $matches[1];
                            if (!empty($imageFormatIds)) {
                                // Try with first image format ID
                                $formatId = $imageFormatIds[0];
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
                                    '--format', $formatId,
                                    $url,
                                ];

                                $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                                
                                $images = array_filter($downloadedFiles, function($file) {
                                    return $this->isImage($file);
                                });
                                
                                if (!empty($images)) {
                                    Log::info('Instagram image downloaded with format ID', [
                                        'url' => $url,
                                        'format_id' => $formatId,
                                        'files_count' => count($images),
                                    ]);
                                    return array_values($images);
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Format listing failed, continuing with other methods', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('Format listing setup failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        // Method 2: Try with --dump-json to extract image URLs directly
        // This is the most reliable method for Instagram images
        // Use --ignore-errors to get JSON even if "No video formats" error occurs
        try {
            $jsonArguments = [
                $this->ytDlpPath,
                '--dump-json',
                '--no-playlist',
                '--no-warnings',
                '--quiet',
                '--ignore-errors',
                '--cookies', $cookiesPath,
                '--user-agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                '--referer', 'https://www.instagram.com/',
                '--extractor-args', 'instagram:skip_auth=False',
                $url,
            ];

            $jsonProcess = new Process($jsonArguments);
            $jsonProcess->setTimeout(30);
            $jsonProcess->setIdleTimeout(30);
            $jsonProcess->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

            try {
                $jsonProcess->run();
                
                // Even if process reports failure, try to parse JSON from output
                // Instagram image posts often return "No video formats" but still provide JSON
                $jsonOutput = $jsonProcess->getOutput();
                $errorOutput = $jsonProcess->getErrorOutput();
                
                // If output is empty but error contains "No video formats", process might have partially succeeded
                // Try to continue parsing even if process reports failure
                $isNoVideoFormatsError = str_contains(strtolower($errorOutput), 'no video formats');
                
                // Try to extract JSON from output (might be mixed with errors)
                $jsonLines = explode("\n", $jsonOutput);
                $jsonData = null;
                
                // Look for JSON object in output lines
                foreach ($jsonLines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Check if line starts with { (JSON object)
                    if (str_starts_with($line, '{')) {
                        $decoded = json_decode($line, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $jsonData = $decoded;
                            break;
                        }
                    }
                }
                
                // If no JSON found in output, try parsing entire output
                if ($jsonData === null && !empty($jsonOutput)) {
                    $decoded = json_decode($jsonOutput, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $jsonData = $decoded;
                    }
                }
                
                // If still no JSON and "No video formats" error, try parsing error output (sometimes JSON is in stderr)
                if ($jsonData === null && $isNoVideoFormatsError && !empty($errorOutput)) {
                    // Try to find JSON in error output
                    $errorLines = explode("\n", $errorOutput);
                    foreach ($errorLines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        if (str_starts_with($line, '{')) {
                            $decoded = json_decode($line, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $jsonData = $decoded;
                                Log::debug('JSON found in error output', ['url' => $url]);
                                break;
                            }
                        }
                    }
                }
                
                // If still no JSON, try combining output and error output
                if ($jsonData === null && !empty($jsonOutput . $errorOutput)) {
                    $combinedOutput = $jsonOutput . "\n" . $errorOutput;
                    // Try to find JSON object in combined output (more comprehensive regex)
                    // Look for JSON objects that might contain URLs or image data
                    if (preg_match('/\{[^{}]*(?:"url"|"thumbnail"|"formats"|"display_url")[^{}]*\}/s', $combinedOutput, $matches)) {
                        $decoded = json_decode($matches[0], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $jsonData = $decoded;
                            Log::debug('JSON found in combined output via regex', ['url' => $url]);
                        } else {
                            // Try to find larger JSON block
                            if (preg_match('/\{.*"url".*\}/s', $combinedOutput, $largerMatches)) {
                                $decoded = json_decode($largerMatches[0], true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $jsonData = $decoded;
                                    Log::debug('JSON found in combined output via larger regex', ['url' => $url]);
                                }
                            }
                        }
                    }
                }
                
                // If still no JSON but "No video formats" error, try to get thumbnail directly
                // Sometimes yt-dlp fails to get JSON but can still get thumbnails
                if ($jsonData === null && $isNoVideoFormatsError) {
                    Log::debug('No JSON found but "No video formats" error, trying thumbnail method as fallback', ['url' => $url]);
                    // This will be handled by the thumbnail method that comes after JSON extraction
                }
                
                if ($jsonData && is_array($jsonData)) {
                    Log::debug('JSON extracted from yt-dlp output', [
                        'url' => $url,
                        'has_thumbnail' => !empty($jsonData['thumbnail']),
                        'has_url' => !empty($jsonData['url']),
                        'has_formats' => !empty($jsonData['formats']),
                    ]);
                    
                    // Try to extract image URLs from JSON
                    $imageUrls = [];
                    
                    // Check for thumbnail
                    if (!empty($jsonData['thumbnail'])) {
                        $imageUrls[] = $jsonData['thumbnail'];
                    }
                    
                    // Check for thumbnails array
                    if (!empty($jsonData['thumbnails']) && is_array($jsonData['thumbnails'])) {
                        foreach ($jsonData['thumbnails'] as $thumb) {
                            if (!empty($thumb['url'])) {
                                $imageUrls[] = $thumb['url'];
                            }
                        }
                    }
                    
                    // Check for url (direct image URL)
                    if (!empty($jsonData['url'])) {
                        // Check if it's an image URL or try to use it anyway
                        if ($this->isImageUrl($jsonData['url']) || empty($jsonData['vcodec'])) {
                            $imageUrls[] = $jsonData['url'];
                        }
                    }
                    
                    // Check for formats array
                    if (!empty($jsonData['formats']) && is_array($jsonData['formats'])) {
                        foreach ($jsonData['formats'] as $format) {
                            if (!empty($format['url'])) {
                                // Prefer image formats, but also check if vcodec is none
                                if ($this->isImageUrl($format['url']) || 
                                    (empty($format['vcodec']) || $format['vcodec'] === 'none')) {
                                    $imageUrls[] = $format['url'];
                                }
                            }
                        }
                    }
                    
                    // Download images from extracted URLs
                    if (!empty($imageUrls)) {
                        $downloadedFiles = [];
                        foreach (array_unique($imageUrls) as $imageUrl) {
                            try {
                                $imagePath = $this->downloadImageFromUrl($imageUrl, $outputDir);
                                if ($imagePath && file_exists($imagePath)) {
                                    $downloadedFiles[] = $imagePath;
                                }
                            } catch (\Exception $e) {
                                Log::debug('Failed to download image from extracted URL', [
                                    'url' => $imageUrl,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                        
                        if (!empty($downloadedFiles)) {
                            Log::info('Instagram image downloaded via JSON extraction', [
                                'url' => $url,
                                'files_count' => count($downloadedFiles),
                            ]);
                            return array_values($downloadedFiles);
                        }
                    } else {
                        Log::debug('No image URLs found in JSON', [
                            'url' => $url,
                            'json_keys' => array_keys($jsonData),
                        ]);
                    }
                } else {
                    // Log error for debugging
                    Log::debug('JSON extraction failed - no valid JSON in output', [
                        'url' => $url,
                        'exit_code' => $jsonProcess->getExitCode(),
                        'output_length' => strlen($jsonOutput),
                        'error_length' => strlen($errorOutput),
                        'error_preview' => substr($errorOutput, 0, 200),
                    ]);
                }
            } catch (\Exception $e) {
                Log::debug('JSON extraction method exception', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('JSON extraction method setup failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Method 2b: Try with --write-thumbnail and --skip-download as fallback
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
                '--extractor-args', 'instagram:skip_auth=False',
                '--output', $outputDir . '/%(title)s.%(ext)s',
                '--write-thumbnail',
                '--skip-download',
                $url,
            ];

            $process = new Process($arguments);
            $process->setTimeout(30);
            $process->setIdleTimeout(30);
            $process->setWorkingDirectory($outputDir);
            $process->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

            try {
                Log::debug('Trying Instagram thumbnail method (write-thumbnail + skip-download)', ['url' => $url]);
                $process->run();
                
                // Even if process reports failure, check for downloaded thumbnails
                // Instagram image posts often return "No video formats" but still download thumbnails
                $errorOutput = $process->getErrorOutput();
                $isNoVideoFormats = str_contains(strtolower($errorOutput), 'no video formats');
                
                // Check for thumbnail files regardless of process success/failure
                // Especially if "No video formats" error (which is expected for image posts)
                $thumbnails = $this->findDownloadedFiles($outputDir, ['jpg', 'jpeg', 'png', 'webp']);
                
                if (!empty($thumbnails)) {
                    Log::info('Instagram image downloaded via thumbnail method', [
                        'url' => $url,
                        'files_count' => count($thumbnails),
                        'process_successful' => $process->isSuccessful(),
                        'is_no_video_formats' => $isNoVideoFormats,
                    ]);
                    return array_values($thumbnails);
                } else {
                    // Log for debugging if no thumbnails found
                    if ($isNoVideoFormats) {
                        Log::debug('No video formats error but no thumbnails found', [
                            'url' => $url,
                            'output_dir' => $outputDir,
                            'error' => substr($errorOutput, 0, 300),
                        ]);
                    } else {
                        Log::debug('Thumbnail method failed', [
                            'url' => $url,
                            'exit_code' => $process->getExitCode(),
                            'error' => substr($errorOutput, 0, 500),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Thumbnail method exception, trying direct download', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('Thumbnail method setup failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        // Method 3: Try direct download without format selector (let yt-dlp decide)
        // This is the most reliable for Instagram image posts
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
                // No format selector - let yt-dlp choose automatically
                $url,
            ];

            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Filter to only return image files (exclude videos)
            $images = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            
            if (!empty($images)) {
                Log::info('Instagram image downloaded successfully with cookies (no format selector)', [
                    'url' => $url,
                    'files_count' => count($images),
                ]);
                return array_values($images);
            }
        } catch (\Exception $e) {
            $errorMsg = strtolower($e->getMessage());
            
            // If "No video formats" error, this is expected for image posts
            // Try alternative methods
            if (str_contains($errorMsg, 'no video formats')) {
                Log::info('No video formats error (expected for image posts), trying alternative methods', [
                    'url' => $url,
                ]);
                
                // Method 4: Try with --write-thumbnail without --skip-download
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
                        '--write-thumbnail',
                        $url,
                    ];

                    $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                    
                    $images = array_filter($downloadedFiles, function($file) {
                        return $this->isImage($file);
                    });
                    
                    if (!empty($images)) {
                        Log::info('Instagram image downloaded with thumbnail write', [
                            'url' => $url,
                            'files_count' => count($images),
                        ]);
                        return array_values($images);
                    }
                } catch (\Exception $e2) {
                    Log::debug('Thumbnail write method failed', [
                        'url' => $url,
                        'error' => $e2->getMessage(),
                    ]);
                }
                
                // Method 5: Try with explicit image format selector (ignore "no video formats" error)
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
                        '--extractor-args', 'instagram:skip_auth=False',
                        '--output', $outputDir . '/%(title)s.%(ext)s',
                        '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
                        $url,
                    ];

                    $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
                    
                    $images = array_filter($downloadedFiles, function($file) {
                        return $this->isImage($file);
                    });
                    
                    if (!empty($images)) {
                        Log::info('Instagram image downloaded with explicit image format', [
                            'url' => $url,
                            'files_count' => count($images),
                        ]);
                        return array_values($images);
                    }
                } catch (\Exception $e2) {
                    Log::debug('Explicit image format failed', [
                        'url' => $url,
                        'error' => $e2->getMessage(),
                    ]);
                }
            }
            
            throw $e;
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
        // Try with first available cookie
        $cookiesPaths = $this->getInstagramCookiesPaths();
        $cookiesPath = !empty($cookiesPaths) ? $cookiesPaths[0] : null;
        
        // Method 1: Try with --write-thumbnail and --skip-download (most reliable for images)
        if ($cookiesPath && file_exists($cookiesPath)) {
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
                    '--write-thumbnail',
                    '--skip-download',
                    $url,
                ];

                $process = new Process($arguments);
                $process->setTimeout(30);
                $process->setIdleTimeout(30);
                $process->setWorkingDirectory($outputDir);
                $process->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

                Log::debug('Trying Instagram thumbnail method', ['url' => $url]);
                $process->run();
                
                if ($process->isSuccessful()) {
                    // Find thumbnail files
                    $thumbnails = $this->findDownloadedFiles($outputDir, ['jpg', 'jpeg', 'png', 'webp']);
                    if (!empty($thumbnails)) {
                        Log::info('Instagram image downloaded via thumbnail method', [
                            'url' => $url,
                            'files_count' => count($thumbnails),
                            'files' => array_map('basename', $thumbnails),
                        ]);
                        return array_values($thumbnails);
                    } else {
                        Log::debug('Thumbnail method successful but no files found, trying full download', [
                            'url' => $url,
                            'output_dir' => $outputDir,
                        ]);
                    }
                } else {
                    $errorOutput = $process->getErrorOutput();
                    $stdOutput = $process->getOutput();
                    Log::debug('Thumbnail method failed', [
                        'url' => $url,
                        'error' => $errorOutput,
                        'output' => substr($stdOutput, 0, 200),
                    ]);
                }
            } catch (\Exception $e) {
                Log::debug('Thumbnail method exception, trying direct download', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Method 2: Try direct download with image format selector
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
            // Try image format first, then best
            '--format', 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
            $url,
        ]);
        
        try {
            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Filter to only return image files
            $images = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            
            if (!empty($images)) {
                Log::info('Instagram image downloaded via direct method with format selector', [
                    'url' => $url,
                    'files_count' => count($images),
                ]);
                return array_values($images);
            }
        } catch (\RuntimeException $e) {
            // If "no video formats" error for image post, try without format selector
            if (str_contains($e->getMessage(), 'video format topilmadi')) {
                Log::debug('No video formats error caught, trying without format selector', [
                    'url' => $url,
                ]);
                
                // Try again without format selector
                $argumentsNoFormat = array_filter($arguments, function($arg) {
                    return $arg !== '--format' && $arg !== 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best';
                });
                $argumentsNoFormat = array_values($argumentsNoFormat);
                $argumentsNoFormat[] = $url;
                
                try {
                    $downloadedFiles = $this->executeDownload($argumentsNoFormat, $url, $outputDir);
                    
                    // Filter to only return image files
                    $images = array_filter($downloadedFiles, function($file) {
                        return $this->isImage($file);
                    });
                    
                    if (!empty($images)) {
                        Log::info('Instagram image downloaded via direct method without format selector', [
                            'url' => $url,
                            'files_count' => count($images),
                        ]);
                        return array_values($images);
                    }
                } catch (\Exception $e2) {
                    Log::debug('Direct download without format selector also failed', [
                        'url' => $url,
                        'error' => $e2->getMessage(),
                    ]);
                    throw $e2;
                }
            }
            
            Log::debug('Direct download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::debug('Direct download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        
        throw new \RuntimeException('No image files were downloaded with direct method');
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
        // Try with first available cookie
        $cookiesPaths = $this->getInstagramCookiesPaths();
        $cookiesPath = !empty($cookiesPaths) ? $cookiesPaths[0] : null;
        
        // Minimal arguments - no format selector, let yt-dlp decide
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--output', $outputDir . '/%(title)s.%(ext)s',
        ];
        
        // Add cookies if available
        if ($cookiesPath && file_exists($cookiesPath)) {
            $arguments[] = '--cookies';
            $arguments[] = $cookiesPath;
        }
        
        $arguments[] = $url;

        try {
            $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
            
            // Filter to only return image files
            $images = array_filter($downloadedFiles, function($file) {
                return $this->isImage($file);
            });
            
            if (!empty($images)) {
                Log::info('Instagram image downloaded with minimal method', [
                    'url' => $url,
                    'files_count' => count($images),
                ]);
                return array_values($images);
            }
        } catch (\Exception $e) {
            Log::debug('Minimal method failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        
        throw new \RuntimeException('No image files were downloaded with minimal method');
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
        // Ensure cookie path is absolute
        if (!str_starts_with($cookiesPath, '/')) {
            $cookiesPath = base_path($cookiesPath);
        }
        $cookiesPath = realpath($cookiesPath) ?: $cookiesPath;
        
        // Verify cookie file exists and is readable
        if (!file_exists($cookiesPath) || !is_readable($cookiesPath)) {
            throw new \RuntimeException("Cookie file not found or not readable: {$cookiesPath}");
        }
        
        Log::debug('Using cookie file for Instagram video download', [
            'url' => $url,
            'cookie_path' => $cookiesPath,
            'cookie_exists' => file_exists($cookiesPath),
            'cookie_size' => file_exists($cookiesPath) ? filesize($cookiesPath) : 0,
        ]);
        
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

        Log::info('Downloading Instagram video with cookies', [
            'url' => $url,
            'cookie_path' => basename($cookiesPath),
            'output_dir' => $outputDir,
        ]);

        $downloadedFiles = $this->executeDownload($arguments, $url, $outputDir);
        
        Log::info('Instagram video download completed', [
            'url' => $url,
            'downloaded_files_count' => count($downloadedFiles),
            'downloaded_files' => array_map('basename', $downloadedFiles),
        ]);
        
        // Filter to only return video files (exclude images)
        $videos = array_filter($downloadedFiles, function($file) {
            return $this->isVideo($file);
        });
        
        if (empty($videos)) {
            Log::warning('No video files found after Instagram video download', [
                'url' => $url,
                'downloaded_files' => array_map('basename', $downloadedFiles),
                'downloaded_files_extensions' => array_map(function($file) {
                    return pathinfo($file, PATHINFO_EXTENSION);
                }, $downloadedFiles),
            ]);
        } else {
            Log::info('Instagram video files found', [
                'url' => $url,
                'videos_count' => count($videos),
                'video_files' => array_map('basename', $videos),
            ]);
        }
        
        return array_values($videos);
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
        // Try with multiple cookies (rotation for reliability)
        $cookiesPaths = $this->getInstagramCookiesPaths();
        
        foreach ($cookiesPaths as $index => $cookiesPath) {
            if ($cookiesPath && file_exists($cookiesPath) && filesize($cookiesPath) > 100) {
                try {
                    Log::info('Trying Instagram download with cookie file', [
                        'url' => $url,
                        'cookie_index' => $index + 1,
                        'cookie_path' => basename($cookiesPath),
                    ]);
                    
                    $result = $this->downloadWithCookies($url, $outputDir, $cookiesPath);
                    
                    // If we got files, return them
                    if (!empty($result)) {
                        Log::info('Instagram downloaded successfully with cookies', [
                            'url' => $url,
                            'cookie_index' => $index + 1,
                            'cookie_path' => basename($cookiesPath),
                        ]);
                        return $result;
                    }
                } catch (\Exception $e) {
                    Log::warning('Instagram download with cookie failed, trying next', [
                        'url' => $url,
                        'cookie_index' => $index + 1,
                        'cookie_path' => basename($cookiesPath),
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to next cookie
                    continue;
                }
            }
        }
        
        if (empty($cookiesPaths)) {
            Log::warning('Instagram cookies files not available', [
                'url' => $url,
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
                    // Check if this is an image post by URL pattern
                    $isImagePost = str_contains($url, '/p/') && !str_contains($url, '/reel/') && !str_contains($url, '/tv/');
                    
                if ($isImagePost) {
                    // This is likely an image post, "No video formats" is expected
                    // Try to find downloaded files anyway (might have downloaded images)
                    Log::info('Instagram image post detected - no video formats (expected), checking for downloaded images', [
                        'url' => $url,
                        'error' => $errorOutput,
                    ]);
                    
                    // Check if any files were downloaded despite the error
                    $downloadedFiles = $this->findDownloadedFiles($outputDir);
                    if (!empty($downloadedFiles)) {
                        // Filter to only return image files
                        $images = array_filter($downloadedFiles, function($file) {
                            return $this->isImage($file);
                        });
                        
                        if (!empty($images)) {
                            Log::info('Instagram images found despite "no video formats" error', [
                                'url' => $url,
                                'files_count' => count($images),
                            ]);
                            return array_values($images);
                        }
                    }
                    
                    // If no files found, throw exception so caller can try another method
                    $specificError = 'Instagram rasm post - video format topilmadi (kutilgan), rasm format qidirilmoqda';
                    throw new \RuntimeException($specificError);
                    } else {
                        // Might be a video post, but no formats found
                        $specificError = 'Instagram post - video format topilmadi';
                    }
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
    
    /**
     * Check if URL is an image URL
     *
     * @param string $url
     * @return bool
     */
    private function isImageUrl(string $url): bool
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
        $urlLower = strtolower($url);
        
        foreach ($imageExtensions as $ext) {
            if (str_contains($urlLower, '.' . $ext) || str_contains($urlLower, '/' . $ext . '?')) {
                return true;
            }
        }
        
        // Check if URL contains image-related paths
        if (preg_match('/\.(jpg|jpeg|png|webp|gif|bmp)(\?|$)/i', $url)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Download image from direct URL
     *
     * @param string $imageUrl
     * @param string $outputDir
     * @return string|null Downloaded file path or null on failure
     */
    private function downloadImageFromUrl(string $imageUrl, string $outputDir): ?string
    {
        try {
            // Generate unique filename
            $extension = 'jpg';
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|bmp)(\?|$)/i', $imageUrl, $matches)) {
                $extension = strtolower($matches[1]);
                if ($extension === 'jpeg') {
                    $extension = 'jpg';
                }
            }
            
            $filename = uniqid('img_', true) . '.' . $extension;
            $filePath = $outputDir . '/' . $filename;
            
            // Download using file_get_contents with context
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                        'Referer: https://www.instagram.com/',
                    ],
                    'timeout' => 30,
                    'follow_location' => true,
                    'max_redirects' => 5,
                ],
            ]);
            
            $imageData = @file_get_contents($imageUrl, false, $context);
            
            if ($imageData === false) {
                Log::warning('Failed to download image from URL', [
                    'url' => $imageUrl,
                ]);
                return null;
            }
            
            // Verify it's actually an image
            $imageInfo = @getimagesizefromstring($imageData);
            if ($imageInfo === false) {
                Log::warning('Downloaded data is not a valid image', [
                    'url' => $imageUrl,
                ]);
                return null;
            }
            
            // Save file
            if (file_put_contents($filePath, $imageData) === false) {
                Log::warning('Failed to save downloaded image', [
                    'url' => $imageUrl,
                    'file_path' => $filePath,
                ]);
                return null;
            }
            
            Log::debug('Image downloaded from URL', [
                'url' => $imageUrl,
                'file_path' => $filePath,
                'size' => filesize($filePath),
            ]);
            
            return $filePath;
        } catch (\Exception $e) {
            Log::error('Exception downloading image from URL', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Download Instagram image by parsing HTML page directly
     * This is a last resort method when yt-dlp fails
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadInstagramImageFromHtml(string $url, string $outputDir): array
    {
        try {
            Log::info('Attempting HTML parsing method for Instagram image', [
                'url' => $url,
                'output_dir' => $outputDir,
            ]);
            
            // Try with cookies if available
            $cookiesPaths = $this->getInstagramCookiesPaths();
            $cookiesPath = !empty($cookiesPaths) ? $cookiesPaths[0] : null;
            
            // Prepare headers
            $headers = [
                'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'Referer: https://www.instagram.com/',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ];
            
            // Build context options
            $contextOptions = [
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 30,
                    'follow_location' => true,
                    'max_redirects' => 5,
                ],
            ];
            
            // Add cookies if available
            if ($cookiesPath && file_exists($cookiesPath)) {
                // Parse cookies and add to header
                $cookiesContent = @file_get_contents($cookiesPath);
                if ($cookiesContent) {
                    // Extract cookies from Netscape format
                    $cookieLines = explode("\n", $cookiesContent);
                    $cookiePairs = [];
                    foreach ($cookieLines as $line) {
                        $line = trim($line);
                        if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '#')) {
                            continue;
                        }
                        // Netscape format: domain, flag, path, secure, expiration, name, value
                        $parts = explode("\t", $line);
                        if (count($parts) >= 7 && str_contains($parts[0], 'instagram.com')) {
                            $cookiePairs[] = $parts[5] . '=' . $parts[6];
                        }
                    }
                    
                    if (!empty($cookiePairs)) {
                        $contextOptions['http']['header'] .= "\r\nCookie: " . implode('; ', array_unique($cookiePairs));
                    }
                }
            }
            
            $context = stream_context_create($contextOptions);
            
            // Fetch HTML - try file_get_contents first, then curl as fallback
            $html = @file_get_contents($url, false, $context);
            
            // If file_get_contents failed, try with curl
            if ($html === false || empty($html)) {
                Log::debug('file_get_contents failed, trying curl', ['url' => $url]);
                
                if (function_exists('curl_init')) {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1');
                    
                    // Add cookies if available
                    if ($cookiesPath && file_exists($cookiesPath)) {
                        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiesPath);
                        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiesPath);
                    }
                    
                    $html = @curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    
                    if ($html === false || empty($html) || $httpCode !== 200) {
                        Log::error('Failed to fetch Instagram HTML with curl', [
                            'url' => $url,
                            'http_code' => $httpCode,
                            'curl_error' => $curlError,
                        ]);
                        throw new \RuntimeException('Failed to fetch Instagram page HTML: ' . ($curlError ?: 'HTTP ' . $httpCode));
                    }
                    
                    Log::info('Instagram HTML fetched successfully with curl', [
                        'url' => $url,
                        'http_code' => $httpCode,
                        'html_length' => strlen($html),
                        'method' => 'curl',
                    ]);
                } else {
                    throw new \RuntimeException('Failed to fetch Instagram page HTML: file_get_contents failed and curl not available');
                }
            }
            
            Log::info('Instagram HTML fetched successfully', [
                'url' => $url,
                'html_length' => strlen($html),
                'method' => 'file_get_contents',
            ]);
            
            // Extract image URLs from HTML
            // Instagram embeds image URLs in JSON-LD or meta tags or script tags
            $imageUrls = [];
            
            // Method 1: Extract from JSON-LD structured data
            if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $jsonLdMatches)) {
                foreach ($jsonLdMatches[1] as $jsonLdContent) {
                    $jsonData = json_decode(trim($jsonLdContent), true);
                    if ($jsonData && is_array($jsonData)) {
                        // Look for image URLs in JSON-LD
                        if (!empty($jsonData['image']) && is_string($jsonData['image'])) {
                            $imageUrls[] = $jsonData['image'];
                        } elseif (!empty($jsonData['image']) && is_array($jsonData['image'])) {
                            if (isset($jsonData['image']['url'])) {
                                $imageUrls[] = $jsonData['image']['url'];
                            }
                        }
                    }
                }
            }
            
            // Method 2: Extract from meta property="og:image"
            if (preg_match_all('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $ogMatches)) {
                $imageUrls = array_merge($imageUrls, $ogMatches[1]);
            }
            
            // Method 3: Extract from meta name="twitter:image"
            if (preg_match_all('/<meta[^>]*name=["\']twitter:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $twitterMatches)) {
                $imageUrls = array_merge($imageUrls, $twitterMatches[1]);
            }
            
            // Method 4: Extract from script tags with window._sharedData
            if (preg_match('/window\._sharedData\s*=\s*({.+?});/is', $html, $sharedDataMatches)) {
                $sharedData = json_decode($sharedDataMatches[1], true);
                if ($sharedData && is_array($sharedData)) {
                    // Navigate through sharedData structure to find image URLs
                    $imagePaths = [
                        ['entry_data', 'PostPage', 0, 'graphql', 'shortcode_media', 'display_url'],
                        ['entry_data', 'PostPage', 0, 'graphql', 'shortcode_media', 'thumbnail_src'],
                        ['entry_data', 'PostPage', 0, 'graphql', 'shortcode_media', 'edge_sidecar_to_children', 'edges', 0, 'node', 'display_url'],
                    ];
                    
                    foreach ($imagePaths as $path) {
                        $value = $sharedData;
                        foreach ($path as $key) {
                            if (is_array($value) && isset($value[$key])) {
                                $value = $value[$key];
                            } else {
                                $value = null;
                                break;
                            }
                        }
                        if ($value && is_string($value)) {
                            $imageUrls[] = $value;
                        }
                    }
                }
            }
            
            // Method 5: Extract from window.__additionalDataLoaded or similar patterns
            if (preg_match_all('/"display_url":\s*"([^"]+)"/i', $html, $displayUrlMatches)) {
                $imageUrls = array_merge($imageUrls, $displayUrlMatches[1]);
            }
            
            // Method 6: Extract from thumbnail_url pattern
            if (preg_match_all('/"thumbnail_url":\s*"([^"]+)"/i', $html, $thumbMatches)) {
                $imageUrls = array_merge($imageUrls, $thumbMatches[1]);
            }
            
            // Method 7: Extract from srcset attributes (Instagram uses these)
            if (preg_match_all('/srcset=["\']([^"\']+)["\']/i', $html, $srcsetMatches)) {
                foreach ($srcsetMatches[1] as $srcset) {
                    // Parse srcset (format: url width, url width, ...)
                    if (preg_match_all('/(https?:\/\/[^\s,]+)/i', $srcset, $urlMatches)) {
                        $imageUrls = array_merge($imageUrls, $urlMatches[1]);
                    }
                }
            }
            
            // Method 8: Extract from any Instagram CDN URLs directly in HTML
            if (preg_match_all('/(https?:\/\/[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)[^"\'\s]*\.(jpg|jpeg|png|webp))/i', $html, $cdnMatches)) {
                $imageUrls = array_merge($imageUrls, $cdnMatches[1]);
            }
            
            // Method 9: Extract from data-src attributes (lazy loading)
            if (preg_match_all('/data-src=["\']([^"\']+)["\']/i', $html, $dataSrcMatches)) {
                foreach ($dataSrcMatches[1] as $dataSrc) {
                    if (str_contains($dataSrc, 'instagram.com') || str_contains($dataSrc, 'cdninstagram.com') || 
                        str_contains($dataSrc, 'fbcdn.net') || preg_match('/\.(jpg|jpeg|png|webp)/i', $dataSrc)) {
                        $imageUrls[] = $dataSrc;
                    }
                }
            }
            
            // Method 10: Extract from script tags with JSON (comprehensive regex)
            if (preg_match_all('/<script[^>]*>.*?"(display_url|thumbnail_url|src)":\s*"([^"]+)"[^<]*<\/script>/is', $html, $scriptMatches)) {
                foreach ($scriptMatches[2] as $scriptUrl) {
                    if (filter_var($scriptUrl, FILTER_VALIDATE_URL)) {
                        $imageUrls[] = $scriptUrl;
                    }
                }
            }
            
            // Clean and filter URLs - decode HTML entities only (keep original URL with parameters)
            $imageUrls = array_map(function($url) {
                // Decode HTML entities (e.g., &amp; -> &)
                // Keep all URL parameters as Instagram needs them for proper image access
                return html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $imageUrls);
            
            $imageUrls = array_unique($imageUrls);
            $imageUrls = array_filter($imageUrls, function($url) {
                // Remove query parameters for validation, but keep them for download
                $cleanUrl = strtok($url, '?');
                
                // Only keep valid image URLs
                if (!filter_var($cleanUrl, FILTER_VALIDATE_URL)) {
                    return false;
                }
                
                // Reject static/icon/logo URLs (these are not actual post images)
                if (str_contains($url, '/rsrc.php/') || 
                    str_contains($url, '/static.cdninstagram.com') ||
                    str_contains($url, 'icon') || 
                    str_contains($url, 'logo') ||
                    str_contains($url, 'favicon')) {
                    return false;
                }
                
                // Accept URLs from Instagram CDN domains - prioritize actual image URLs
                if (str_contains($url, 'scontent-') || str_contains($url, 'cdninstagram.com')) {
                    // Must contain image path patterns or extensions
                    return (str_contains($url, '/scontent/') || 
                            str_contains($url, '/t51.') || str_contains($url, '/t52.') || 
                            str_contains($url, '/t64.') || str_contains($url, '/v/t') ||
                            preg_match('/\.(jpg|jpeg|png|webp)/i', $url)) &&
                           // Must NOT be icon/logo/static files
                           !str_contains($url, '/rsrc.php/') &&
                           !str_contains($url, '/static/');
                }
                
                // Accept other Instagram domains only if they have image extensions
                if (str_contains($url, 'instagram.com')) {
                    return preg_match('/\.(jpg|jpeg|png|webp)/i', $url) &&
                           !str_contains($url, '/rsrc.php/') &&
                           !str_contains($url, '/static/');
                }
                
                // For other domains, require file extension
                return preg_match('/\.(jpg|jpeg|png|webp|gif|bmp)/i', $url);
            });
            
            Log::info('Image URLs extracted from HTML', [
                'url' => $url,
                'image_urls_count' => count($imageUrls),
                'image_urls_preview' => array_slice($imageUrls, 0, 3), // First 3 for logging
            ]);
            
            if (empty($imageUrls)) {
                throw new \RuntimeException('No image URLs found in HTML');
            }
            
            // Download images from extracted URLs - prioritize scontent URLs (actual images)
            // Sort URLs to prioritize scontent-* URLs first (these are actual post images)
            usort($imageUrls, function($a, $b) {
                $aScore = (str_contains($a, 'scontent-') ? 10 : 0) + (str_contains($a, '/scontent/') ? 5 : 0);
                $bScore = (str_contains($b, 'scontent-') ? 10 : 0) + (str_contains($b, '/scontent/') ? 5 : 0);
                return $bScore <=> $aScore; // Descending order
            });
            
            $downloadedFiles = [];
            $downloadedCount = 0;
            $maxImages = 10; // Limit to 10 images
            
            foreach ($imageUrls as $imageUrl) {
                if ($downloadedCount >= $maxImages) {
                    break;
                }
                
                try {
                    Log::debug('Attempting to download image from extracted URL', [
                        'url' => substr($imageUrl, 0, 100) . '...', // Truncate for logging
                        'attempt' => $downloadedCount + 1,
                    ]);
                    
                    $imagePath = $this->downloadImageFromUrl($imageUrl, $outputDir);
                    if ($imagePath && file_exists($imagePath)) {
                        // Verify it's actually an image file
                        $imageInfo = @getimagesize($imagePath);
                        if ($imageInfo !== false && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
                            $downloadedFiles[] = $imagePath;
                            $downloadedCount++;
                            Log::info('Image successfully downloaded from extracted URL', [
                                'url' => substr($imageUrl, 0, 80) . '...',
                                'file_path' => basename($imagePath),
                                'size' => filesize($imagePath),
                                'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
                            ]);
                        } else {
                            Log::debug('Downloaded file is not a valid image, removing', [
                                'file_path' => $imagePath,
                            ]);
                            @unlink($imagePath);
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('Failed to download image from extracted URL', [
                        'url' => substr($imageUrl, 0, 100) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            if (!empty($downloadedFiles)) {
                Log::info('Instagram images downloaded via HTML parsing', [
                    'url' => $url,
                    'files_count' => count($downloadedFiles),
                ]);
                return array_values($downloadedFiles);
            }
            
            throw new \RuntimeException('Failed to download images from extracted URLs');
        } catch (\Exception $e) {
            Log::error('HTML parsing method failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
