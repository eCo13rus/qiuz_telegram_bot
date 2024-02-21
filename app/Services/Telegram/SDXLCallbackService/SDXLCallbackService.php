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
        if ($this->sendImageToTelegram($imageUrl, $chatId)) {
            // Предполагаем, что 7-й вопрос это вопрос, на который отвечается изображением
            $user = User::where('telegram_id', $chatId)->first();
            if ($user) {
                $user->quizResponses()->create([
                    'question_id' => 7,
                    'is_image_response' => true,
                    'is_correct' => true,
                ]);
            }

            $this->sendQuizResults($chatId);
        }
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
            // Предполагается, что у вас есть сервис QuizService для обработки квизов
            $score = $this->quizService->calculateQuizResults($user);
            $resultMessages = $this->quizService->getResultMessage($score);

            $this->quizService->resetUserQuizResponses($user);

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
            }

            // Отправляем правильные ответы и дополнительную информацию.
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $resultMessages['additional'],
                'parse_mode' => 'HTML',
            ]);

            // Сброс состояния пользователя после отправки результатов
            $user->state()->update(['state' => 'initial_state']);

            Log::info('сброс состояния', ['user' => $user]);
        }
    }
}
