<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Instagram Service with multiple fallback methods
 * Tries different methods for reliable Instagram downloads
 */
class InstagramService
{
    private string $ytDlpPath;
    private int $timeout;
    private ?string $cookiesPath;

    public function __construct()
    {
        $this->ytDlpPath = config('telegram.yt_dlp_path', 'yt-dlp');
        $this->timeout = config('telegram.download_timeout', 60);
        $this->cookiesPath = config('telegram.instagram_cookies_path');
    }

    /**
     * Download media from Instagram URL
     * Tries multiple methods with fallback
     *
     * @param string $url
     * @param string $outputDir
     * @return array Array of downloaded file paths
     */
    public function download(string $url, string $outputDir): array
    {
        // Method 1: Try with cookies (most reliable)
        if ($this->cookiesPath && file_exists($this->cookiesPath)) {
            try {
                return $this->downloadWithCookies($url, $outputDir);
            } catch (\Exception $e) {
                Log::warning('Instagram download with cookies failed, trying fallback', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Method 2: Try with enhanced headers (fallback)
        try {
            return $this->downloadWithEnhancedHeaders($url, $outputDir);
        } catch (\Exception $e) {
            Log::warning('Instagram download with enhanced headers failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download with Instagram cookies (most reliable method)
     *
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function downloadWithCookies(string $url, string $outputDir): array
    {
        $arguments = [
            $this->ytDlpPath,
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--no-progress',
            '--ignore-errors',
            '--cookies', $this->cookiesPath,
            '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            '--referer', 'https://www.instagram.com/',
            '--output', $outputDir . '/%(title)s.%(ext)s',
            '--format', 'best',
            $url,
        ];

        return $this->executeDownload($arguments, $url, $outputDir);
    }

    /**
     * Download with enhanced headers (fallback method)
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
            '--format', 'best',
            $url,
        ];

        return $this->executeDownload($arguments, $url, $outputDir);
    }

    /**
     * Execute download process
     *
     * @param array $arguments
     * @param string $url
     * @param string $outputDir
     * @return array
     */
    private function executeDownload(array $arguments, string $url, string $outputDir): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $process = new Process($arguments);
        $process->setTimeout($this->timeout);
        $process->setIdleTimeout($this->timeout);
        $process->setWorkingDirectory($outputDir);
        $process->setEnv(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);

        Log::info('Starting Instagram download', [
            'url' => $url,
            'method' => isset($arguments[array_search('--cookies', $arguments)]) ? 'with-cookies' : 'enhanced-headers',
            'output_dir' => $outputDir,
        ]);

        try {
            $process->run(function ($type, $buffer) use ($url) {
                Log::debug('Instagram download output', [
                    'url' => $url,
                    'type' => $type === Process::ERR ? 'stderr' : 'stdout',
                    'buffer' => substr($buffer, 0, 500),
                ]);
            });

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                $stdOutput = $process->getOutput();
                
                Log::error('Instagram download failed', [
                    'url' => $url,
                    'exit_code' => $process->getExitCode(),
                    'error' => $errorOutput,
                    'output' => substr($stdOutput, 0, 500),
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

        // Find downloaded files
        $files = $this->findDownloadedFiles($outputDir);

        if (empty($files)) {
            throw new \RuntimeException('No files were downloaded');
        }

        return $files;
    }

    /**
     * Find downloaded files in directory
     *
     * @param string $directory
     * @return array
     */
    private function findDownloadedFiles(string $directory): array
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
                
                // Skip info files
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
     * Force kill process
     *
     * @param Process $process
     * @return void
     */
    private function forceKillProcess(Process $process): void
    {
        try {
            if ($process->isRunning()) {
                $pid = $process->getPid();
                if ($pid && $pid > 0 && function_exists('posix_kill')) {
                    @posix_kill($pid, SIGTERM);
                    usleep(500000);
                    if ($process->isRunning()) {
                        @posix_kill($pid, SIGKILL);
                    }
                } else {
                    $process->stop(5, SIGKILL);
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
    }
}
