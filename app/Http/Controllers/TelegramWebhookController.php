<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadMediaJob;
use App\Validators\UrlValidator;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Webhook Controller
 */
class TelegramWebhookController extends Controller
{
    private TelegramService $telegramService;
    private UrlValidator $urlValidator;

    public function __construct(TelegramService $telegramService, UrlValidator $urlValidator)
    {
        $this->telegramService = $telegramService;
        $this->urlValidator = $urlValidator;
    }

    /**
     * Handle incoming webhook from Telegram
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $update = $request->all();

            // Log incoming update (without sensitive data)
            Log::info('Telegram webhook received', [
                'update_id' => $update['update_id'] ?? null,
            ]);

            // Handle callback_query (inline button clicks)
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
            }

            // Handle message
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }

            // Always return 200 OK immediately
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Error processing Telegram webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Still return 200 to prevent Telegram from retrying
            return response()->json(['ok' => false, 'error' => 'Internal error']);
        }
    }

    /**
     * Handle incoming message
     *
     * @param array $message
     * @return void
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $text = $message['text'] ?? null;

        if (!$chatId) {
            Log::warning('Message without chat_id', ['message' => $message]);
            return;
        }

        // Handle /start command
        if ($text === '/start') {
            // Send language selection keyboard
            try {
                \App\Jobs\SendTelegramLanguageSelectionJob::dispatch($chatId)->onQueue('telegram');
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch language selection job', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }


        // Handle URL
        if ($text) {
            // Validate and sanitize URL
            $validatedUrl = $this->urlValidator->validateAndSanitize($text);

            if (!$validatedUrl) {
                // Get user's language preference
                $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');
                
                $errorMessages = [
                    'uz' => "❌ Iltimos, to'g'ri Instagram yoki TikTok linkini yuboring.",
                    'ru' => "❌ Пожалуйста, отправьте действительную ссылку Instagram или TikTok.",
                    'en' => "❌ Please send a valid Instagram or TikTok link.",
                ];
                
                $errorMessage = $errorMessages[$language] ?? $errorMessages['en'];
                
                // Dispatch error message asynchronously
                \App\Jobs\SendTelegramMessageJob::dispatch(
                    $chatId,
                    $errorMessage,
                    $messageId
                )->onQueue('telegram');
                return;
            }

            // Get user's language preference
            $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');
            
            // Dispatch job to queue for async processing
            // The job itself will send the "Downloading..." message
            DownloadMediaJob::dispatch($chatId, $validatedUrl, $messageId, $language)
                ->onQueue('downloads');

            Log::info('Download job dispatched', [
                'chat_id' => $chatId,
                'url' => $validatedUrl,
            ]);
        }
    }

    /**
     * Handle callback query (inline button clicks)
     *
     * @param array $callbackQuery
     * @return void
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $chatId = $callbackQuery['from']['id'] ?? null;
        $callbackData = $callbackQuery['data'] ?? null;
        $callbackQueryId = $callbackQuery['id'] ?? null;
        $message = $callbackQuery['message'] ?? null;

        if (!$chatId || !$callbackData || !$callbackQueryId) {
            return;
        }

        // Handle language selection
        if (str_starts_with($callbackData, 'lang_')) {
            $language = str_replace('lang_', '', $callbackData);
            
            // Save language preference (using Redis cache for 30 days)
            try {
                \Illuminate\Support\Facades\Cache::put(
                    "user_lang_{$chatId}",
                    $language,
                    now()->addDays(30)
                );
            } catch (\Exception $e) {
                Log::warning('Failed to save language preference', [
                    'chat_id' => $chatId,
                    'language' => $language,
                    'error' => $e->getMessage(),
                ]);
            }

            // Answer callback query (remove loading state)
            try {
                \App\Jobs\AnswerCallbackQueryJob::dispatch($callbackQueryId)->onQueue('telegram');
            } catch (\Exception $e) {
                Log::warning('Failed to answer callback query', [
                    'callback_query_id' => $callbackQueryId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send welcome message in selected language
            try {
                \App\Jobs\SendTelegramWelcomeMessageJob::dispatch($chatId, $language)->onQueue('telegram');
            } catch (\Exception $e) {
                Log::warning('Failed to send welcome message', [
                    'chat_id' => $chatId,
                    'language' => $language,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
