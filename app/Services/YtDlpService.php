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
                                $imagePath = $this->downloadImageFromUrl($imageUrl, $outputDir, $url);
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
                        // Prioritize full-size images, avoid thumbnails
                        '--format', 'best[height>=1080][ext=jpg]/best[height>=1080][ext=jpeg]/best[height>=1080][ext=png]/best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
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
            // Prioritize full-size images (height >= 1080), avoid thumbnails
            'best[height>=1080][ext=jpg]/best[height>=1080][ext=jpeg]/best[height>=1080][ext=png]/best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
            'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best',
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
    private function downloadImageFromUrl(string $imageUrl, string $outputDir, ?string $originalPostUrl = null): ?string
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
            
            // Determine Referer: use original post URL if available, otherwise default to Instagram homepage
            // CRITICAL: Instagram CDN requires correct Referer to avoid 403 errors
            $referer = 'https://www.instagram.com/';
            if ($originalPostUrl && filter_var($originalPostUrl, FILTER_VALIDATE_URL)) {
                // Use the original post URL as Referer (Instagram CDN expects this)
                $referer = $originalPostUrl;
            } elseif (str_contains($imageUrl, 'instagram.com')) {
                // Extract post ID from image URL if possible
                // Instagram CDN URLs sometimes contain post references
                $referer = 'https://www.instagram.com/';
            }
            
            // Prepare headers with cookies for Instagram CDN
            // IMPORTANT: Use correct Referer to match Instagram CDN expectations
            $headers = [
                'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'Referer: ' . $referer,
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: identity', // Disable compression to avoid issues
                'Origin: https://www.instagram.com',
            ];
            
            // Add cookies if available (for Instagram CDN)
            $cookiesPaths = $this->getInstagramCookiesPaths();
            $cookiesPath = !empty($cookiesPaths) ? $cookiesPaths[0] : null;
            
            $cookieString = '';
            if ($cookiesPath && file_exists($cookiesPath) && str_contains($imageUrl, 'instagram.com')) {
                // Parse cookies from Netscape format
                $cookiesContent = @file_get_contents($cookiesPath);
                if ($cookiesContent) {
                    $cookieLines = explode("\n", $cookiesContent);
                    $cookiePairs = [];
                    foreach ($cookieLines as $line) {
                        $line = trim($line);
                        if (empty($line) || str_starts_with($line, '#')) {
                            continue;
                        }
                        // Netscape format: domain, flag, path, secure, expiration, name, value
                        $parts = explode("\t", $line);
                        if (count($parts) >= 7 && str_contains($parts[0], 'instagram.com')) {
                            $cookiePairs[] = $parts[5] . '=' . $parts[6];
                        }
                    }
                    if (!empty($cookiePairs)) {
                        $cookieString = implode('; ', $cookiePairs);
                        $headers[] = 'Cookie: ' . $cookieString;
                        Log::debug('Added cookies to image download request', [
                            'url' => substr($imageUrl, 0, 80) . '...',
                            'cookies_count' => count($cookiePairs),
                            'referer' => $referer,
                        ]);
                    }
                }
            }
            
            // Download using file_get_contents with context
            // IMPORTANT: Use proper headers to avoid 403 errors from Instagram CDN
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 30,
                    'follow_location' => true,
                    'max_redirects' => 5,
                    'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                ],
            ]);
            
            $imageData = @file_get_contents($imageUrl, false, $context);
            
            // If file_get_contents fails, try with curl as fallback
            if ($imageData === false) {
                Log::debug('file_get_contents failed, trying curl', [
                    'url' => substr($imageUrl, 0, 80) . '...',
                    'referer' => $referer,
                    'has_cookie' => !empty($cookieString),
                ]);
                
                $ch = curl_init($imageUrl);
                
                // Build curl headers without duplicates
                // CRITICAL: Instagram CDN requires correct Referer and headers to avoid 403 errors
                $curlHeaders = [
                    'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                    'Referer: ' . $referer,
                    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Accept-Encoding: identity', // Disable compression to avoid issues
                    'Origin: https://www.instagram.com',
                ];
                
                // Add cookies if available (CRITICAL for Instagram CDN)
                if (!empty($cookieString)) {
                    $curlHeaders[] = 'Cookie: ' . $cookieString;
                }
                
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                    CURLOPT_HTTPHEADER => $curlHeaders,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]);
                
                // Add cookies via CURLOPT_COOKIE if available (for Instagram CDN)
                // CRITICAL: Instagram CDN often requires valid cookies to avoid 403 errors
                if (!empty($cookieString)) {
                    curl_setopt($ch, CURLOPT_COOKIE, $cookieString);
                    Log::debug('Using cookie string for curl (Instagram CDN)', [
                        'url' => substr($imageUrl, 0, 80) . '...',
                        'cookie_length' => strlen($cookieString),
                        'referer' => $referer,
                    ]);
                } elseif ($cookiesPath && file_exists($cookiesPath) && str_contains($imageUrl, 'instagram.com')) {
                    // Fallback: try COOKIEFILE (may not work with Netscape format, but worth trying)
                    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiesPath);
                    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiesPath);
                    Log::debug('Using cookie file for curl (Instagram CDN fallback)', [
                        'url' => substr($imageUrl, 0, 80) . '...',
                        'cookie_path' => $cookiesPath,
                        'referer' => $referer,
                    ]);
                }
                
                $imageData = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $responseSize = $imageData ? strlen($imageData) : 0;
                curl_close($ch);
                
                Log::info('Curl download attempt result', [
                    'url' => substr($imageUrl, 0, 80) . '...',
                    'http_code' => $httpCode,
                    'response_size' => $responseSize,
                    'curl_error' => $curlError,
                    'referer' => $referer,
                    'has_cookie' => !empty($cookieString),
                ]);
                
                if ($imageData === false || $httpCode !== 200) {
                    Log::warning('Failed to download image from URL (both file_get_contents and curl failed)', [
                        'url' => $imageUrl,
                        'http_code' => $httpCode,
                        'curl_error' => $curlError,
                        'referer' => $referer,
                        'has_cookie' => !empty($cookieString),
                        'response_size' => $responseSize,
                    ]);
                    return null;
                }
            }
            
            // Verify it's actually an image
            $imageInfo = @getimagesizefromstring($imageData);
            if ($imageInfo === false) {
                Log::warning('Downloaded data is not a valid image', [
                    'url' => $imageUrl,
                    'data_length' => strlen($imageData),
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
            
            Log::info('Image downloaded from URL successfully', [
                'url' => substr($imageUrl, 0, 80) . '...',
                'file_path' => basename($filePath),
                'size' => filesize($filePath),
                'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
            ]);
            
            return $filePath;
        } catch (\Exception $e) {
            Log::error('Exception downloading image from URL', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
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
            // CRITICAL: Track display_url and candidates[0] separately (they're 99%+ original, no crop)
            $imageUrls = [];
            $premiumDisplayUrls = []; // display_url from JSON (99%+ original, NO crop)
            $premiumCandidateUrls = []; // candidates[0] from image_versions2 (99%+ original, NO crop)
            
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
            
            // Method 2: Extract from meta property="og:image" (usually contains actual post image)
            if (preg_match_all('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $ogMatches)) {
                foreach ($ogMatches[1] as $ogUrl) {
                    $decodedOgUrl = html_entity_decode($ogUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    
                    // CRITICAL: Skip rsrc.php/static URLs (icons/logos, NOT post images)
                    if (str_contains($decodedOgUrl, '/rsrc.php/') || 
                        str_contains($decodedOgUrl, 'static.cdninstagram.com') ||
                        str_contains($decodedOgUrl, 'icon') ||
                        str_contains($decodedOgUrl, 'logo')) {
                        Log::debug('Skipping og:image URL (icon/logo, not post image)', [
                            'url' => substr($decodedOgUrl, 0, 80) . '...',
                        ]);
                        continue;
                    }
                    
                    // og:image usually contains actual post image - add to premium if valid
                    if (filter_var($decodedOgUrl, FILTER_VALIDATE_URL)) {
                        // Prioritize scontent- URLs (Instagram CDN for post images)
                        if (str_contains($decodedOgUrl, 'scontent-')) {
                            $premiumDisplayUrls[] = $decodedOgUrl;
                            Log::info('Found og:image URL (scontent-, premium quality)', [
                                'url' => substr($decodedOgUrl, 0, 80) . '...',
                            ]);
                        } else {
                            $imageUrls[] = $decodedOgUrl;
                        }
                    }
                }
            }
            
            // Method 3: Extract from meta name="twitter:image"
            if (preg_match_all('/<meta[^>]*name=["\']twitter:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $twitterMatches)) {
                foreach ($twitterMatches[1] as $twitterUrl) {
                    $decodedTwitterUrl = html_entity_decode($twitterUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    
                    // CRITICAL: Skip rsrc.php/static URLs (icons/logos, NOT post images)
                    if (str_contains($decodedTwitterUrl, '/rsrc.php/') || 
                        str_contains($decodedTwitterUrl, 'static.cdninstagram.com') ||
                        str_contains($decodedTwitterUrl, 'icon') ||
                        str_contains($decodedTwitterUrl, 'logo')) {
                        continue;
                    }
                    
                    if (filter_var($decodedTwitterUrl, FILTER_VALIDATE_URL)) {
                        $imageUrls[] = $decodedTwitterUrl;
                    }
                }
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
                        if ($value && is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                            $decodedUrl = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            // display_url from _sharedData is premium (99%+ original, no crop)
                            if (str_contains($path[array_key_last($path)], 'display_url')) {
                                $premiumDisplayUrls[] = $decodedUrl;
                                Log::info('Found display_url from _sharedData (99%+ original, NO crop)', [
                                    'url' => substr($decodedUrl, 0, 80) . '...',
                                ]);
                            } else {
                                $imageUrls[] = $decodedUrl;
                            }
                        }
                    }
                }
            }
            
            // Method 5: Extract from window.__additionalDataLoaded or similar patterns
            // display_url is usually the full-size image URL (highest priority, 99%+ original, NO crop)
            // Try multiple regex patterns to find display_url (Instagram uses different formats)
            $displayUrlPatterns = [
                '/"display_url":\s*"([^"]+)"/i', // Standard format
                '/\'display_url\':\s*\'([^\']+)\'/i', // Single quotes
                '/display_url["\']?\s*:\s*["\']?([^"\'\s,}]+)/i', // Flexible quotes
                '/display_url["\']?\s*:\s*["\']?(https?:\/\/[^"\'\s,}]+)/i', // With http
            ];
            
            foreach ($displayUrlPatterns as $pattern) {
                if (preg_match_all($pattern, $html, $displayUrlMatches)) {
                    foreach ($displayUrlMatches[1] as $displayUrl) {
                        // Decode HTML entities
                        $displayUrl = html_entity_decode($displayUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        // Remove trailing characters that might be part of JSON
                        $displayUrl = rtrim($displayUrl, ',}');
                        if (filter_var($displayUrl, FILTER_VALIDATE_URL) && !str_contains($displayUrl, 'stp=')) {
                            // display_url is premium - 99%+ original, no crop (and no stp=)
                            $premiumDisplayUrls[] = $displayUrl;
                            Log::info('Found display_url from HTML regex (99%+ original, NO crop, NO stp=)', [
                                'url' => substr($displayUrl, 0, 80) . '...',
                                'pattern' => substr($pattern, 0, 40) . '...',
                            ]);
                        }
                    }
                }
            }
            
            // Method 5b: Extract from window.__additionalDataLoaded JSON structure
            // This contains the highest quality image URLs
            // Try multiple patterns for __additionalDataLoaded (Instagram uses different formats)
            $additionalDataPatterns = [
                '/window\.__additionalDataLoaded\s*\([^,]+,\s*({.+?})\);/is', // Standard format
                '/window\.__additionalDataLoaded\s*\([^,]+\s*,\s*({.+?})\);/is', // With spaces
                '/__additionalDataLoaded\s*\([^,]+\s*,\s*({.+?})\);/is', // Without window.
                '/additionalDataLoaded\s*\([^,]+\s*,\s*({.+?})\);/is', // Without __
            ];
            
            $additionalData = null;
            foreach ($additionalDataPatterns as $pattern) {
                if (preg_match($pattern, $html, $additionalDataMatches)) {
                    $additionalData = json_decode($additionalDataMatches[1], true);
                    if ($additionalData && is_array($additionalData)) {
                        break; // Found valid JSON
                    }
                }
            }
            
            if ($additionalData && is_array($additionalData)) {
                if ($additionalData && is_array($additionalData)) {
                    // Navigate through additionalData to find display_url and image_versions2
                    $paths = [
                        ['graphql', 'shortcode_media', 'display_url'],
                        ['items', 0, 'display_url'],
                        ['items', 0, 'image_versions2', 'candidates', 0, 'url'],
                    ];
                    
                    // Also extract from image_versions2.candidates array (sorted by quality, first is best)
                    $candidatePaths = [
                        ['items', 0, 'image_versions2', 'candidates'],
                        ['graphql', 'shortcode_media', 'display_resources'],
                    ];
                    
                    foreach ($candidatePaths as $candidatePath) {
                        $candidates = $additionalData;
                        foreach ($candidatePath as $key) {
                            if (is_array($candidates) && isset($candidates[$key])) {
                                $candidates = $candidates[$key];
                            } else {
                                $candidates = null;
                                break;
                            }
                        }
                        
                        // candidates is an array sorted by quality (first = best quality, 99%+ original, NO crop)
                        if (is_array($candidates) && !empty($candidates)) {
                            foreach ($candidates as $index => $candidate) {
                                if (is_array($candidate) && isset($candidate['url'])) {
                                    $candidateUrl = html_entity_decode($candidate['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                    if (filter_var($candidateUrl, FILTER_VALIDATE_URL)) {
                                        // First candidate (index 0) is premium - 99%+ original, no crop
                                        if ($index === 0) {
                                            $premiumCandidateUrls[] = $candidateUrl;
                                            Log::info('Found candidates[0] from image_versions2 (99%+ original, NO crop)', [
                                                'url' => substr($candidateUrl, 0, 80) . '...',
                                                'width' => $candidate['width'] ?? 'unknown',
                                                'height' => $candidate['height'] ?? 'unknown',
                                            ]);
                                        } else {
                                            // Other candidates - still good but not premium
                                            $imageUrls[] = $candidateUrl;
                                            Log::debug('Found image URL from candidates array', [
                                                'url' => substr($candidateUrl, 0, 80) . '...',
                                                'index' => $index,
                                                'width' => $candidate['width'] ?? 'unknown',
                                                'height' => $candidate['height'] ?? 'unknown',
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    // Extract display_url from simple paths
                    foreach ($paths as $path) {
                        $value = $additionalData;
                        foreach ($path as $key) {
                            if (is_array($value) && isset($value[$key])) {
                                $value = $value[$key];
                            } else {
                                $value = null;
                                break;
                            }
                        }
                        if ($value && is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                            $decodedUrl = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            // display_url from __additionalDataLoaded is premium
                            if (str_contains($path[array_key_last($path)], 'display_url')) {
                                $premiumDisplayUrls[] = $decodedUrl;
                                Log::info('Found display_url from __additionalDataLoaded (99%+ original, NO crop)', [
                                    'url' => substr($decodedUrl, 0, 80) . '...',
                                ]);
                            } elseif ($path[array_key_last($path)] === 'url' && str_contains($path[array_key_last($path) - 2] ?? '', 'candidates')) {
                                // candidates[0].url is premium
                                $premiumCandidateUrls[] = $decodedUrl;
                                Log::info('Found candidates[0].url from __additionalDataLoaded (99%+ original, NO crop)', [
                                    'url' => substr($decodedUrl, 0, 80) . '...',
                                ]);
                            } else {
                                $imageUrls[] = $decodedUrl;
                                Log::debug('Found image URL from __additionalDataLoaded', [
                                    'url' => substr($decodedUrl, 0, 80) . '...',
                                ]);
                            }
                        }
                    }
                }
            }
            
            // Method 5c: Extract from window._sharedData more thoroughly (full JSON structure)
            // Try to find image_versions2.candidates in edge_sidecar_to_children (carousel)
            if (preg_match('/window\._sharedData\s*=\s*({.+?});/is', $html, $sharedDataMatches)) {
                $sharedData = json_decode($sharedDataMatches[1], true);
                if ($sharedData && is_array($sharedData)) {
                    // Look for display_resources (sorted by quality, first is best)
                    $resourcePaths = [
                        ['entry_data', 'PostPage', 0, 'graphql', 'shortcode_media', 'display_resources'],
                        ['entry_data', 'PostPage', 0, 'graphql', 'shortcode_media', 'edge_sidecar_to_children', 'edges'],
                    ];
                    
                    foreach ($resourcePaths as $resourcePath) {
                        $resources = $sharedData;
                        foreach ($resourcePath as $key) {
                            if (is_array($resources) && isset($resources[$key])) {
                                $resources = $resources[$key];
                            } else {
                                $resources = null;
                                break;
                            }
                        }
                        
                        if (is_array($resources)) {
                            // If it's edges array (carousel), iterate through nodes
                            if (isset($resources[0]) && is_array($resources[0]) && isset($resources[0]['node'])) {
                                foreach ($resources as $edge) {
                                    $node = $edge['node'] ?? null;
                                    if ($node && is_array($node)) {
                                        // Get display_url or display_resources (carousel posts)
                                        if (isset($node['display_url']) && filter_var($node['display_url'], FILTER_VALIDATE_URL)) {
                                            $decodedUrl = html_entity_decode($node['display_url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                            // display_url from carousel is premium (99%+ original, no crop)
                                            $premiumDisplayUrls[] = $decodedUrl;
                                            Log::info('Found display_url from carousel _sharedData (99%+ original, NO crop)', [
                                                'url' => substr($decodedUrl, 0, 80) . '...',
                                            ]);
                                        }
                                        if (isset($node['display_resources']) && is_array($node['display_resources'])) {
                                            foreach ($node['display_resources'] as $index => $resource) {
                                                if (isset($resource['src']) && filter_var($resource['src'], FILTER_VALIDATE_URL)) {
                                                    $decodedUrl = html_entity_decode($resource['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                                    // First resource (index 0) is premium (99%+ original, no crop)
                                                    if ($index === 0) {
                                                        $premiumCandidateUrls[] = $decodedUrl;
                                                        Log::info('Found display_resources[0] from carousel _sharedData (99%+ original, NO crop)', [
                                                            'url' => substr($decodedUrl, 0, 80) . '...',
                                                        ]);
                                                    } else {
                                                        $imageUrls[] = $decodedUrl;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } elseif (isset($resources[0]['src'])) {
                                // display_resources array (sorted by quality, first is best)
                                foreach ($resources as $index => $resource) {
                                    if (isset($resource['src']) && filter_var($resource['src'], FILTER_VALIDATE_URL)) {
                                        $decodedUrl = html_entity_decode($resource['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                        // First resource (index 0) is premium (99%+ original, no crop)
                                        if ($index === 0) {
                                            $premiumCandidateUrls[] = $decodedUrl;
                                            Log::info('Found display_resources[0] from _sharedData (99%+ original, NO crop)', [
                                                'url' => substr($decodedUrl, 0, 80) . '...',
                                                'width' => $resource['config_width'] ?? 'unknown',
                                                'height' => $resource['config_height'] ?? 'unknown',
                                            ]);
                                        } else {
                                            $imageUrls[] = $decodedUrl;
                                            Log::debug('Found image URL from display_resources', [
                                                'url' => substr($decodedUrl, 0, 80) . '...',
                                                'index' => $index,
                                                'width' => $resource['config_width'] ?? 'unknown',
                                                'height' => $resource['config_height'] ?? 'unknown',
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Method 6: Extract from thumbnail_url pattern (lower priority)
            if (preg_match_all('/"thumbnail_url":\s*"([^"]+)"/i', $html, $thumbMatches)) {
                foreach ($thumbMatches[1] as $thumbUrl) {
                    $thumbUrl = html_entity_decode($thumbUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (filter_var($thumbUrl, FILTER_VALIDATE_URL)) {
                        $imageUrls[] = $thumbUrl;
                    }
                }
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
            // ACCEPT crop URLs and thumbnails (user requirement: download by ANY means)
            // Skip ONLY very small rsrc.php icons (32x32, 64x64, 76x76)
            if (preg_match_all('/(https?:\/\/[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)[^"\'\s]*\.(jpg|jpeg|png|webp))/i', $html, $cdnMatches)) {
                foreach ($cdnMatches[1] as $cdnUrl) {
                    $decodedUrl = html_entity_decode($cdnUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    
                    // Skip ONLY very small rsrc.php icons (32x32, 64x64, 76x76) - they're not post images
                    if (str_contains($decodedUrl, '/rsrc.php/') && 
                        preg_match('/(32x32|64x64|76x76)/', $decodedUrl)) {
                        Log::debug('Skipping very small rsrc.php icon (not post image)', [
                            'url' => substr($decodedUrl, 0, 80) . '...',
                        ]);
                        continue;
                    }
                    
                    // ACCEPT all other URLs (crop, thumbnail, preview - anything!)
                    // User requirement: download image by ANY means, even if cropped/resized
                    $imageUrls[] = $decodedUrl;
                    if (str_contains($decodedUrl, 'stp=')) {
                        Log::debug('Found CDN URL with crop parameter (stp=) - accepting as fallback', [
                            'url' => substr($decodedUrl, 0, 80) . '...',
                        ]);
                    }
                }
            }
            
            // Method 9: Extract from data-src attributes (lazy loading)
            // ACCEPT crop URLs and thumbnails (user requirement: download by ANY means)
            if (preg_match_all('/data-src=["\']([^"\']+)["\']/i', $html, $dataSrcMatches)) {
                foreach ($dataSrcMatches[1] as $dataSrc) {
                    $decodedSrc = html_entity_decode($dataSrc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    // ACCEPT all image URLs (including crop/thumbnail)
                    if (str_contains($decodedSrc, 'instagram.com') || str_contains($decodedSrc, 'cdninstagram.com') || 
                        str_contains($decodedSrc, 'fbcdn.net') || preg_match('/\.(jpg|jpeg|png|webp)/i', $decodedSrc)) {
                        $imageUrls[] = $decodedSrc;
                    }
                }
            }
            
            // Method 10: Extract from script tags with JSON (comprehensive regex)
            // Prioritize display_url, skip crop URLs
            if (preg_match_all('/<script[^>]*>.*?"(display_url|thumbnail_url|src)":\s*"([^"]+)"[^<]*<\/script>/is', $html, $scriptMatches)) {
                foreach ($scriptMatches[1] as $index => $matchType) {
                    $scriptUrl = $scriptMatches[2][$index];
                    $decodedUrl = html_entity_decode($scriptUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (filter_var($decodedUrl, FILTER_VALIDATE_URL)) {
                        // If it's display_url, add to premium (no crop)
                        if (strtolower($matchType) === 'display_url' && !str_contains($decodedUrl, 'stp=')) {
                            $premiumDisplayUrls[] = $decodedUrl;
                            Log::info('Found display_url from script tag (99%+ original, NO crop)', [
                                'url' => substr($decodedUrl, 0, 80) . '...',
                            ]);
                        } elseif (!str_contains($decodedUrl, 'stp=')) {
                            // Other URLs without crop parameter
                            $imageUrls[] = $decodedUrl;
                        }
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
            
            // Combine all URL sources for logging
            $totalUrls = count($imageUrls) + count($premiumDisplayUrls) + count($premiumCandidateUrls);
            
            Log::info('Image URLs extracted from HTML', [
                'url' => $url,
                'image_urls_count' => count($imageUrls),
                'premium_display_urls_count' => count($premiumDisplayUrls),
                'premium_candidate_urls_count' => count($premiumCandidateUrls),
                'total_urls_count' => $totalUrls,
                'image_urls_preview' => array_slice($imageUrls, 0, 3), // First 3 for logging
            ]);
            
            // Check if we have ANY URLs (from any source)
            if (empty($imageUrls) && empty($premiumDisplayUrls) && empty($premiumCandidateUrls)) {
                Log::warning('No image URLs found in HTML - trying fallback extraction methods', [
                    'url' => $url,
                    'html_length' => strlen($html),
                ]);
                
                // Fallback: Try to extract image URLs from HTML - prioritize scontent- URLs (real post images)
                // CRITICAL: Skip ALL rsrc.php/static URLs (they're static icons, NEVER post images)
                // Try multiple patterns to find actual post image URLs
                $fallbackPatterns = [
                    // Pattern 1: scontent- URLs (Instagram CDN for post images) - HIGHEST PRIORITY
                    '/(https?:\/\/[^"\'\s]*scontent-[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)[^"\'\s]*\.(jpg|jpeg|png|webp)(\?[^"\'\s]*)?)/i',
                    // Pattern 2: /v/t51./ or /v/t52./ or /v/t64./ URLs (Instagram image format) - HIGH PRIORITY
                    '/(https?:\/\/[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)\/v\/t\d+\.[^"\'\s]*\.(jpg|jpeg|png|webp)(\?[^"\'\s]*)?)/i',
                    // Pattern 3: /scontent/ path URLs - MEDIUM PRIORITY
                    '/(https?:\/\/[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)\/scontent\/[^"\'\s]*\.(jpg|jpeg|png|webp)(\?[^"\'\s]*)?)/i',
                    // Pattern 4: Any CDN URL with image extension - LOW PRIORITY (but SKIP rsrc.php/static)
                    // This pattern is generic and will be filtered to exclude rsrc.php
                ];
                
                // Try patterns 1-3 first (post image patterns, excluding rsrc.php)
                foreach ($fallbackPatterns as $patternIndex => $pattern) {
                    if ($patternIndex >= 3) {
                        break; // Skip pattern 4 for now (generic, will handle separately)
                    }
                    
                    if (preg_match_all($pattern, $html, $fallbackMatches)) {
                        foreach ($fallbackMatches[1] as $fallbackUrl) {
                            $decodedFallbackUrl = html_entity_decode($fallbackUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            
                            // CRITICAL: Skip ALL rsrc.php URLs - they're static icons/logos, NEVER post images
                            // rsrc.php is Instagram's static asset CDN (icons, buttons, not post content)
                            if (str_contains($decodedFallbackUrl, '/rsrc.php/') || 
                                str_contains($decodedFallbackUrl, 'static.cdninstagram.com')) {
                                Log::debug('Skipping rsrc.php/static URL (static icon, not post image)', [
                                    'url' => substr($decodedFallbackUrl, 0, 80) . '...',
                                ]);
                                continue; // Skip ALL rsrc.php URLs - they're not post images
                            }
                            
                            if (filter_var($decodedFallbackUrl, FILTER_VALIDATE_URL)) {
                                $imageUrls[] = $decodedFallbackUrl;
                                Log::info('Found image URL via fallback method', [
                                    'url' => substr($decodedFallbackUrl, 0, 80) . '...',
                                    'pattern_index' => $patternIndex + 1,
                                ]);
                                
                                // If we found scontent- URL (high priority), break early
                                if (str_contains($decodedFallbackUrl, 'scontent-')) {
                                    Log::info('Found high-priority scontent- URL, stopping fallback search', [
                                        'url' => substr($decodedFallbackUrl, 0, 80) . '...',
                                    ]);
                                    break 2; // Break both loops
                                }
                            }
                        }
                        
                        // If we found URLs with pattern 1-3 (high priority patterns), stop
                        if (!empty($imageUrls)) {
                            break;
                        }
                    }
                }
                
                // Pattern 4: Generic CDN URL (ONLY if patterns 1-3 found nothing AND only if NOT rsrc.php)
                if (empty($imageUrls)) {
                    Log::debug('Patterns 1-3 found nothing - trying generic CDN pattern (excluding rsrc.php)', [
                        'url' => $url,
                    ]);
                    
                    $genericPattern = '/(https?:\/\/[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)[^"\'\s]*\.(jpg|jpeg|png|webp)(\?[^"\'\s]*)?)/i';
                    if (preg_match_all($genericPattern, $html, $genericMatches)) {
                        foreach ($genericMatches[1] as $genericUrl) {
                            $decodedGenericUrl = html_entity_decode($genericUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            
                            // CRITICAL: Skip ALL rsrc.php/static URLs (they're static icons, NEVER post images)
                            if (str_contains($decodedGenericUrl, '/rsrc.php/') || 
                                str_contains($decodedGenericUrl, 'static.cdninstagram.com')) {
                                continue; // Skip ALL rsrc.php/static URLs
                            }
                            
                            if (filter_var($decodedGenericUrl, FILTER_VALIDATE_URL)) {
                                $imageUrls[] = $decodedGenericUrl;
                                Log::info('Found image URL via generic fallback pattern (excluding rsrc.php)', [
                                    'url' => substr($decodedGenericUrl, 0, 80) . '...',
                                    'pattern_index' => 4,
                                ]);
                                break; // Take first valid URL
                            }
                        }
                    }
                }
                
                // If still empty, try ULTRA-AGGRESSIVE fallback: Accept ANY Instagram CDN URL (even crop/thumbnail/icon)
                // User requirement: download image by ANY means, even if cropped/resized
                if (empty($imageUrls)) {
                    Log::warning('All standard fallback methods failed - trying ultra-aggressive extraction (accept ANY CDN URL, including crop/thumbnail)', [
                        'url' => $url,
                    ]);
                    
                    // Ultra-aggressive: Extract ANY CDN URL with image extension (no filtering)
                    $ultraAggressivePatterns = [
                        // Pattern 1: ANY CDN URL with image extension (including crop URLs)
                        '/(https?:\/\/[^"\'\s]+\.(cdninstagram\.com|fbcdn\.net)[^"\'\s]*\.(jpg|jpeg|png|webp)(\?[^"\'\s]*)?)/i',
                        // Pattern 2: Instagram.com image URLs
                        '/(https?:\/\/[^"\'\s]*instagram\.com[^"\'\s]*\.(jpg|jpeg|png|webp)(\?[^"\'\s]*)?)/i',
                        // Pattern 3: Base64 data URLs (rare, but possible)
                        '/(data:image\/(jpeg|jpg|png|webp);base64,[A-Za-z0-9+\/]+={0,2})/i',
                    ];
                    
                    foreach ($ultraAggressivePatterns as $patternIndex => $pattern) {
                        if (preg_match_all($pattern, $html, $ultraMatches)) {
                            foreach ($ultraMatches[1] as $ultraUrl) {
                                $decodedUltraUrl = html_entity_decode($ultraUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                
                            // CRITICAL: Skip ALL rsrc.php/static URLs - they're static icons, NEVER post images
                            // rsrc.php is Instagram's static asset CDN (icons, buttons, not post content)
                            if (str_contains($decodedUltraUrl, '/rsrc.php/') || 
                                str_contains($decodedUltraUrl, 'static.cdninstagram.com')) {
                                continue; // Skip ALL rsrc.php/static URLs - they're not post images
                            }
                                
                                if (filter_var($decodedUltraUrl, FILTER_VALIDATE_URL) || 
                                    str_starts_with($decodedUltraUrl, 'data:image/')) {
                                    $imageUrls[] = $decodedUltraUrl;
                                    Log::info('Found image URL via ultra-aggressive method (may be crop/thumbnail)', [
                                        'url' => substr($decodedUltraUrl, 0, 100) . '...',
                                        'pattern_index' => $patternIndex + 1,
                                        'warning' => 'May be cropped/thumbnail - using as last resort',
                                    ]);
                                    
                                    // Stop after finding first valid URL
                                    if (!empty($imageUrls)) {
                                        break 2; // Break both loops
                                    }
                                }
                            }
                        }
                    }
                }
                
                // If STILL empty after ultra-aggressive, try extracting from meta tags (og:image, twitter:image)
                if (empty($imageUrls)) {
                    Log::warning('Ultra-aggressive extraction failed - trying meta tags extraction', [
                        'url' => $url,
                    ]);
                    
                    // Extract og:image
                    if (preg_match_all('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $ogMatches)) {
                        foreach ($ogMatches[1] as $ogUrl) {
                            $decodedOgUrl = html_entity_decode($ogUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            if (filter_var($decodedOgUrl, FILTER_VALIDATE_URL)) {
                                $imageUrls[] = $decodedOgUrl;
                                Log::info('Found image URL via og:image meta tag (last resort)', [
                                    'url' => substr($decodedOgUrl, 0, 100) . '...',
                                ]);
                                break;
                            }
                        }
                    }
                    
                    // Extract twitter:image
                    if (empty($imageUrls) && preg_match_all('/<meta[^>]*name=["\']twitter:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $twitterMatches)) {
                        foreach ($twitterMatches[1] as $twitterUrl) {
                            $decodedTwitterUrl = html_entity_decode($twitterUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            if (filter_var($decodedTwitterUrl, FILTER_VALIDATE_URL)) {
                                $imageUrls[] = $decodedTwitterUrl;
                                Log::info('Found image URL via twitter:image meta tag (last resort)', [
                                    'url' => substr($decodedTwitterUrl, 0, 100) . '...',
                                ]);
                                break;
                            }
                        }
                    }
                }
                
                // If STILL empty, throw exception
                if (empty($imageUrls)) {
                    Log::error('ALL image extraction methods failed - no URLs found in HTML', [
                        'url' => $url,
                        'html_length' => strlen($html),
                        'html_preview' => substr($html, 0, 500) . '...',
                    ]);
                    throw new \RuntimeException('No image URLs found in HTML after all extraction methods');
                }
            }
            
            // Download images from extracted URLs - prioritize FULL SIZE, NO CROP (99%+ original)
            // User requirement: Download by ANY means, even if cropped/resized
            // Priority: premiumDisplayUrls (display_url) > premiumCandidateUrls (candidates[0]) > goodUrls (no stp=) > cropUrls (fallback)
            
            // Separate URLs by priority to avoid crop (stp= parameter)
            // Premium URLs are already tracked separately (display_url and candidates[0])
            $goodUrls = []; // Without stp= parameter (no crop)
            $cropUrls = []; // WITH stp= parameter (causes 50%+ crop) - COMPLETELY SKIPPED
            
            // Process regular imageUrls - separate by crop parameter
            // ACCEPT thumbnails/previews if no better URLs found (user requirement: download by ANY means)
            foreach ($imageUrls as $url) {
                // Don't skip thumbnails/previews - accept them if nothing better is found
                // User requirement: download image by ANY means, even if cropped/resized
                
                // Separate URLs by crop parameter - crop URLs are fallback (lowest priority)
                $hasStpCrop = str_contains($url, 'stp=');
                if ($hasStpCrop) {
                    // Crop URLs (stp=) - use as fallback only if no uncropped URLs found
                    $cropUrls[] = $url;
                } else {
                    // URL without crop parameter - acceptable (no crop, may have small quality loss)
                    $goodUrls[] = $url;
                }
            }
            
            // Prioritize: premiumDisplayUrls (display_url) > premiumCandidateUrls (candidates[0]) > goodUrls (no stp=) > cropUrls (stp=, fallback)
            // Use crop URLs only if no uncropped URLs are available
            $premiumUrls = array_merge($premiumDisplayUrls, $premiumCandidateUrls);
            $premiumUrls = array_unique($premiumUrls); // Remove duplicates
            $imageUrls = array_merge($premiumUrls, $goodUrls);
            
            // Log URLs (crop URLs are fallback - will be used if no uncropped URLs found)
            Log::info('Prioritized image URLs for download', [
                'url' => $url,
                'premium_display_urls' => count($premiumDisplayUrls),
                'premium_candidate_urls' => count($premiumCandidateUrls),
                'premium_total' => count($premiumUrls),
                'good_urls' => count($goodUrls),
                'crop_urls_fallback' => count($cropUrls),
                'total_urls' => count($imageUrls),
                'priority_order' => 'premium (display_url + candidates[0]) > good (no stp=) > crop (stp=, fallback if needed)',
            ]);
            
            // Sort URLs by size parameters to get HIGHEST QUALITY (full size, no crop)
            // NOTE: All URLs in $imageUrls are already without stp= (crop URLs are skipped)
            // Priority: 1) Premium URLs first (already first in array), 2) Larger size, 3) Better domain
            $premiumUrlsForSort = $premiumUrls ?? []; // For closure
            usort($imageUrls, function($a, $b) use ($premiumUrlsForSort) {
                // Check if URLs are in premium array (display_url or candidates[0])
                // Premium URLs should stay first
                $aIsPremium = in_array($a, $premiumUrlsForSort);
                $bIsPremium = in_array($b, $premiumUrlsForSort);
                
                if ($aIsPremium && !$bIsPremium) {
                    return -1; // a is better (premium)
                }
                if (!$aIsPremium && $bIsPremium) {
                    return 1; // b is better (premium)
                }
                
                // Both have crop or both don't - compare by size
                // Score based on domain (scontent- is better for full images)
                $aDomainScore = (str_contains($a, 'scontent-') ? 20 : 0) + (str_contains($a, '/scontent/') ? 10 : 0);
                $bDomainScore = (str_contains($b, 'scontent-') ? 20 : 0) + (str_contains($b, '/scontent/') ? 10 : 0);
                
                // Extract size parameters from URL (e.g., s1080x1080, s640x640, s320x320)
                $aSize = 0;
                $bSize = 0;
                $aWidth = 0;
                $aHeight = 0;
                $bWidth = 0;
                $bHeight = 0;
                
                // Try to find size parameter in URL
                if (preg_match('/[?&](s(\d+)x(\d+))/i', $a, $aMatches)) {
                    $aWidth = (int)$aMatches[2];
                    $aHeight = (int)$aMatches[3];
                    $aSize = $aWidth * $aHeight; // width * height
                }
                if (preg_match('/[?&](s(\d+)x(\d+))/i', $b, $bMatches)) {
                    $bWidth = (int)$bMatches[2];
                    $bHeight = (int)$bMatches[3];
                    $bSize = $bWidth * $bHeight; // width * height
                }
                
                // Prioritize larger dimensions (full-size images)
                // If sizes are equal, prefer wider images (landscape) for full content
                if ($aSize === $bSize && $aSize > 0) {
                    // If same total size, prefer wider (landscape) - usually full images
                    if ($aWidth > $bWidth) {
                        return -1; // a is better (wider)
                    } elseif ($bWidth > $aWidth) {
                        return 1; // b is better (wider)
                    }
                    // Same dimensions, prefer better domain
                    return $bDomainScore <=> $aDomainScore;
                }
                
                // Prioritize by total size (larger = full image, less crop)
                if ($aSize > 0 && $bSize > 0) {
                    return $bSize <=> $aSize; // Descending order (larger first)
                }
                
                // If one has size and other doesn't, prefer the one with size
                if ($aSize > 0 && $bSize === 0) {
                    return -1; // a is better
                }
                if ($bSize > 0 && $aSize === 0) {
                    return 1; // b is better
                }
                
                // Both have no size parameter, prefer better domain
                return $bDomainScore <=> $aDomainScore;
            });
            
            $downloadedFiles = [];
            $downloadedCount = 0;
            $maxImages = 10; // Limit to 10 images
            $triedPremiumGood = false; // Track if we tried premium/good URLs
            
            Log::info('Filtered and sorted image URLs for download', [
                'url' => $url,
                'premium_urls' => count($premiumUrls ?? []),
                'good_urls' => count($goodUrls ?? []),
                'crop_urls_fallback' => count($cropUrls ?? []),
                'total_urls' => count($imageUrls),
                'top_3_urls_preview' => array_slice($imageUrls, 0, 3),
            ]);
            
            // Try URLs without crop first (99%+ original), crop URLs used as fallback
            // Priority: premium (display_url/candidates[0]) > good (no stp=) > crop (stp=, fallback if needed)
            
            // Add crop URLs as fallback if no uncropped URLs found
            // Better to have a cropped image than none at all
            if (empty($imageUrls) && !empty($cropUrls)) {
                Log::warning('No uncropped URLs found - using crop URLs as fallback (may have 50%+ cropping)', [
                    'url' => $url,
                    'premium_urls_count' => count($premiumUrls ?? []),
                    'good_urls_count' => count($goodUrls ?? []),
                    'crop_urls_count' => count($cropUrls),
                    'note' => 'Using crop URLs (stp=) as fallback - image may be cropped',
                ]);
                
                // Use crop URLs directly as fallback (better than nothing)
                foreach ($cropUrls as $cropUrl) {
                    $imageUrls[] = $cropUrl;
                    Log::info('Added crop URL as fallback (may have 50%+ cropping)', [
                        'url' => substr($cropUrl, 0, 80) . '...',
                        'warning' => 'Image may be significantly cropped',
                    ]);
                }
                
                // Re-index array
                $imageUrls = array_values($imageUrls);
            }
            
            if (empty($imageUrls)) {
                Log::error('No image URLs found at all - cannot download image', [
                    'url' => $url,
                    'premium_urls_count' => count($premiumUrls ?? []),
                    'good_urls_count' => count($goodUrls ?? []),
                    'crop_urls_count' => count($cropUrls ?? []),
                ]);
                throw new \RuntimeException('No image URLs found - cannot download image');
            }
            
            Log::info('Final URL list for download', [
                'url' => $url,
                'premium_urls' => count($premiumUrls ?? []),
                'good_urls' => count($goodUrls ?? []),
                'crop_urls_fallback' => count($cropUrls ?? []),
                'total_urls' => count($imageUrls),
                'note' => 'Crop URLs (stp=) included as fallback if no uncropped URLs found',
            ]);
            
            $triedPremiumGood = false;
            foreach ($imageUrls as $imageUrl) {
                if ($downloadedCount >= $maxImages) {
                    break;
                }
                
                try {
                    // Check if URL has crop parameter
                    $hasCrop = str_contains($imageUrl, 'stp=');
                    
                    // Prioritize uncropped URLs - skip crop URLs only if we haven't tried uncropped ones yet
                    // We'll try crop URLs later if all uncropped ones fail (after the loop)
                    if ($hasCrop && $triedPremiumGood === false && (!empty($premiumUrls ?? []) || !empty($goodUrls ?? []))) {
                        // If we have uncropped URLs that we haven't tried yet, skip crop URLs for now
                        // We'll try crop URLs later if all uncropped ones fail
                        Log::debug('Skipping crop URL - will try uncropped URLs first', [
                            'url' => substr($imageUrl, 0, 80) . '...',
                            'premium_count' => count($premiumUrls ?? []),
                            'good_count' => count($goodUrls ?? []),
                        ]);
                        continue;
                    }
                    
                    // Determine priority and log appropriately
                    $priority = 'unknown';
                    $hasCropLabel = $hasCrop ? 'yes (stp=, fallback)' : 'no (99%+ original)';
                    
                    if (in_array($imageUrl, $premiumUrls ?? [])) {
                        $priority = 'premium (display_url/candidates[0])';
                    } elseif (!$hasCrop) {
                        $priority = 'good (no stp=)';
                    } else {
                        $priority = 'crop (stp=, fallback)';
                    }
                    
                    Log::info('Attempting to download image from extracted URL', [
                        'url' => substr($imageUrl, 0, 100) . '...',
                        'attempt' => $downloadedCount + 1,
                        'total_urls' => count($imageUrls),
                        'has_crop' => $hasCropLabel,
                        'priority' => $priority,
                    ]);
                    
                    $imagePath = $this->downloadImageFromUrl($imageUrl, $outputDir, $url);
                    if ($imagePath && file_exists($imagePath)) {
                        // Verify it's actually an image file and check dimensions
                        $imageInfo = @getimagesize($imagePath);
                        if ($imageInfo !== false && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
                            $width = $imageInfo[0];
                            $height = $imageInfo[1];
                            $fileSize = filesize($imagePath);
                            
                            // Accept valid images - prefer uncropped, but accept cropped if needed
                            // User requirement: send image even if cropped (better than nothing)
                            if ($width >= 200 && $height >= 200) {
                                // Accept all images >= 200x200 (cropped or not)
                                // User prefers sending cropped image rather than no image
                                
                                $downloadedFiles[] = $imagePath;
                                $downloadedCount++;
                                Log::info('Image successfully downloaded (99%+ original preserved)', [
                                    'url' => substr($imageUrl, 0, 80) . '...',
                                    'file_path' => basename($imagePath),
                                    'file_size' => $fileSize,
                                    'dimensions' => $width . 'x' . $height,
                                    'aspect_ratio' => round($width / $height, 2),
                                    'has_crop_param' => $hasCrop ? 'yes (cropped)' : 'no (original)',
                                    'quality' => $hasCrop ? 'may be cropped' : '99%+ original',
                                ]);
                                
                                // If we got a good image without crop, stop trying more URLs
                                // If image is large (1080x1080+), it's definitely good
                                if (!$hasCrop && $width >= 1080 && $height >= 1080) {
                                    Log::info('Got high-quality original image (no crop), stopping URL attempts', [
                                        'dimensions' => $width . 'x' . $height,
                                    ]);
                                    break; // Got perfect image, stop trying
                                }
                                
                                // If image is medium-large (640x640+) without crop, also good
                                if (!$hasCrop && $width >= 640 && $height >= 640) {
                                    Log::info('Got good quality original image (no crop), stopping URL attempts', [
                                        'dimensions' => $width . 'x' . $height,
                                    ]);
                                    break; // Got good image, stop trying
                                }
                                
                                // Mark that we tried premium/good URLs
                                $triedPremiumGood = true;
                            } else {
                                Log::debug('Downloaded image is too small, trying next URL', [
                                    'file_path' => $imagePath,
                                    'dimensions' => $width . 'x' . $height,
                                ]);
                                @unlink($imagePath);
                            }
                        } else {
                            Log::debug('Downloaded file is not a valid image, trying next URL', [
                                'file_path' => $imagePath,
                            ]);
                            @unlink($imagePath);
                        }
                    } else {
                        Log::debug('Failed to download from URL, trying next', [
                            'url' => substr($imageUrl, 0, 80) . '...',
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::debug('Exception downloading image from URL, trying next', [
                        'url' => substr($imageUrl, 0, 100) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // If no uncropped URLs worked, try crop URLs as fallback
            // User requirement: send image even if cropped (better than nothing)
            if (empty($downloadedFiles) && !empty($cropUrls)) {
                Log::warning('All uncropped URLs failed - trying crop URLs as fallback (may have 50%+ cropping)', [
                    'url' => $url,
                    'premium_urls_tried' => count($premiumUrls ?? []),
                    'good_urls_tried' => count($goodUrls ?? []),
                    'crop_urls_fallback' => count($cropUrls),
                    'note' => 'Using crop URLs (stp=) as fallback - image may be cropped',
                ]);
                
                // Try crop URLs as fallback
                foreach ($cropUrls as $cropUrl) {
                    if ($downloadedCount >= $maxImages) {
                        break;
                    }
                    
                    try {
                        Log::info('Attempting to download from crop URL (fallback, may have cropping)', [
                            'url' => substr($cropUrl, 0, 100) . '...',
                            'attempt' => $downloadedCount + 1,
                            'total_crop_urls' => count($cropUrls),
                            'warning' => 'Image may be significantly cropped',
                        ]);
                        
                        $imagePath = $this->downloadImageFromUrl($cropUrl, $outputDir, $url);
                        if ($imagePath && file_exists($imagePath)) {
                            $imageInfo = @getimagesize($imagePath);
                            if ($imageInfo !== false && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
                                $width = $imageInfo[0];
                                $height = $imageInfo[1];
                                $fileSize = filesize($imagePath);
                                
                                // Accept cropped images if they're valid (better than nothing)
                                if ($width >= 200 && $height >= 200) {
                                    $downloadedFiles[] = $imagePath;
                                    $downloadedCount++;
                                    Log::info('Image downloaded from crop URL (fallback, may be cropped)', [
                                        'url' => substr($cropUrl, 0, 80) . '...',
                                        'file_path' => basename($imagePath),
                                        'dimensions' => $width . 'x' . $height,
                                        'warning' => 'Image may be significantly cropped',
                                    ]);
                                    break; // Got image, stop trying
                                } else {
                                    @unlink($imagePath);
                                }
                            } else {
                                @unlink($imagePath);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::debug('Exception downloading from crop URL, trying next', [
                            'url' => substr($cropUrl, 0, 100) . '...',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            
            if (!empty($downloadedFiles)) {
                Log::info('Instagram images downloaded via HTML parsing', [
                    'url' => $url,
                    'files_count' => count($downloadedFiles),
                ]);
                return array_values($downloadedFiles);
            }
            
            // If still no files, log error but throw exception
            Log::error('All image download attempts failed (including crop URLs as fallback)', [
                'url' => $url,
                'premium_urls_tried' => count($premiumUrls ?? []),
                'good_urls_tried' => count($goodUrls ?? []),
                'crop_urls_tried' => count($cropUrls ?? []),
            ]);
            
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
