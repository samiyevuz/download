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
 * Job to send language selection keyboard
 */
class SendTelegramLanguageSelectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;
    public int $tries = 3;

    public function __construct(
        public int|string $chatId
    ) {
        //
    }

    public function handle(TelegramService $telegramService): void
    {
        try {
            Log::info('SendTelegramLanguageSelectionJob started', [
                'chat_id' => $this->chatId,
                'attempt' => $this->attempts(),
            ]);
            
            $text = "🌍 Please select your language:\nВыберите язык:\nTilni tanlang:";
            
            // Professional layout: each button on its own row (full width)
            $keyboard = [
                [
                    ['text' => '🇺🇿 Oʻzbek tili', 'callback_data' => 'lang_uz'],
                ],
                [
                    ['text' => '🇷🇺 Русский язык', 'callback_data' => 'lang_ru'],
                ],
                [
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang_en'],
                ],
            ];

            $result = $telegramService->sendMessageWithKeyboard($this->chatId, $text, $keyboard);
            
            Log::info('SendTelegramLanguageSelectionJob completed', [
                'chat_id' => $this->chatId,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send language selection', [
                'chat_id' => $this->chatId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
