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
            $messages = [
                'uz' => "👋 Xush kelibsiz!\nInstagram yoki TikTok linkini yuboring va men video yoki rasmlarni yuklab beraman 🚀",
                'ru' => "👋 Добро пожаловать!\nОтправьте ссылку Instagram или TikTok, и я скачаю видео или изображения 🚀",
                'en' => "👋 Welcome!\nSend an Instagram or TikTok link and I will download the video or images for you 🚀",
            ];

            $message = $messages[$this->language] ?? $messages['en'];
            $telegramService->sendMessage($this->chatId, $message);
        } catch (\Exception $e) {
            Log::error('Failed to send welcome message', [
                'chat_id' => $this->chatId,
                'language' => $this->language,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
