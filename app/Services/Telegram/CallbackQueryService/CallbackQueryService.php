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

        Log::info("Handling callback query", ['callbackData' => $callbackData, 'chatId' => $chatId]);

        try {
            $parts = explode('_', $callbackData);
            if (count($parts) === 4 && $parts[0] === 'question') {
                $this->processCallbackData($parts, $chatId, $callbackQuery);
            }

            TelegramFacade::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            Log::error("Telegram response exception", ['message' => $e->getMessage(), 'chatId' => $chatId]);
            // Дополнительная логика обработки исключений...
        }
    }


    // Обрабатывает данные обратного вызова от Telegram, связанные с викториной.
    protected function processCallbackData(array $parts, int $chatId, CallbackQuery $callbackQuery): void
    {
        Log::info("Processing callback data", ['parts' => $parts, 'chatId' => $chatId]);

        $telegramUserId = $callbackQuery->getFrom()->getId();
        $currentQuestionId = (int) $parts[1];
        $currentAnswerId = (int) $parts[3];

        Log::debug("Callback data details", ['telegramUserId' => $telegramUserId, 'currentQuestionId' => $currentQuestionId, 'currentAnswerId' => $currentAnswerId]);

        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);
        Log::info("User fetched or created", ['userId' => $user->id]);

        // Проверка правильности ответа
        $isCorrect = Question::find($currentQuestionId)
            ->answers()
            ->where('id', $currentAnswerId)
            ->where('is_correct', true)
            ->exists();

        // Сохранение ответа пользователя
        $user->quizResponses()->create([
            'answer_id' => $currentAnswerId,
            'is_correct' => $isCorrect,
        ]);

        // Формирование текста сообщения
        $messageText = $isCorrect ? "✅ Верно!" : "❌ Неверно.";

        // Получение объяснения текущего вопроса, если оно есть и ответ правильный
        if ($isCorrect) {
            $explanationText = $this->quizService->getCurrentQuestionExplanation($currentQuestionId);
            if (!empty($explanationText)) {
                $messageText .= "\n\n" . $explanationText; // Добавляем объяснение к сообщению
            }
        }

        // Отправка сообщения пользователю
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'HTML',
        ]);

        // Проверка и отправка следующего вопроса или завершение викторины
        if (!$this->quizService->sendNextQuestion($user, $currentQuestionId, $chatId)) {
            $this->quizService->completeQuiz($user, $chatId);
        }
    }



    // // Обрабатывает неправильный ответ пользователя.
    // public function handleIncorrectAnswer(User $user, int $currentAnswerId, int $chatId): void
    // {

    //     Log::info("Обработка неправильного ответа", [
    //         'userId' => $user->id,
    //         'questionId' => $currentAnswerId
    //     ]);
    //     $text = '❌ Неверно.' . PHP_EOL;

    //     // Сохранение ответа пользователя с пометкой о том, что он правильный
    //     $user->quizResponses()->create([
    //         'answer_id' => $currentAnswerId,
    //         'is_correct' => false,
    //     ]);
    //     TelegramFacade::sendMessage([
    //         'chat_id' => $chatId,
    //         'text' => $text,
    //     ]);
    // }

    // // Обрабатываем правильный ответ пользователя на вопрос.
    // public function handleCorrectAnswer(User $user, int $currentAnswerId, int $chatId): void
    // {
    //     // Логируем начало обработки правильного ответа
    //     Log::info("Начало обработки правильного ответа пользователя {$user->id} на вопрос {$currentAnswerId}");

    //     Log::info("Обработка правильного ответа", [
    //         'userId' => $user->id,
    //         'questionId' => $currentAnswerId
    //     ]);

    //     // Подготовка текста о правильном ответе
    //     $correctAnswerText = "<strong>Ответ:" . PHP_EOL . PHP_EOL . "✅ Верно!" . "</strong>";

    //     // Сохранение ответа пользователя с пометкой о том, что он правильный
    //     $user->quizResponses()->create([
    //         'answer_id' => $currentAnswerId,
    //         'is_correct' => true,
    //     ]);

    //     // Получение объяснения текущего вопроса, если оно есть
    //     $explanationText = $this->quizService->getCurrentQuestionExplanation($currentAnswerId);

    //     // Формирование окончательного текста сообщения
    //     $finalMessageText = $correctAnswerText;
    //     if (!empty($explanationText)) {
    //         $finalMessageText .= PHP_EOL . PHP_EOL . $explanationText;
    //     }

    //     // Отправка сообщения пользователю
    //     TelegramFacade::sendMessage([
    //         'chat_id' => $chatId,
    //         'text' => $finalMessageText,
    //         'parse_mode' => 'HTML',
    //     ]);

    //     // Отправка следующего вопроса или завершение квиза, если вопросы закончились
    //     if (!$this->quizService->sendNextQuestion($user, $currentAnswerId, $chatId)) {
    //         $this->quizService->completeQuiz($user, $chatId);
    //     }
    // }
}
