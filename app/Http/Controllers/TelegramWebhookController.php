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
            $updateId = $update['update_id'] ?? null;

            // Log incoming update (without sensitive data)
            Log::info('Telegram webhook received', [
                'update_id' => $updateId,
            ]);

            // Prevent duplicate webhook processing using update_id
            // Telegram guarantees that update_id is unique and monotonically increasing
            if ($updateId !== null) {
                $cacheKey = "telegram_update_{$updateId}";
                
                // Check if this update has already been processed
                if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                    Log::warning('Duplicate webhook update detected, skipping', [
                        'update_id' => $updateId,
                    ]);
                    
                    // Still return 200 OK to prevent Telegram from retrying
                    return response()->json(['ok' => true]);
                }
                
                // Mark this update as processed (cache for 24 hours)
                // Update IDs are unique, so we can safely cache them
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addHours(24));
            }

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
     * @param int|string|null $chatId Optional chat ID for sending messages (defaults to userId for private chats)
     * @return bool
     */
    private function checkSubscription(int|string $userId, string $language = 'en', ?string $chatType = null, int|string|null $chatId = null): bool
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
            // Use chatId if provided, otherwise use userId (for private chats)
            $targetChatId = $chatId ?? $userId;
            
            // Send subscription required message with missing channels info
            Log::info('User not subscribed, sending subscription required message', [
                'user_id' => $userId,
                'chat_id' => $targetChatId,
                'language' => $language,
                'missing_channels' => $missingChannels,
            ]);
            $this->telegramService->sendSubscriptionRequiredMessage($targetChatId, $language, $missingChannels);
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

        // Handle /start command (check for /start with or without bot username)
        // Also check if text starts with /start to catch variations like /start@botname
        if ($text && (str_starts_with($text, '/start') && (strlen($text) === 6 || $text[6] === ' ' || $text[6] === '@'))) {
            // Send language selection keyboard DIRECTLY (synchronous) for immediate response
            // Using queue causes delays and reliability issues
            try {
                Log::info('Sending language selection directly', [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
                
                $langText = "🌍 Please select your language:\nВыберите язык:\nTilni tanlang:";
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
                
                $messageId = $this->telegramService->sendMessageWithKeyboard($chatId, $langText, $keyboard);
                
                if ($messageId) {
                    Log::info('Language selection sent successfully', [
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                } else {
                    Log::warning('Language selection sent but no message ID returned', [
                        'chat_id' => $chatId,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send language selection', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            return; // CRITICAL: Return immediately to prevent URL processing
        }


        // Handle URL (skip if text is empty or is a command)
        if ($text && !str_starts_with($text, '/')) {
            // Get user's language preference
            $language = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", 'en');
            
            // Check subscription only for private chats (not for groups/supergroups)
            // In groups, subscription check is not required
            if (!$this->checkSubscription($userId, $language, $chatType)) {
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

            // Prevent duplicate job dispatch for the same message
            // Use chat_id + message_id as unique identifier for the message
            $jobCacheKey = "download_job_{$chatId}_{$messageId}_{$validatedUrl}";
            
            // Check if job for this message has already been dispatched
            if (\Illuminate\Support\Facades\Cache::has($jobCacheKey)) {
                Log::warning('Duplicate download job detected, skipping', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'url' => $validatedUrl,
                ]);
                return; // Skip duplicate job dispatch
            }
            
            // Mark this job as dispatched (cache for 1 hour to prevent duplicates)
            // This prevents the same URL from being processed multiple times for the same message
            \Illuminate\Support\Facades\Cache::put($jobCacheKey, true, now()->addHour());

            // Send "Downloading..." message IMMEDIATELY (before dispatching job)
            // This gives instant feedback to user
            $downloadingMessages = [
                'uz' => "⏳ <b>Yuklanmoqda...</b>\n\nIltimos, kuting. Media fayli tayyorlanmoqda.",
                'ru' => "⏳ <b>Загрузка...</b>\n\nПожалуйста, подождите. Медиа файл готовится.",
                'en' => "⏳ <b>Downloading...</b>\n\nPlease wait. Media file is being prepared.",
            ];
            
            $downloadingMessage = $downloadingMessages[$language] ?? $downloadingMessages['en'];
            
            // Send message immediately (synchronously) for instant feedback
            $downloadingMessageId = $this->telegramService->sendMessage(
                $chatId,
                $downloadingMessage,
                $messageId
            );

            // Dispatch job to queue for async processing
            // Pass downloading message ID so job can delete it later
            DownloadMediaJob::dispatch($chatId, $validatedUrl, $messageId, $language, $downloadingMessageId)
                ->onQueue('downloads');

            Log::info('Download job dispatched', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'url' => $validatedUrl,
                'downloading_message_id' => $downloadingMessageId,
                'job_cache_key' => $jobCacheKey,
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

            // Clear membership cache before checking to get fresh result
            $requiredChannels = $this->telegramService->getRequiredChannels();
            if (!empty($requiredChannels)) {
                $cacheKey = "channel_membership_{$userId}_" . md5(implode(',', $requiredChannels));
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
                Log::info('Cleared membership cache before subscription check', [
                    'user_id' => $userId,
                    'cache_key' => $cacheKey,
                ]);
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
                    
                    // Answer callback query directly (synchronous)
                    try {
                        $this->telegramService->answerCallbackQuery($callbackQueryId, $text, false);
                    } catch (\Exception $e) {
                        Log::warning('Failed to answer callback query', [
                            'callback_query_id' => $callbackQueryId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    
                    // Delete subscription message
                    if ($message && isset($message['message_id'])) {
                        try {
                            $this->telegramService->deleteMessage($chatId, $message['message_id']);
                        } catch (\Exception $e) {
                            Log::warning('Failed to delete subscription message', [
                                'chat_id' => $chatId,
                                'message_id' => $message['message_id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    
                    // Check if language is already selected
                    $selectedLanguage = \Illuminate\Support\Facades\Cache::get("user_lang_{$chatId}", null);
                    
                    if ($selectedLanguage) {
                        // Send welcome message DIRECTLY (synchronous) in selected language
                        Log::info('Sending welcome message after subscription check', [
                            'chat_id' => $chatId,
                            'language' => $selectedLanguage,
                        ]);
                        
                        $messages = [
                            'uz' => "Xush kelibsiz.\n\nInstagram yoki TikTok havolasini yuboring.\nMedia fayllar avtomatik tarzda yuklab beriladi.",
                            'ru' => "Добро пожаловать.\n\nОтправьте ссылку Instagram или TikTok.\nМедиа файлы загружаются автоматически.",
                            'en' => "Welcome.\n\nSend an Instagram or TikTok link.\nMedia files are downloaded automatically.",
                        ];

                        $welcomeMessage = $messages[$selectedLanguage] ?? $messages['en'];
                        $messageId = $this->telegramService->sendMessage($chatId, $welcomeMessage);
                        
                        if ($messageId) {
                            Log::info('Welcome message sent successfully after subscription check', [
                                'chat_id' => $chatId,
                                'language' => $selectedLanguage,
                                'message_id' => $messageId,
                            ]);
                        }
                    } else {
                        // Send language selection directly (synchronous)
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
                        } catch (\Exception $e) {
                            Log::error('Failed to send language selection after subscription check', [
                                'chat_id' => $chatId,
                                'error' => $e->getMessage(),
                            ]);
                        }
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
            // Clear membership cache to get fresh result
            $requiredChannels = $this->telegramService->getRequiredChannels();
            if (!empty($requiredChannels)) {
                $cacheKey = "channel_membership_{$userId}_" . md5(implode(',', $requiredChannels));
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
                Log::debug('Cleared membership cache before subscription check after language selection', [
                    'user_id' => $userId,
                    'cache_key' => $cacheKey,
                ]);
            }
            
            Log::info('Checking subscription after language selection', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'language' => $language,
                'chat_type' => $chatType,
            ]);
            
            if (!$this->checkSubscription($userId, $language, $chatType, $chatId)) {
                // Subscription required message will be sent by checkSubscription
                Log::info('Subscription check failed, waiting for user to subscribe', [
                    'user_id' => $userId,
                    'chat_id' => $chatId,
                ]);
                return;
            }
            
            Log::info('Subscription check passed after language selection', [
                'user_id' => $userId,
                'chat_id' => $chatId,
            ]);

            // If subscribed (or in group), send welcome message DIRECTLY (synchronous)
            // Using queue causes delays and reliability issues
            try {
                Log::info('Sending welcome message directly', [
                    'chat_id' => $chatId,
                    'language' => $language,
                ]);
                
                $messages = [
                    'uz' => "Xush kelibsiz.\n\nInstagram yoki TikTok havolasini yuboring.\nMedia fayllar avtomatik tarzda yuklab beriladi.",
                    'ru' => "Добро пожаловать.\n\nОтправьте ссылку Instagram или TikTok.\nМедиа файлы загружаются автоматически.",
                    'en' => "Welcome.\n\nSend an Instagram or TikTok link.\nMedia files are downloaded automatically.",
                ];

                $message = $messages[$language] ?? $messages['en'];
                $messageId = $this->telegramService->sendMessage($chatId, $message);
                
                if ($messageId) {
                    Log::info('Welcome message sent successfully', [
                        'chat_id' => $chatId,
                        'language' => $language,
                        'message_id' => $messageId,
                    ]);
                } else {
                    Log::warning('Welcome message sent but no message ID returned', [
                        'chat_id' => $chatId,
                        'language' => $language,
                    ]);
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
