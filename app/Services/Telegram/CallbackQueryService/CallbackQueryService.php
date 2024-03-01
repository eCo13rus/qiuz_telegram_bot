<?php

namespace App\Services\Telegram\CallbackQueryService;

use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use Telegram\Bot\Objects\CallbackQuery;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Services\Telegram\QuizService\QuizService;
use App\Traits\ResultMessageTrait;
use App\Services\Telegram\SDXLCallbackService\SDXLCallbackService;

class CallbackQueryService
{
    use ResultMessageTrait;

    protected $quizService;
    protected $sdxlCallbackService;

    public function __construct(QuizService $quizService, SDXLCallbackService $sdxlCallbackService)
    {
        $this->quizService = $quizService;
        $this->sdxlCallbackService = $sdxlCallbackService;
    }

    // Получаем информацию с кнопок(ответов пользователя)
    public function handleCallbackQuery(CallbackQuery $callbackQuery): void
    {
        Log::info('Вебхук с кнопок в handleCallbackQuery', ['input' => request()->all()]);

        $callbackData = $callbackQuery->getData();
        $message = $callbackQuery->getMessage();
        $chatId = $message->getChat()->getId();

        Log::info("Обработка запроса обратного вызова", ['callbackData' => $callbackData, 'chatId' => $chatId]);

        try {
            // Проверка callback_data для кнопки "Вау, круто, что дальше?"
            if ($callbackData === 'show_quiz_results') {
                // Вызов метода, который отправляет результаты квиза
                $this->sdxlCallbackService->sendQuizResults($chatId);
            } else {
                $parts = explode('_', $callbackData);
                if (count($parts) === 4 && $parts[0] === 'question') {
                    $this->processCallbackData($parts, $chatId, $callbackQuery);
                }
            }

            TelegramFacade::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            Log::error("Ответ от телеграм", ['message' => $e->getMessage(), 'chatId' => $chatId]);
        }
    }

    // Обрабатывает ответы пользователя и проверяет отвечал ли он уже на вопросы связанные с викториной.
    protected function processCallbackData(array $parts, int $chatId, CallbackQuery $callbackQuery): void
    {
        Log::info("Начало обработки callback данных", ['parts' => $parts, 'chatId' => $chatId]);

        // Извлечение данных пользователя и вопроса из callback-запроса
        $telegramUserId = $callbackQuery->getFrom()->getId();
        $currentQuestionId = (int) $parts[1];

        Log::debug("Детали callback данных", ['telegramUserId' => $telegramUserId, 'currentQuestionId' => $currentQuestionId]);

        // Получение или создание записи пользователя в базе данных
        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);
        Log::info("Пользователь получен или создан", ['userId' => $user->id]);

        // Проверка, отвечал ли пользователь на вопрос ранее
        if ($this->hasUserAlreadyResponded($user, $currentQuestionId, $chatId)) {
            return; // Если да, прекращаем дальнейшую обработку
        }

        // Переход к обработке ответа пользователя
        $this->handleUserResponse($parts, $user, $chatId);
    }

    // Обрабатывает ответ пользователя, сохраняет его и отправляет следующий вопрос или завершает викторину.
    protected function handleUserResponse(array $parts, User $user, int $chatId): void
    {
        $currentQuestionId = (int) $parts[1];
        $currentAnswerId = (int) $parts[3];

        // Проверка правильности ответа пользователя
        $isCorrect = Question::find($currentQuestionId)
            ->answers()
            ->where('id', $currentAnswerId)
            ->where('is_correct', true)
            ->exists();

        // Сохранение ответа пользователя
        $user->quizResponses()->create([
            'question_id' => $currentQuestionId,
            'answer_id' => $currentAnswerId,
            'is_correct' => $isCorrect,
        ]);

        // Формирование текста сообщения в зависимости от правильности ответа
        $messageText = $this->generateResponseMessage($isCorrect, $currentQuestionId);

        // Отправка сообщения пользователю
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'HTML',
        ]);

        // Отправка следующего вопроса или завершение викторины
        if (!$this->quizService->sendNextQuestion($user, $currentQuestionId, $chatId)) {
            $this->quizService->completeQuiz($user, $chatId);
        }
    }

    // Выводим сообщение в зависимости от правильности ответа и наличия объяснения.
    protected function generateResponseMessage(bool $isCorrect, int $currentQuestionId): string
    {
        $messageText = $isCorrect ? "✅ Верно!\n\n" : "❌ Неверно.\n";
        if (!$isCorrect) {
            $correctAnswer = Question::find($currentQuestionId)
                ->answers()
                ->where('is_correct', true)
                ->first();

            if ($correctAnswer) {
                $messageText .= "\n<strong>Правильный ответ: {$correctAnswer->text}</strong>\n\n";
            } else {
                $messageText .= "Не удалось найти правильный ответ.\n\n";
            }
        }

        // Добавление объяснения к ответу, если оно есть
        $explanationText = $this->getCurrentQuestionExplanation($currentQuestionId);
        if (!empty($explanationText)) {
            $messageText .= $explanationText;
        }

        return $messageText;
    }

    // Проверяет, отвечал ли пользователь уже на вопрос.
    protected function hasUserAlreadyResponded(User $user, int $currentQuestionId, int $chatId): bool
    {
        $previousResponse = $user->quizResponses()->where('question_id', $currentQuestionId)->first();
        if ($previousResponse) {
            $messageText = "Вы уже дали ответ на этот вопрос. Пожалуйста, ответьте на текущий 🥸.";
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $messageText,
                'parse_mode' => 'HTML',
            ]);
            return true;
        }
        return false;
    }

    // Отправляет объяснение текущего вопроса и загружая следующий вопрос.
    public function getCurrentQuestionExplanation(int $currentQuestionId): ?string
    {
        $currentQuestion = Question::find($currentQuestionId);

        if (!empty($currentQuestion->explanation)) {
            return "<em>🔸" . htmlspecialchars($currentQuestion->explanation) . "</em>";
        }
        return null;
    }
}
