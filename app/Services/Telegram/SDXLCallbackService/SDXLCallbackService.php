<?php

namespace App\Services\Telegram\SDXLCallbackService;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use Telegram\Bot\FileUpload\InputFile;
use Illuminate\Http\Request;
use App\Services\Telegram\QuizService\QuizService;
use App\Models\User;

class SDXLCallbackService
{
    protected $quizService;

    public function __construct(QuizService $quizService)
    {
        $this->quizService = $quizService;
    }
    // Обрабатывает колбэк от SDXL API
    public function processDalleCallback(Request $request, $chatId)
    {
        Log::info("Получен колбэк от SDXL", ['chatId' => $chatId, 'requestData' => $request->all()]);

        $data = $request->json()->all();

        if (!isset($data['request_id'])) {
            Log::error("Отсутствует request_id в данных колбэка", ['data' => $data]);
            return response()->json(['error' => 'Отсутствует request_id'], 400);
        }

        switch ($data['status']) {
            case 'processing':
                $this->handleProcessingStatus($data, $chatId);
                break;
            case 'success':
                $this->handleSuccessStatus($data, $chatId);
                break;
            default:
                Log::error("Некорректный статус в данных колбэка", ['data' => $data]);
                TelegramFacade::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Извините, произошла неизвестная ошибка.'
                ]);
        }

        return response()->json(['status' => 'success']);
    }

    // Обрабатывает статус 'processing' колбэка от SDXL API
    protected function handleProcessingStatus($data, $chatId)
    {
        Log::info("Запрос успешно обработан", ['data' => $data]);
        $imageUrl = $data['result'][0] ?? null;

        if ($imageUrl) {
            $this->sendImageToTelegram($imageUrl, $chatId);
        } else {
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Ваше изображение все еще обрабатывается. Пожалуйста, подождите.'
            ]);
        }
    }

    // Обрабатывает статус 'success' колбэка от SDXL API
    protected function handleSuccessStatus($data, $chatId)
    {
        Log::info("Запрос успешно обработан", ['data' => $data]);

        // Проверяем наличие результатов в ответе
        if (!isset($data['result']) || empty($data['result'])) {
            Log::error("Отсутствуют результаты в данных успешного колбэка", ['data' => $data]);
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, не удалось получить результат.'
            ]);
            return;
        }

        $imageUrl = $data['result'][0];

        // После успешной отправки изображения, отправляем результаты квиза
        $this->sendImageToTelegram($imageUrl, $chatId);

        // И выводим результаты
        $this->sendQuizResults($chatId);
    }

    // Отправляет изображение в чат Telegram
    protected function sendImageToTelegram($imageUrl, $chatId): bool
    {
        Log::info("Отправляем изображение в Telegram", ['imageUrl' => $imageUrl]);

        try {
            // Создаем экземпляр InputFile из URL изображения
            $photo = InputFile::create($imageUrl);
            TelegramFacade::sendPhoto([
                'chat_id' => $chatId,
                'photo' => $photo,
            ]);

            return true; // Изображение успешно отправлено
        } catch (\Exception $e) {
            Log::error("Ошибка при отправке изображения в Telegram", ['error' => $e->getMessage()]);
            return false; // Ошибка при отправке изображения
        }
    }

    // Отправляем результаты квиза пользователю
    protected function sendQuizResults($chatId)
    {
        $user = User::where('telegram_id', $chatId)->first();

        if ($user) {
            $score = $this->quizService->calculateQuizResults($user);
            $resultMessages = $this->quizService->getResultMessage($score);

            // Получаем telegramFileId для изображения, соответствующего результату
            $telegramFileId = $this->quizService->fetchResultImage($score, $chatId);

            // Отправляем звание
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $resultMessages['title'],
                'parse_mode' => 'HTML',
            ]);

            // Если изображение найдено, отправляем его к званию
            if ($telegramFileId) {
                TelegramFacade::sendPhoto([
                    'chat_id' => $chatId,
                    'photo' => $telegramFileId,
                ]);

                // После отправки результатов квиза и изображения обновляем столбец image_generated
                $user->state()->update(['state' => 'image_generated']);

                // Устанавливаем флаг `image_generated` для всех ответов пользователя на квиз
                $user->quizResponses()->update(['image_generated' => true]);
            }

            // Отправляем правильные ответы и дополнительную информацию.
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $resultMessages['additional'],
                'parse_mode' => 'HTML',
            ]);

            $deepLink = $this->generateDeepLink($user->telegram_id);
            Log::info('id', ['id' => $deepLink]);

            // Отправка сообщения с призывом к действию
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => "🚀 Хочешь получить больше полезного контента? Подпишись на наш <a href=\"{$deepLink}\">канал</a>",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🔔 Перейти на канал', 'url' => $deepLink],
                            ['text' => '✅ Я уже подписался', 'callback_data' => 'subscribed_' . $user->id],
                        ]
                    ]
                ]),
            ]);
        }
    }

    // Передаем пользователя как параметр
    public function generateDeepLink($telegramUserId): string
    {
        return getenv('TG_KANAL') . "?start={$telegramUserId}";
    }

    //Обработка ответа от бота из канала
    //Обработка ответа от бота из канала
    public function handleChannelBotResponse(Request $request)
    {
        Log::info('Полученный запрос в handleChannelBotResponse', ['data' => $request->all()]);

        $callbackData = $request->input('data');
        $chatId = $request->input('chatId');

        Log::info('Извлечение данных запроса', ['callbackData' => $callbackData, 'chatId' => $chatId]);

        if ($callbackData === 'subscribed_1') {
            Log::info('Проверка подписки: true', ['chatId' => $chatId]);

            try {
                $user = User::where('telegram_id', $chatId)->firstOrFail();
                Log::info('Пользователь найден', ['userId' => $user->id]);

                $user->is_subscribed = 1; // Возможно, способ обновления атрибута не срабатывает корректно. Попробуйте установить значение явно.

                if ($user->save()) {
                    Log::info('Статус подписки успешно обновлён', ['userId' => $user->id]);
                } else {
                    Log::error('Ошибка: save() был вызван, но статус подписки не обновлен', ['userId' => $user->id]);
                }
            } catch (\Exception $e) {
                Log::error('Ошибка при работе с базой данных', ['exception' => $e->getMessage()]);
            }
        } else {
            Log::info('callbackData не соответствует ожидаемому значению', ['callbackData' => $callbackData]);
        }

        return response()->json(['success' => true]);
    }
}
