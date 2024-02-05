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

        $parts = explode('_', $callbackData);
        if (count($parts) === 4 && $parts[0] === 'question') {
            // Передаем объект $callbackQuery как третий параметр
            $this->processCallbackData($parts, $chatId, $callbackQuery);
        }

        TelegramFacade::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
    }

    //Метод определяет состояние пользователя и был ли выбран правильный ответ
    protected function processCallbackData(array $parts, int $chatId, CallbackQuery $callbackQuery): void
    {
        $telegramUserId = $callbackQuery->getFrom()->getId();
        $currentQuestionId = (int) $parts[1];
        $currentAnswerId = (int) $parts[3];

        Log::info('Processing callback data', [
            'telegramUserId' => $telegramUserId,
            'currentQuestionId' => $currentQuestionId,
            'currentAnswerId' => $currentAnswerId,
        ]);

        // Использование firstOrCreate для предотвращения повторного создания пользователя
        Log::debug('Trying to find or create user with telegram_id', ['telegram_id' => $telegramUserId]);
        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);

        Log::info($user->wasRecentlyCreated ? 'New user action - registered' : 'User action - existing', ['telegramUserId' => $telegramUserId, 'user' => $user]);


        Log::info('User action', ['telegramUserId' => $telegramUserId, 'user' => $user]);

        // Проверка ответа пользователя
        $isCorrect = Question::find($currentQuestionId)
            ->answers()
            ->where('id', $currentAnswerId)
            ->where('is_correct', true)
            ->exists();

        Log::info('Answer checked', ['userId' => $user->id, 'isCorrect' => $isCorrect]);

        try {
            if ($isCorrect) {
                // Определение следующего вопроса или завершения викторины
                $nextQuestionId = Question::where('id', '>', $currentQuestionId)->min('id');
                $text = 'Красавчик!';
                $replyMarkup = null;

                if (!is_null($nextQuestionId)) {
                    $nextQuestion = Question::with('answers')->find($nextQuestionId);
                    $keyboard = QuizCommand::createQuestionKeyboard($nextQuestion);
                    $text .= ' Следующий вопрос:' . PHP_EOL . $nextQuestion->text;
                    $replyMarkup = json_encode(['inline_keyboard' => $keyboard]);

                    $state = 'quiz_in_progress';
                    $questionId = $nextQuestionId;
                } else {
                    $text .= ' Прошел quiz.' . PHP_EOL . 'Теперь спроси что-нибудь у ChatGPT:';
                    $state = 'quiz_completed';
                    $questionId = null; // Очищаем ID текущего вопроса
                }

                // Обновляем состояние пользователя
                UserState::updateOrCreate(
                    ['user_id' => $user->id],
                    ['state' => $state, 'current_question_id' => $questionId]
                );

                // Отправка сообщения пользователю
                TelegramFacade::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup
                ]);
            } else {
                $text = 'Ну ты гонишь? Попробуй еще раз. Нажми /quiz';
                Log::warning('User gave a wrong answer', ['userId' => $user->id]);
                TelegramFacade::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
            }
        } catch (QueryException $exception) {
            Log::error("Database error updating user state", ['exception' => $exception->getMessage(), 'userId' => $user->id]);
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, произошла ошибка. Пожалуйста, попробуйте ещё раз.'
            ]);
        }
    }
}
