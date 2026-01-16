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
     * Note: Subscription check is skipped for groups/supergroups
     *
     * @param int|string $userId
     * @param string $language
     * @param string|null $chatType Chat type (private, group, supergroup)
     * @return bool
     */
    private function checkSubscription(int|string $userId, string $language = 'en', ?string $chatType = null): bool
    {
        // Skip subscription check for groups and supergroups
        // Subscription is only required for private chats
        if ($chatType && in_array($chatType, ['group', 'supergroup'])) {
            Log::debug('Skipping subscription check for group chat', [
                'user_id' => $userId,
                'chat_type' => $chatType,
            ]);
            return true; // Allow access in groups
        }

        // Check if channel subscription is required
        // Use getRequiredChannels() method to get all channels (supports multiple channels)
        $requiredChannels = $this->telegramService->getRequiredChannels();
        $channelId = config('telegram.required_channel_id');
        $channelUsername = config('telegram.required_channel_username');

        // Debug logging
        Log::debug('Checking subscription', [
            'user_id' => $userId,
            'chat_type' => $chatType ?? 'private',
            'required_channels' => $requiredChannels,
            'required_channels_config' => config('telegram.required_channels'),
            'channel_id' => $channelId,
            'channel_username' => $channelUsername,
        ]);

        // If no channel is configured, allow access
        if (empty($requiredChannels) && empty($channelId) && empty($channelUsername)) {
            Log::debug('No channels configured, allowing access');
            return true;
        }

        // Check membership (only for private chats)
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
        $chatType = $message['chat']['type'] ?? 'private'; // private, group, supergroup, channel

        if (!$chatId) {
            Log::warning('Message without chat_id', ['message' => $message]);
            return;
        }

        // Get user's language preference
        $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');

        // Log chat type for debugging
        Log::debug('Message received', [
            'chat_id' => $chatId,
            'chat_type' => $chatType,
            'user_id' => $userId,
            'text' => $text ? substr($text, 0, 50) : null,
        ]);

        // Handle /start command
        if ($text === '/start') {
            Log::info('Handling /start command', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);
            
            // Always send language selection on /start (user can change language anytime)
            Log::info('Sending language selection on /start', [
                'chat_id' => $chatId,
            ]);
            
            try {
                $text = "🌍 Please select your language:\nВыберите язык:\nTilni tanlang:";
                
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
                
                $this->telegramService->sendMessageWithKeyboard($chatId, $text, $keyboard);
                
                Log::info('Language selection sent successfully', [
                    'chat_id' => $chatId,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send language selection', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Fallback: send simple welcome message
                $welcomeMessage = "Welcome.\nSend an Instagram or TikTok link.";
                $this->telegramService->sendMessage($chatId, $welcomeMessage);
            }
            return;
        }


        // Handle URL
        if ($text) {
            // Get user's language preference
            $language = 'en';
            try {
                $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');
            } catch (\Exception $e) {
                Log::warning('Failed to get language preference, using default', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Validate and sanitize URL
            $validatedUrl = $this->urlValidator->validateAndSanitize($text);

            if (!$validatedUrl) {
                // Invalid URL - send error message in user's language
                $errorMessages = [
                    'uz' => "❌ Iltimos, to'g'ri Instagram yoki TikTok linkini yuboring.",
                    'ru' => "❌ Пожалуйста, отправьте действительную ссылку Instagram или TikTok.",
                    'en' => "❌ Please send a valid Instagram or TikTok link.",
                ];
                $errorMessage = $errorMessages[$language] ?? $errorMessages['en'];
                $this->telegramService->sendMessage($chatId, $errorMessage, $messageId);
                return;
            }

            // Check subscription (only for private chats, skip for groups)
            // In groups, always allow (subscription check is skipped)
            $subscriptionCheck = $this->checkSubscription($userId, $language, $chatType);
            
            Log::info('URL received, subscription check result', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'url' => $validatedUrl,
                'chat_type' => $chatType,
                'subscription_check' => $subscriptionCheck,
            ]);
            
            if (!$subscriptionCheck) {
                // Subscription required message will be sent by checkSubscription
                Log::info('Subscription check failed, not processing URL', [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'chat_type' => $chatType,
                ]);
                return;
            }

            // Send "Downloading..." message IMMEDIATELY (before dispatching job)
            $downloadingMessages = [
                'uz' => "⏳ Yuklanmoqda, iltimos kuting...",
                'ru' => "⏳ Загрузка, пожалуйста подождите...",
                'en' => "⏳ Downloading, please wait...",
            ];
            $downloadingMessage = $downloadingMessages[$language] ?? $downloadingMessages['en'];
            
            $downloadingMessageId = $this->telegramService->sendMessage(
                $chatId,
                $downloadingMessage,
                $messageId
            );

            // Dispatch job to queue for async processing
            DownloadMediaJob::dispatch($chatId, $validatedUrl, $messageId, $language, $downloadingMessageId, $userId)
                ->onQueue('downloads');

            Log::info('Download job dispatched', [
                'chat_id' => $chatId,
                'url' => $validatedUrl,
                'language' => $language,
                'downloading_message_id' => $downloadingMessageId,
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
        // Get chat ID from message (for groups) or from user (for private chats)
        // This is CRITICAL: in groups, we must use message['chat']['id'], not from['id']
        $message = $callbackQuery['message'] ?? null;
        $chatId = $message['chat']['id'] ?? $callbackQuery['from']['id'] ?? null;
        $userId = $callbackQuery['from']['id'] ?? null;
        $callbackData = $callbackQuery['data'] ?? null;
        $callbackQueryId = $callbackQuery['id'] ?? null;
        $chatType = $message['chat']['type'] ?? 'private';

        if (!$chatId || !$callbackData || !$callbackQueryId) {
            Log::warning('Invalid callback query', [
                'callback_query' => $callbackQuery,
            ]);
            return;
        }

        // Log for debugging
        Log::debug('Handling callback query', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'chat_type' => $chatType,
            'callback_data' => $callbackData,
        ]);

        // Handle subscription check
        if ($callbackData === 'check_subscription') {
            $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');

            if (!$userId) {
                return;
            }

            // Skip subscription check for groups/supergroups
            if (in_array($chatType, ['group', 'supergroup'])) {
                Log::debug('Skipping subscription check for group chat in callback', [
                    'user_id' => $userId,
                    'chat_type' => $chatType,
                ]);
                // Send success message and welcome message
                $successMessages = [
                    'uz' => '✅ Guruhda ishlaydi!',
                    'ru' => '✅ Работает в группе!',
                    'en' => '✅ Works in group!',
                ];
                $text = $successMessages[$language] ?? $successMessages['en'];
                \App\Jobs\AnswerCallbackQueryJob::dispatch($callbackQueryId, $text, false)->onQueue('telegram');
                
                // Delete subscription message if exists
                if ($message && isset($message['message_id'])) {
                    $this->telegramService->deleteMessage($chatId, $message['message_id']);
                }
                
                // Send welcome message
                $selectedLanguage = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", null);
                if ($selectedLanguage) {
                    \App\Jobs\SendTelegramWelcomeMessageJob::dispatch($chatId, $selectedLanguage)->onQueue('telegram');
                } else {
                    \App\Jobs\SendTelegramLanguageSelectionJob::dispatch($chatId)->onQueue('telegram');
                }
                return;
            }

            // Check membership (only for private chats)
            // IMPORTANT: Clear cache before checking to get fresh result
            $cacheKey = "channel_membership_{$userId}_" . md5(implode(',', $this->telegramService->getRequiredChannels()));
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            
            Log::info('Checking subscription (callback), cache cleared', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'cache_key' => $cacheKey,
            ]);
            
            $membershipResult = $this->telegramService->checkChannelMembership($userId);
            $isMember = $membershipResult['is_member'];
            $missingChannels = $membershipResult['missing_channels'];
            
            Log::info('Subscription check result (callback)', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'is_member' => $isMember,
                'missing_channels' => $missingChannels,
            ]);

            // Answer callback query IMMEDIATELY (synchronously) for better UX
            try {
                if ($isMember) {
                    $successMessages = [
                        'uz' => '✅ Muvaffaqiyatli! Barcha kanallarga a\'zo bo\'ldingiz.',
                        'ru' => '✅ Успешно! Вы подписались на все каналы.',
                        'en' => '✅ Success! You have subscribed to all channels.',
                    ];
                    $text = $successMessages[$language] ?? $successMessages['en'];
                    
                    // Answer callback query directly (synchronously) for immediate feedback
                    try {
                        $this->telegramService->answerCallbackQuery($callbackQueryId, $text, false);
                        Log::info('Callback query answered successfully', [
                            'callback_query_id' => $callbackQueryId,
                            'user_id' => $userId,
                            'is_member' => true,
                        ]);
                    } catch (\Exception $answerError) {
                        Log::error('Failed to answer callback query directly, trying via queue', [
                            'callback_query_id' => $callbackQueryId,
                            'error' => $answerError->getMessage(),
                        ]);
                        \App\Jobs\AnswerCallbackQueryJob::dispatch($callbackQueryId, $text, false)->onQueue('telegram');
                    }
                    
                    // Delete subscription message
                    if ($message && isset($message['message_id'])) {
                        try {
                            $this->telegramService->deleteMessage($chatId, $message['message_id']);
                        } catch (\Exception $deleteError) {
                            Log::warning('Failed to delete subscription message', [
                                'chat_id' => $chatId,
                                'message_id' => $message['message_id'],
                                'error' => $deleteError->getMessage(),
                            ]);
                        }
                    }
                    
                    // Check if language is already selected
                    $selectedLanguage = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", null);
                    
                    if ($selectedLanguage) {
                        // Send welcome message in selected language (directly for immediate response)
                        try {
                            $welcomeMessages = [
                                'uz' => "Welcome.\nSend an Instagram or TikTok link.",
                                'ru' => "Welcome.\nSend an Instagram or TikTok link.",
                                'en' => "Welcome.\nSend an Instagram or TikTok link.",
                            ];
                            $welcomeMessage = $welcomeMessages[$selectedLanguage] ?? $welcomeMessages['en'];
                            $this->telegramService->sendMessage($chatId, $welcomeMessage);
                            Log::info('Welcome message sent directly after subscription check', [
                                'chat_id' => $chatId,
                                'language' => $selectedLanguage,
                            ]);
                        } catch (\Exception $welcomeError) {
                            Log::warning('Failed to send welcome message directly, trying via queue', [
                                'chat_id' => $chatId,
                                'error' => $welcomeError->getMessage(),
                            ]);
                            \App\Jobs\SendTelegramWelcomeMessageJob::dispatch($chatId, $selectedLanguage)->onQueue('telegram');
                        }
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
                        'uz' => "❌ A'zo bo'lmagan: {$missingChannelsText}\n\nIltimos, kanallarga o'ting va qayta urinib ko'ring.",
                        'ru' => "❌ Не подписаны: {$missingChannelsText}\n\nПожалуйста, перейдите в каналы и попробуйте снова.",
                        'en' => "❌ Not subscribed: {$missingChannelsText}\n\nPlease join the channels and try again.",
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
            
            // Save language preference first (using Redis cache for 30 days)
            // Use chat_id for cache key (works for both private and group chats)
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

            // Check subscription after language selection (only for private chats)
            // In groups, subscription check is not required
            $subscriptionCheck = $this->checkSubscription($userId, $language, $chatType);
            
            Log::info('Language selected, subscription check result', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'language' => $language,
                'chat_type' => $chatType,
                'subscription_check' => $subscriptionCheck,
            ]);

            if (!$subscriptionCheck) {
                // Subscription required message will be sent by checkSubscription
                // Don't send welcome message if not subscribed (private chat only)
                Log::info('Subscription check failed, not sending welcome message', [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'chat_type' => $chatType,
                ]);
                return;
            }

            // If subscribed (or in group), send welcome message IMMEDIATELY
            // IMPORTANT: Use chatId (which is now correctly set to group chat ID if in group)
            Log::info('Sending welcome message after language selection', [
                'chat_id' => $chatId,
                'language' => $language,
                'chat_type' => $chatType,
            ]);
            
            // Send welcome message directly (synchronously) for immediate response
            // Queue can be slow, so we send directly to ensure user sees the message
            try {
                $welcomeMessages = [
                    'uz' => "Welcome.\nSend an Instagram or TikTok link.",
                    'ru' => "Welcome.\nSend an Instagram or TikTok link.",
                    'en' => "Welcome.\nSend an Instagram or TikTok link.",
                ];
                $welcomeMessage = $welcomeMessages[$language] ?? $welcomeMessages['en'];
                
                $messageId = $this->telegramService->sendMessage($chatId, $welcomeMessage);
                
                if ($messageId) {
                    Log::info('Welcome message sent successfully (direct)', [
                        'chat_id' => $chatId,
                        'language' => $language,
                        'message_id' => $messageId,
                    ]);
                } else {
                    Log::warning('Welcome message send returned null, trying via queue', [
                        'chat_id' => $chatId,
                        'language' => $language,
                    ]);
                    
                    // Fallback: try via queue if direct send failed
                    try {
                        \App\Jobs\SendTelegramWelcomeMessageJob::dispatch($chatId, $language)->onQueue('telegram');
                        Log::info('Welcome message job dispatched (fallback)', [
                            'chat_id' => $chatId,
                            'language' => $language,
                        ]);
                    } catch (\Exception $queueError) {
                        Log::error('Failed to dispatch welcome message job', [
                            'chat_id' => $chatId,
                            'language' => $language,
                            'error' => $queueError->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to send welcome message', [
                    'chat_id' => $chatId,
                    'language' => $language,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
