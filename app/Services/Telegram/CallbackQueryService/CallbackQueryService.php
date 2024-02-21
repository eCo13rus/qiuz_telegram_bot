<?php

namespace App\Services\Telegram\CallbackQueryService;

use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use Telegram\Bot\Objects\CallbackQuery;
use App\Models\User;
use Illuminate\Support\Facades\Log;
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
        Log::info('Webhook hit', ['input' => request()->all()]);

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

    // Обрабатывает ответы пользователя, связанные с викториной.
    protected function processCallbackData(array $parts, int $chatId, CallbackQuery $callbackQuery): void
    {
        Log::info("Processing callback data", ['parts' => $parts, 'chatId' => $chatId]);

        $telegramUserId = $callbackQuery->getFrom()->getId();
        $currentQuestionId = (int) $parts[1];
        $currentAnswerId = (int) $parts[3];

        Log::debug("Callback data details", ['telegramUserId' => $telegramUserId, 'currentQuestionId' => $currentQuestionId, 'currentAnswerId' => $currentAnswerId]);

        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);
        Log::info("User fetched or created", ['userId' => $user->id]);

        $isCorrect = Question::find($currentQuestionId)
            ->answers()
            ->where('id', $currentAnswerId)
            ->where('is_correct', true)
            ->exists();

        $user->quizResponses()->create([
            'answer_id' => $currentAnswerId,
            'is_correct' => $isCorrect,
        ]);

        $messageText = $isCorrect ? "✅ Верно!" . PHP_EOL . PHP_EOL : "❌ Неверно.\n";

        if (!$isCorrect) {
            $correctAnswer = Question::find($currentQuestionId)
                ->answers()
                ->where('is_correct', true)
                ->first();

            if ($correctAnswer) {
                $messageText .= PHP_EOL . "<strong>Правильный ответ: " . $correctAnswer->text . "</strong>\n\n";
            } else {
                $messageText .= "Не удалось найти правильный ответ.\n\n";
            }
        }

        $explanationText = $this->getCurrentQuestionExplanation($currentQuestionId);
        if (!empty($explanationText)) {
            $messageText .= $explanationText;
        }

        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'HTML',
        ]);

        if (!$this->quizService->sendNextQuestion($user, $currentQuestionId, $chatId)) {
            $this->quizService->completeQuiz($user, $chatId);
        }
    }

    // Отправляет объяснение текущего вопроса и загружая следующий вопрос.
    public function getCurrentQuestionExplanation(int $currentQuestionId): ?string
    {
        $currentQuestion = Question::find($currentQuestionId);

        if (!empty($currentQuestion->explanation)) {
            return '<em>' . '🔸' . htmlspecialchars($currentQuestion->explanation) . '</em>';
        }
        return null;
    }

    // Выводит финольное сообщение с информацией
    public function getResultMessage(int $score): array
    {
        if ($score <= 2) {
            $result = '🤓 Ученик.';
        } elseif ($score <= 5) {
            $result = '😏 Уверенный юзер.';
        } else {
            $result = '😎 Всевидящее око.';
        }

        $titleMessage = "<strong>Твоё звание: $result</strong>\n\n";
        $additionalMessage = "Правильные ответы: $score<strong>\n\n😳 Неожиданные результаты, верно?</strong>" . "\n\nТеперь ты точно убедился, что нейросети - важная часть современного мира и сейчас самое время начать их изучать.\n\n🎁 А чтобы старт был легче, держи бонусные токены для <a href=\"https://neuro-texter.ru/\">НейроТекстера</a>.\n\nС ними ты сможешь создать курсовую, рекламный пост, стихотворение, картинку и много чего еще. <a href=\"https://neuro-texter.ru/\">👉Скорее переходи👈</a>";

        Log::info("Итоговое сообщение для пользователя сформировано: $result");

        return [
            'title' => $titleMessage,
            'additional' => "<strong>$additionalMessage</strong>"
        ];
    }
}
