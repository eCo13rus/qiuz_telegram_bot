<?php

namespace App\Services\Telegram\CallbackQueryService;

use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Telegram\Commands\QuizCommand;
use App\Models\Question;
use Telegram\Bot\Objects\CallbackQuery;
use App\Models\UserState;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class CallbackQueryService
{
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
                $this->handleCorrectAnswer($user, $currentQuestionId, $chatId);
            } else {
                $this->handleIncorrectAnswer($chatId);
            }
        } catch (QueryException $exception) {
            // Обработка исключения при возникновении ошибки запроса к БД
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, произошла ошибка. Пожалуйста, попробуйте ещё раз.'
            ]);
        }
    }

    // Обрабатывает правильный ответ пользователя на вопрос викторины.
    protected function handleCorrectAnswer($user, $currentQuestionId, $chatId): void
    {
        // Определение ID следующего вопроса
        $nextQuestionId = Question::where('id', '>', $currentQuestionId)->min('id');
        $text = 'Молодец! 😊';
        $replyMarkup = null;

        if (!is_null($nextQuestionId)) {
            // Получение следующего вопроса и создание клавиатуры с ответами
            $nextQuestion = Question::with('answers')->find($nextQuestionId);
            $keyboard = QuizCommand::createQuestionKeyboard($nextQuestion);
            $text .= ' Следующий вопрос:' . PHP_EOL . $nextQuestion->text;
            $replyMarkup = json_encode(['inline_keyboard' => $keyboard]);

            $state = 'quiz_in_progress';
            $questionId = $nextQuestionId;
        } else {
            $text .= ' Вы прошли quiz. 🥳' . PHP_EOL . 'Теперь спроси что-нибудь у ChatGPT:';
            $state = 'quiz_completed';
            $questionId = null; // Очищаем ID текущего вопроса
        }

        // Обновляем состояние пользователя
        UserState::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => $state, 'current_question_id' => $questionId]
        );

        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup
        ]);
    }

    // Обрабатывает неправильный ответ пользователя.
    protected function handleIncorrectAnswer($chatId): void
    {
        $text = 'Не правильно 😔. Подумайте еще раз.';
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
