<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupZombieProcesses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:cleanup-zombies 
                            {--kill : Actually kill zombie processes (default: dry-run)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and optionally kill zombie yt-dlp processes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Scanning for zombie yt-dlp processes...');
        $this->newLine();

        if (!function_exists('exec')) {
            $this->error('exec() function is not available');
            return Command::FAILURE;
        }

        $zombies = $this->findZombieProcesses();

        if (empty($zombies)) {
            $this->info('✅ No zombie processes found');
            return Command::SUCCESS;
        }

        $this->warn("Found " . count($zombies) . " potential zombie process(es):");
        $this->newLine();

        $this->table(
            ['PID', 'Command', 'Runtime', 'Memory'],
            array_map(function ($proc) {
                return [
                    $proc['pid'],
                    substr($proc['cmd'], 0, 60) . '...',
                    $this->formatRuntime($proc['runtime']),
                    $this->formatMemory($proc['memory']),
                ];
            }, $zombies)
        );

        if ($this->option('kill')) {
            $this->newLine();
            if ($this->confirm('Kill these processes?', false)) {
                $killed = $this->killZombieProcesses($zombies);
                $this->info("✅ Killed {$killed} process(es)");
                Log::info('Zombie processes killed', [
                    'count' => $killed,
                    'pids' => array_column($zombies, 'pid'),
                ]);
            } else {
                $this->info('Cancelled');
            }
        } else {
            $this->newLine();
            $this->comment('This was a dry-run. Use --kill to actually kill processes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Find zombie yt-dlp processes
     *
     * @return array
     */
    private function findZombieProcesses(): array
    {
        $processes = [];
        $output = [];

        // Find all yt-dlp processes
        exec("ps aux | grep '[y]t-dlp'", $output);

        foreach ($output as $line) {
            if (preg_match('/^\S+\s+(\d+)\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+(.+)/', $line, $matches)) {
                $pid = (int) $matches[1];
                $cmd = trim($matches[2]);

                // Check if process is actually running (not a zombie)
                $stat = @file_get_contents("/proc/{$pid}/stat");
                if ($stat) {
                    $statParts = explode(' ', $stat);
                    $state = $statParts[2] ?? '';

                    // Skip if process is a zombie (state = 'Z')
                    if ($state === 'Z') {
                        continue;
                    }

                    // Get process info
                    $runtime = $this->getProcessRuntime($pid);
                    $memory = $this->getProcessMemory($pid);

                    // Consider it a zombie if it's been running for more than 5 minutes
                    // and is using significant memory (might be stuck)
                    if ($runtime > 300 && $memory > 100 * 1024 * 1024) { // 5 min, 100MB
                        $processes[] = [
                            'pid' => $pid,
                            'cmd' => $cmd,
                            'runtime' => $runtime,
                            'memory' => $memory,
                        ];
                    }
                }
            }
        }

        return $processes;
    }

    /**
     * Get process runtime in seconds
     *
     * @param int $pid
     * @return int
     */
    private function getProcessRuntime(int $pid): int
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (!$stat) {
            return 0;
        }

        $statParts = explode(' ', $stat);
        $startTime = (int) ($statParts[21] ?? 0);
        $uptime = (int) @file_get_contents('/proc/uptime');
        $uptime = explode(' ', $uptime)[0] ?? 0;

        $hertz = (int) shell_exec('getconf CLK_TCK') ?: 100;
        $startTimeSeconds = $startTime / $hertz;
        $runtime = $uptime - $startTimeSeconds;

        return (int) max(0, $runtime);
    }

    /**
     * Get process memory usage in bytes
     *
     * @param int $pid
     * @return int
     */
    private function getProcessMemory(int $pid): int
    {
        $status = @file_get_contents("/proc/{$pid}/status");
        if (!$status) {
            return 0;
        }

        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
            return (int) $matches[1] * 1024; // Convert KB to bytes
        }

        return 0;
    }

    /**
     * Kill zombie processes
     *
     * @param array $processes
     * @return int Number of processes killed
     */
    private function killZombieProcesses(array $processes): int
    {
        $killed = 0;

        foreach ($processes as $proc) {
            $pid = $proc['pid'];

            // Try SIGTERM first (graceful)
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
                usleep(500000); // Wait 0.5 seconds

                // Check if still running
                if (file_exists("/proc/{$pid}")) {
                    // Force kill
                    @posix_kill($pid, SIGKILL);
                    usleep(100000); // Wait 0.1 seconds
                }
            } else {
                // Fallback
                @exec("kill -9 {$pid} 2>&1", $output, $returnCode);
            }

            // Verify process is gone
            if (!file_exists("/proc/{$pid}")) {
                $killed++;
            }
        }

        return $killed;
    }

    /**
     * Format runtime in human-readable format
     *
     * @param int $seconds
     * @return string
     */
    private function formatRuntime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }

    /**
     * Format memory in human-readable format
     *
     * @param int $bytes
     * @return string
     */
    private function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
