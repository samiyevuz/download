<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job to answer callback query (remove loading state from button)
 */
class AnswerCallbackQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;
    public int $tries = 3;

    public function __construct(
        public string $callbackQueryId,
        public ?string $text = null,
        public bool $showAlert = false
    ) {
        //
    }

    public function handle(): void
    {
        try {
            $botToken = config('telegram.bot_token');
            $apiUrl = config('telegram.api_url');

            $response = Http::timeout(10)->post("{$apiUrl}{$botToken}/answerCallbackQuery", [
                'callback_query_id' => $this->callbackQueryId,
                'text' => $this->text,
                'show_alert' => $this->showAlert,
            ]);

            if (!$response->successful()) {
                Log::error('Failed to answer callback query', [
                    'callback_query_id' => $this->callbackQueryId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to answer callback query', [
                'callback_query_id' => $this->callbackQueryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
