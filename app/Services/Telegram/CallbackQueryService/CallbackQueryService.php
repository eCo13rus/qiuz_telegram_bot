<?php

namespace App\Services\Telegram\CallbackQueryService;

use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use Telegram\Bot\Objects\CallbackQuery;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use App\Services\Telegram\QuizService\QuizService;

class CallbackQueryService
{
    protected $quizService;

    public function __construct(QuizService $quizService)
    {
        $this->quizService = $quizService;
    }

    // Получаем информацию с кнопок(ответов пользователя)
    public function handleCallbackQuery(CallbackQuery $callbackQuery): void
    {
        $callbackData = $callbackQuery->getData();
        $message = $callbackQuery->getMessage();
        $chatId = $message->getChat()->getId();

        try {
            $parts = explode('_', $callbackData);
            if (count($parts) === 4 && $parts[0] === 'question') {
                // Передаем объект $callbackQuery как третий параметр
                $this->processCallbackData($parts, $chatId, $callbackQuery);
            }

            TelegramFacade::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            if (strpos($e->getMessage(), 'bot was blocked by the user') !== false) {
                Log::warning('Пользователь заблокировал бота при взаимодействии с inline-клавиатурой', ['chatId' => $chatId]);

                // Обновление статуса пользователя в базе данных как "неактивный"
                $user = User::where('telegram_id', $chatId)->first();
                if ($user) {
                    $user->update(['status' => 'неактивный']); // Предполагается, что у модели User есть атрибут status
                    Log::info('Статус пользователя обновлен на неактивный', ['userId' => $chatId]);
                }
            } else {
                throw $e; // Переброс других исключений для обработки в другом месте
            }
        }
    }

    // Обрабатывает данные обратного вызова от Telegram, связанные с викториной.
    protected function processCallbackData(array $parts, int $chatId, CallbackQuery $callbackQuery): void
    {
        // Извлечение данных пользователя и вопроса из обратного вызова
        $telegramUserId = $callbackQuery->getFrom()->getId();
        $currentQuestionId = (int) $parts[1];
        $currentAnswerId = (int) $parts[3];

        // Поиск или создание записи пользователя в БД
        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);

        // Проверка правильности ответа
        $isCorrect = Question::find($currentQuestionId)
            ->answers()
            ->where('id', $currentAnswerId)
            ->where('is_correct', true)
            ->exists();

        try {
            if ($isCorrect) {
                $this->quizService->handleCorrectAnswer($user, $currentQuestionId, $chatId);
            } else {
                $this->quizService->handleIncorrectAnswer($chatId);
            }
        } catch (QueryException $exception) {
            // Обработка исключения при возникновении ошибки запроса к БД
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, произошла ошибка. Пожалуйста, попробуйте ещё раз.'
            ]);
        }
    }
}
