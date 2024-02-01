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

    public function handle()
    {
        $this->replyWithChatAction(['action' => Actions::TYPING]);

        $chat_id = $this->getUpdate()->getMessage()->getChat()->getId();

        $currentQuestionId = Cache::get('chat_' . $chat_id . '_current_question_id', function () {
            return Question::first()->id;
        });

        $question = Question::with('answers')->find($currentQuestionId);

        $keyboard = [];
        foreach ($question->answers as $answer) {
            $keyboard[] = [['text' => $answer->text, 'callback_data' => "question_{$question->id}_answer_{$answer->id}"]];
        }

        $this->replyWithMessage([
            'text'         => $question->text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);

    }
}
