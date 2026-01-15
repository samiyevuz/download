<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to send Telegram messages asynchronously
 * Used to prevent blocking webhook responses
 */
class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int|string $chatId,
        public string $text,
        public ?int $replyToMessageId = null
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(TelegramService $telegramService): void
    {
        try {
            $telegramService->sendMessage(
                $this->chatId,
                $this->text,
                $this->replyToMessageId
            );
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message job', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendTelegramMessageJob failed permanently', [
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage(),
        ]);
    }
}
