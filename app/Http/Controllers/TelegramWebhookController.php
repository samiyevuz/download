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
            // Send welcome message directly (non-blocking via queue job)
            try {
                \App\Jobs\SendTelegramMessageJob::dispatch(
                    $chatId,
                    "👋 Welcome!\nSend an Instagram or TikTok link and I will download the video or images for you 🚀"
                )->onQueue('telegram');
            } catch (\Exception $e) {
                // Fallback: send directly if queue fails
                Log::warning('Failed to dispatch welcome message job', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
                // Don't block webhook, just log the error
            }
            return;
        }

        // Handle URL
        if ($text) {
            // Validate and sanitize URL
            $validatedUrl = $this->urlValidator->validateAndSanitize($text);

            if (!$validatedUrl) {
                // Dispatch error message asynchronously
                \App\Jobs\SendTelegramMessageJob::dispatch(
                    $chatId,
                    "❌ Please send a valid Instagram or TikTok link.",
                    $messageId
                )->onQueue('telegram');
                return;
            }

            // Dispatch job to queue for async processing
            // The job itself will send the "Downloading..." message
            DownloadMediaJob::dispatch($chatId, $validatedUrl, $messageId)
                ->onQueue('downloads');

            Log::info('Download job dispatched', [
                'chat_id' => $chatId,
                'url' => $validatedUrl,
            ]);
        }
    }
}
