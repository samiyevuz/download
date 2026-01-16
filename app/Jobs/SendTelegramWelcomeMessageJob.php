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
                'uz' => "👋 <b>Xush kelibsiz!</b>\n\n📥 Men Instagram va TikTok'dan video va rasmlarni yuklab beraman.\n\n🔗 <i>Instagram yoki TikTok linkini yuboring:</i>",
                'ru' => "👋 <b>Добро пожаловать!</b>\n\n📥 Я скачиваю видео и изображения из Instagram и TikTok.\n\n🔗 <i>Отправьте ссылку Instagram или TikTok:</i>",
                'en' => "👋 <b>Welcome!</b>\n\n📥 I download videos and images from Instagram and TikTok.\n\n🔗 <i>Send an Instagram or TikTok link:</i>",
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
