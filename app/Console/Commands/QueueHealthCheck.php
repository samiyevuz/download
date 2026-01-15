<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class QueueHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:health 
                            {--detailed : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check queue system health and statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Queue Health Check');
        $this->newLine();

        // Check Redis connection
        $this->checkRedis();

        // Check queue lengths
        $this->checkQueueLengths();

        // Check workers
        $this->checkWorkers();

        // Check failed jobs
        $this->checkFailedJobs();

        // Detailed information
        if ($this->option('detailed')) {
            $this->showDetailedInfo();
        }

        return Command::SUCCESS;
    }

    /**
     * Check Redis connection
     */
    private function checkRedis(): void
    {
        $this->info('📡 Redis Connection:');
        try {
            $connection = config('queue.connections.redis.connection', 'default');
            Redis::connection($connection)->ping();
            $this->line('  ✅ Redis is connected');
        } catch (\Exception $e) {
            $this->error('  ❌ Redis connection failed: ' . $e->getMessage());
        }
        $this->newLine();
    }

    /**
     * Check queue lengths
     */
    private function checkQueueLengths(): void
    {
        $this->info('📊 Queue Lengths:');
        
        try {
            $downloadsLen = Redis::llen('queues:downloads') ?? 0;
            $telegramLen = Redis::llen('queues:telegram') ?? 0;

            $this->line("  Downloads queue: {$downloadsLen} jobs");
            $this->line("  Telegram queue: {$telegramLen} jobs");

            // Warn if queues are backing up
            if ($downloadsLen > 100) {
                $this->warn('  ⚠️  Downloads queue is backing up!');
            }
            if ($telegramLen > 200) {
                $this->warn('  ⚠️  Telegram queue is backing up!');
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Failed to check queue lengths: ' . $e->getMessage());
        }
        
        $this->newLine();
    }

    /**
     * Check running workers
     */
    private function checkWorkers(): void
    {
        $this->info('👷 Running Workers:');
        
        $workers = $this->getRunningWorkers();
        $workerCount = count($workers);

        if ($workerCount === 0) {
            $this->error('  ❌ No queue workers are running!');
        } else {
            $this->line("  ✅ {$workerCount} worker(s) running");
            
            if ($this->option('detailed')) {
                foreach ($workers as $worker) {
                    $this->line("    - PID: {$worker['pid']} | Queue: {$worker['queue']}");
                }
            }
        }
        
        $this->newLine();
    }

    /**
     * Get running workers
     */
    private function getRunningWorkers(): array
    {
        $workers = [];
        
        if (function_exists('exec')) {
            $output = [];
            exec("ps aux | grep '[q]ueue:work'", $output);
            
            foreach ($output as $line) {
                if (preg_match('/queue:work\s+(\S+)\s+--queue=(\S+)/', $line, $matches)) {
                    preg_match('/^\S+\s+(\d+)/', $line, $pidMatches);
                    $workers[] = [
                        'pid' => $pidMatches[1] ?? 'unknown',
                        'connection' => $matches[1] ?? 'unknown',
                        'queue' => $matches[2] ?? 'unknown',
                    ];
                }
            }
        }
        
        return $workers;
    }

    /**
     * Check failed jobs
     */
    private function checkFailedJobs(): void
    {
        $this->info('❌ Failed Jobs:');
        
        try {
            $failedCount = DB::table('failed_jobs')->count();
            
            if ($failedCount === 0) {
                $this->line('  ✅ No failed jobs');
            } else {
                $this->warn("  ⚠️  {$failedCount} failed job(s)");
                
                if ($this->option('detailed')) {
                    $recent = DB::table('failed_jobs')
                        ->orderBy('failed_at', 'desc')
                        ->limit(5)
                        ->get(['id', 'queue', 'failed_at']);
                    
                    $this->table(
                        ['ID', 'Queue', 'Failed At'],
                        $recent->map(fn($job) => [
                            $job->id,
                            $job->queue ?? 'default',
                            $job->failed_at,
                        ])->toArray()
                    );
                }
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Failed to check failed jobs: ' . $e->getMessage());
        }
        
        $this->newLine();
    }

    /**
     * Show detailed information
     */
    private function showDetailedInfo(): void
    {
        $this->info('📈 Detailed Statistics:');
        
        // Queue processing rate (if available)
        $this->line('  Queue processing metrics:');
        $this->line('    (Use queue:monitor for real-time metrics)');
        
        $this->newLine();
    }
}
