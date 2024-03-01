<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;
use App\Models\Question;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QuizCommand extends Command
{
    protected string $name = 'quiz';

    protected string $description = 'Начать квиз';

    public function getName(): string
    {
        return $this->name;
    }

    public function handle()
    {
        // Log::info('Начало обработки команды', ['command' => $this->getName()]);
        $this->replyWithChatAction(['action' => Actions::TYPING]);

        $chat_id = $this->getUpdate()->getMessage()->getChat()->getId();
        $currentQuestionId = Cache::get('chat_' . $chat_id . '_current_question_id', function () {
            return Question::first()->id;
        });

        $question = Question::with('answers')->find($currentQuestionId);
        $keyboard = $this->createQuestionKeyboard($question);

        $this->replyWithMessage([
            'text' => "<strong>ВОПРОС #1\n\n{$question->text}\n</strong>\n<em>Выберите вариант ответа:</em>",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
