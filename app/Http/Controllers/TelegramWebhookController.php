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
     * Check if user is subscribed to required channel
     *
     * @param int|string $userId
     * @param string $language
     * @return bool
     */
    private function checkSubscription(int|string $userId, string $language = 'en'): bool
    {
        // Check if channel subscription is required
        $requiredChannels = config('telegram.required_channels');
        $channelId = config('telegram.required_channel_id');
        $channelUsername = config('telegram.required_channel_username');

        // Debug logging
        Log::debug('Checking subscription', [
            'user_id' => $userId,
            'required_channels' => $requiredChannels,
            'channel_id' => $channelId,
            'channel_username' => $channelUsername,
        ]);

        // If no channel is configured, allow access
        if (empty($requiredChannels) && empty($channelId) && empty($channelUsername)) {
            Log::debug('No channels configured, allowing access');
            return true;
        }

        // Check membership
        $membershipResult = $this->telegramService->checkChannelMembership($userId);
        $isMember = $membershipResult['is_member'];
        $missingChannels = $membershipResult['missing_channels'];
        
        Log::debug('Channel membership check result', [
            'user_id' => $userId,
            'is_member' => $isMember,
            'missing_channels' => $missingChannels,
        ]);

        if (!$isMember) {
            // Send subscription required message with missing channels info
            Log::info('User not subscribed, sending subscription required message', [
                'user_id' => $userId,
                'language' => $language,
                'missing_channels' => $missingChannels,
            ]);
            $this->telegramService->sendSubscriptionRequiredMessage($userId, $language, $missingChannels);
            return false;
        }

        return true;
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
        $userId = $message['from']['id'] ?? $chatId;
        $messageId = $message['message_id'] ?? null;
        $text = $message['text'] ?? null;

        if (!$chatId) {
            Log::warning('Message without chat_id', ['message' => $message]);
            return;
        }

        // Get user's language preference
        $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');

        // Handle /start command
        if ($text === '/start') {
            // Send language selection keyboard FIRST (before subscription check)
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
            // Check subscription before processing URL
            if (!$this->checkSubscription($userId, $language)) {
                return;
            }

            // Validate and sanitize URL
            $validatedUrl = $this->urlValidator->validateAndSanitize($text);

            if (!$validatedUrl) {
                // Get user's language preference
                $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');
                
                $errorMessages = [
                    'uz' => "❌ <b>Noto'g'ri link</b>\n\n⚠️ Iltimos, to'g'ri Instagram yoki TikTok linkini yuboring.\n\n📝 Misol: <code>https://www.instagram.com/reel/...</code>",
                    'ru' => "❌ <b>Неверная ссылка</b>\n\n⚠️ Пожалуйста, отправьте действительную ссылку Instagram или TikTok.\n\n📝 Пример: <code>https://www.instagram.com/reel/...</code>",
                    'en' => "❌ <b>Invalid link</b>\n\n⚠️ Please send a valid Instagram or TikTok link.\n\n📝 Example: <code>https://www.instagram.com/reel/...</code>",
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

        // Handle subscription check
        if ($callbackData === 'check_subscription') {
            $userId = $callbackQuery['from']['id'] ?? null;
            $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');

            if (!$userId) {
                return;
            }

            // Check membership
            $membershipResult = $this->telegramService->checkChannelMembership($userId);
            $isMember = $membershipResult['is_member'];
            $missingChannels = $membershipResult['missing_channels'];

            // Answer callback query
            try {
                if ($isMember) {
                    $successMessages = [
                        'uz' => '✅ Muvaffaqiyatli! Barcha kanallarga a\'zo bo\'ldingiz.',
                        'ru' => '✅ Успешно! Вы подписались на все каналы.',
                        'en' => '✅ Success! You have subscribed to all channels.',
                    ];
                    $text = $successMessages[$language] ?? $successMessages['en'];
                    \App\Jobs\AnswerCallbackQueryJob::dispatch($callbackQueryId, $text, false)->onQueue('telegram');
                    
                    // Delete subscription message
                    if ($message && isset($message['message_id'])) {
                        $this->telegramService->deleteMessage($chatId, $message['message_id']);
                    }
                    
                    // Check if language is already selected
                    $selectedLanguage = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", null);
                    
                    if ($selectedLanguage) {
                        // Send welcome message in selected language
                        \App\Jobs\SendTelegramWelcomeMessageJob::dispatch($chatId, $selectedLanguage)->onQueue('telegram');
                    } else {
                        // Send language selection
                        \App\Jobs\SendTelegramLanguageSelectionJob::dispatch($chatId)->onQueue('telegram');
                    }
                } else {
                    // Show which channels are missing
                    $missingChannelsList = array_map(function($channel) {
                        return "@{$channel}";
                    }, $missingChannels);
                    
                    $missingChannelsText = implode(', ', $missingChannelsList);
                    
                    $errorMessages = [
                        'uz' => "❌ Hali quyidagi kanallarga a'zo bo'lmadingiz:\n\n{$missingChannelsText}\n\nIltimos, kanallarga o'ting va qayta urinib ko'ring.",
                        'ru' => "❌ Вы еще не подписались на следующие каналы:\n\n{$missingChannelsText}\n\nПожалуйста, перейдите в каналы и попробуйте снова.",
                        'en' => "❌ You have not subscribed to the following channels yet:\n\n{$missingChannelsText}\n\nPlease join the channels and try again.",
                    ];
                    $text = $errorMessages[$language] ?? $errorMessages['en'];
                    \App\Jobs\AnswerCallbackQueryJob::dispatch($callbackQueryId, $text, true)->onQueue('telegram');
                }
            } catch (\Exception $e) {
                Log::warning('Failed to handle subscription check', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // Handle language selection
        if (str_starts_with($callbackData, 'lang_')) {
            $language = str_replace('lang_', '', $callbackData);
            $userId = $callbackQuery['from']['id'] ?? $chatId;
            
            // Save language preference first (using Redis cache for 30 days)
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

            // Delete language selection message (clean up)
            if ($message && isset($message['message_id'])) {
                try {
                    $this->telegramService->deleteMessage($chatId, $message['message_id']);
                } catch (\Exception $e) {
                    // Log but don't fail if deletion fails
                    Log::warning('Failed to delete language selection message', [
                        'chat_id' => $chatId,
                        'message_id' => $message['message_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Check subscription after language selection
            if (!$this->checkSubscription($userId, $language)) {
                // Subscription required message will be sent by checkSubscription
                return;
            }

            // If subscribed, send welcome message
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
