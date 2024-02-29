<?php

namespace App\Services\Telegram\ServiceCheckSubscription;

use Telegram\Bot\Objects\CallbackQuery;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use App\Traits\ResultMessageTrait;

class ServiceCheckSubscription
{
    use ResultMessageTrait;
    // // Передаем пользователя как параметр
    public function generateDeepLink($telegramUserId): string
    {
        $channelName = env('TG_KANAL');
        return "https://t.me/{$channelName}?start={$telegramUserId}";
    }

    // Запрос к боту который в канале на наличие подписки юзера
    public function checkUserSubscription(int $userId): bool
    {
        Log::info('Проверка подписки для пользователя', ['id' => $userId]);

        $token = env('TG_BOT_TOKEN_KANAL');
        $channel = env('TG_KANAL');
        $url = "https://api.telegram.org/bot{$token}/getChatMember?chat_id={$channel}&user_id={$userId}";

        try {
            $response = Http::get($url);
            $data = $response->json();

            Log::info('Ответ от Telegram API', ['data' => $data]);

            if ($response->successful() && $data['ok']) {
                return in_array($data['result']['status'], ['member', 'administrator', 'creator']);
            }

            Log::error("Ошибка API Telegram при проверке подписки", ['error' => $data['description'] ?? 'Нет описания ошибки']);
        } catch (\Exception $e) {
            Log::error("Ошибка при запросе к Telegram API", ['exception' => $e->getMessage()]);
        }

        return false;
    }

    public function handleSubscriptionCallback(CallbackQuery $callbackQuery)
    {
        Log::info('Обработка callback-запроса подписки', ['input' => request()->all()]);

        $callbackData = $callbackQuery->getData();
        $userId = $callbackQuery->getFrom()->getId();

        // Проверяем, что callback_data соответствует действию подтверждения подписки
        if (strpos($callbackData, 'subscribed_') === 0) {
            $telegramId = str_replace('subscribed_', '', $callbackData);

            // Поиск пользователя по telegram_id
            $user = User::where('telegram_id', $telegramId)->first();

            if ($user) {
                // Проверка подписки пользователя
                $isSubscribed = $this->checkUserSubscription($userId);

                if ($isSubscribed) {
                    // Обновление статуса подписки в базе данных
                    try {
                        $user->update(['is_subscribed' => true]);
                        Log::info("Статус подписки обновлён", ['is_subscribed' => $user->is_subscribed]);
                        $confirmationMessage = "✅ Спасибо за подписку! Теперь ты полноправный участник нашего сообщества.";

                        // Отправка бонусного сообщения
                        $bonusMessage = "🤫 Делимся с тобой секретным сервисом «НейроХолст», который способен генерировать картинки на уровне DALL-E и Midjourney.\n\nТебе не понадобятся VPN, зарубежная карта и даже знание английского языка.\n\n👇 Пробуй прямо сейчас 👇\n\n      💰Это БЕСПЛАТНО💰 ";

                        $buttonUrlForNeuroHolst = route('neuroholst.redirect', ['userId' => $userId]);

                        TelegramFacade::sendMessage([
                            'chat_id' => $userId,
                            'text' => $bonusMessage,
                            'parse_mode' => 'HTML',
                            'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [[
                                        'text' => 'Попробовать и перейти',
                                        'url' => $buttonUrlForNeuroHolst,
                                    ]]
                                ]
                            ])
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Ошибка при обновлении статуса подписки пользователя: " . $e->getMessage());
                    }
                } else {
                    // Отправка сообщения с предложением подписаться, если проверка не пройдена
                    $confirmationMessage = "❗️ Кажется, ты ещё не подписался на наш канал. Пожалуйста, подпишись, чтобы получить дополнительный бонус.";
                    TelegramFacade::sendMessage([
                        'chat_id' => $userId,
                        'text' => $confirmationMessage,
                        'parse_mode' => 'HTML',
                    ]);
                }
            } else {
                Log::error("Пользователь с telegram_id {$telegramId} не найден.");
            }
        }
    }
}
