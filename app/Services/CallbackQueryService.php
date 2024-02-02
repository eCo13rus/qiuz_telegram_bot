<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Telegram\Commands\QuizCommand;
use App\Models\Question;
use Telegram\Bot\Objects\CallbackQuery;

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
            $this->processCallbackData($parts, $chatId);
        }

        TelegramFacade::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
    }

    //Метод определяет был ли выбран правильный ответ
    protected function processCallbackData(array $parts, int $chat_id):void
    {
        $currentQuestion_id = (int) $parts[1];
        $currentAnswer_id = (int) $parts[3];

        $is_correct = Question::find($currentQuestion_id)
            ->answers()
            ->where('id', $currentAnswer_id)
            ->where('is_correct', true)
            ->exists();

        if ($is_correct) {
            $nextQuestionId = Question::where('id', '>', $currentQuestion_id)->min('id');
            if ($nextQuestionId) {
                $nextQuestion = Question::with('answers')->find($nextQuestionId);
                $keyboard = QuizCommand::createQuestionKeyboard($nextQuestion);
                $text = 'Красавчик! Следующий вопрос:' . PHP_EOL . $nextQuestion->text;
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            } else {
                $text = 'Достойно! Прошел quiz.' . PHP_EOL . 'Теперь спроси что-нибудь у ChatGPT:';
                $reply_markup = null;
            }
            TelegramFacade::sendMessage([
                'chat_id' => $chat_id,
                'text' => $text,
                'reply_markup' => $reply_markup
            ]);
        } else {
            $text = 'Ну ты гонишь?. Попробуй еще раз. Нажми /quiz';
            TelegramFacade::sendMessage([
                'chat_id' => $chat_id,
                'text' => $text,
            ]);
        }
    }
}
