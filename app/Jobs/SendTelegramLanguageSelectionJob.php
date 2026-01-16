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
            $text = "🌍 Please select your language / Выберите язык / Tilni tanlang:";
            
            $keyboard = [
                [
                    ['text' => '🇺🇿 O\'zbek', 'callback_data' => 'lang_uz'],
                    ['text' => '🇷🇺 Русский', 'callback_data' => 'lang_ru'],
                ],
                [
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang_en'],
                ],
            ];

            $telegramService->sendMessageWithKeyboard($this->chatId, $text, $keyboard);
        } catch (\Exception $e) {
            Log::error('Failed to send language selection', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
