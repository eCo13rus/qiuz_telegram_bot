<?php

namespace App\Services\Telegram\SDXLCallbackService;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use Telegram\Bot\FileUpload\InputFile;
use Illuminate\Http\Request;
use App\Services\Telegram\QuizService\QuizService;
use App\Models\User;
use App\Providers\SDXLService;
use App\Services\Telegram\ServiceCheckSubscription\ServiceCheckSubscription;
use App\Models\UserState;

class SDXLCallbackService
{
    public const TOTAL_QUESTIONS = 7;
    protected $quizService;
    protected $serviceCheckSubscription;
    protected $sdxlService;


    public function __construct(QuizService $quizService, ServiceCheckSubscription $serviceCheckSubscription, SDXLService $sdxlService)
    {
        $this->quizService = $quizService;
        $this->serviceCheckSubscription = $serviceCheckSubscription;
        $this->sdxlService = $sdxlService;
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
    protected function handleSuccessStatus($data, $chatId, $processingMessageId = null)
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

        // Отправляем изображение
        $this->sendImageToTelegram($imageUrl, $chatId, $processingMessageId);

        // Выводим кнопку "Вау круто а что дальше?"
        $this->sendNextStepButton($chatId);
    }

    // Добавляем после отправки изображения в методе handleSuccessStatus
    protected function sendNextStepButton($chatId)
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => 'Вау, круто, что дальше?🤔', 'callback_data' => 'show_quiz_results']]
            ]
        ];

        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Ну как, понравилось?😜',
            'reply_markup' => json_encode($keyboard)
        ]);
    }


    // Отправляет сгенерированное изображение в чат Telegram и удаляет сообщение об обработке
    protected function sendImageToTelegram($imageUrl, $chatId, $processingMessageId = null): bool
    {
        Log::info("Отправляем изображение в Telegram", ['imageUrl' => $imageUrl]);
        try {
            // Создаем экземпляр InputFile из URL изображения
            $photo = InputFile::create($imageUrl);
            TelegramFacade::sendPhoto([
                'chat_id' => $chatId,
                'photo' => $photo,
            ]);

            // Сначала находим пользователя по telegram_id
            $user = User::where('telegram_id', $chatId)->first();
            if ($user) {
                // Затем получаем состояние пользователя по его внутреннему user_id
                $userState = UserState::where('user_id', $user->id)->first();
                if ($userState) {
                    // Проверяем, есть ли сохраненный processing_message_id
                    $messageIdToDelete = $processingMessageId ?? $userState->processing_message_id;

                    Log::info('processingMessageId', ['processingMessageId' => $processingMessageId]);

                    // Если изображение успешно отправлено и существует ID сообщения для удаления, удаляем сообщение об обработке
                    if ($messageIdToDelete) {
                        $this->sdxlService->deleteProcessingMessage($chatId, $messageIdToDelete);
                        // Очищаем поле после использования
                        $userState->processing_message_id = null;
                        $userState->save();
                    }
                }
            }

            return true; // Изображение успешно отправлено
        } catch (\Exception $e) {
            Log::error("Ошибка при отправке изображения в Telegram", ['error' => $e->getMessage()]);
            return false; // Ошибка при отправке изображения
        }
    }

    // Отправляем результаты квиза пользователю
    public function sendQuizResults($chatId)
    {
        $user = User::where('telegram_id', $chatId)->first();

        if ($user) {
            $score = $this->quizService->calculateQuizResults($user);

            $totalQuestions = SDXLCallbackService::TOTAL_QUESTIONS;
            $resultMessages = $this->quizService->getResultMessage($score, $totalQuestions);

            // Получаем telegramFileId для изображения, соответствующего результату
            $telegramFileId = $this->quizService->fetchResultImage($score, $chatId);

            // Если изображение найдено, отправляем его к званию
            if ($telegramFileId) {
                TelegramFacade::sendPhoto([
                    'chat_id' => $chatId,
                    'photo' => $telegramFileId,
                    'caption' => $resultMessages['title'], 
                    'parse_mode' => 'HTML',
                ]);

                // После отправки результатов квиза и изображения обновляем столбец image_generated
                $user->state()->update(['state' => 'image_generated']);

                // Устанавливаем флаг `image_generated` для всех ответов пользователя на квиз
                $user->quizResponses()->update(['image_generated' => true]);
            }

            // Отправляем правильные ответы и дополнительную информацию.
            $fullMessageText = $resultMessages['additional'] . "\n\nПодробнее о НейроТекстере:";

            $buttonUrlForNeuroTexter = route('neurotexter.redirect', ['userId' => $user->telegram_id]);

            // Отправляем объединенное сообщение с кнопкой
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $fullMessageText,
                'disable_web_page_preview' => true,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [[
                            'text' => '👉 Скорее переходи 👈',
                            'url' => $buttonUrlForNeuroTexter,
                        ]]
                    ]
                ])
            ]);

            $message = "⛔️ Погоди! На этом приятные сюрпризы не кончаются!\n\nПодпишись на наш канал и подскажем тебе ещё один супер-полезный сервиc";

            // Отправляем сообщение с кнопками для подтверждения подписки
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🔔 Перейти на канал', 'url' => env('TG_CHANNEL')],
                            ['text' => '✅ Я уже подписался', 'callback_data' => 'subscribed_' . $user->telegram_id],
                        ]
                    ]
                ]),
            ]);
        }
    }
}
