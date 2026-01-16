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
 * Job to send welcome message in selected language
 */
class SendTelegramWelcomeMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;
    public int $tries = 3;

    public function __construct(
        public int|string $chatId,
        public string $language = 'en'
    ) {
        //
    }

    public function handle(TelegramService $telegramService): void
    {
        try {
            Log::info('SendTelegramWelcomeMessageJob started', [
                'chat_id' => $this->chatId,
                'language' => $this->language,
            ]);

            $messages = [
                'uz' => "Welcome.\nSend an Instagram or TikTok link.",
                'ru' => "Welcome.\nSend an Instagram or TikTok link.",
                'en' => "Welcome.\nSend an Instagram or TikTok link.",
            ];

            $message = $messages[$this->language] ?? $messages['en'];
            
            Log::info('Sending welcome message', [
                'chat_id' => $this->chatId,
                'language' => $this->language,
                'message' => $message,
            ]);
            
            $messageId = $telegramService->sendMessage($this->chatId, $message);
            
            if ($messageId) {
                Log::info('Welcome message sent successfully', [
                    'chat_id' => $this->chatId,
                    'language' => $this->language,
                    'message_id' => $messageId,
                ]);
            } else {
                Log::warning('Welcome message send returned null', [
                    'chat_id' => $this->chatId,
                    'language' => $this->language,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send welcome message', [
                'chat_id' => $this->chatId,
                'language' => $this->language,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
