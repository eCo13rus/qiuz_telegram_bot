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
        $keyboard = $this->createQuestionKeyboard($question);

        $this->replyWithMessage([
            'text' => $question->text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    public static function createQuestionKeyboard($question): array
    {
        $keyboard = [];

        $answers = $question->answers->toArray();

        // Первая строка кнопок
        $keyboard[] = [
            [
                'text' => $answers[0]['text'],
                'callback_data' => "question_{$question->id}_answer_{$answers[0]['id']}" // Callback данные первой кнопки
            ],
            [
                'text' => $answers[1]['text'],
                'callback_data' => "question_{$question->id}_answer_{$answers[1]['id']}" // Callback данные второй кнопки
            ]
        ];

        // Вторая строка кнопок
        $keyboard[] = [
            [
                'text' => $answers[2]['text'],
                'callback_data' => "question_{$question->id}_answer_{$answers[2]['id']}" // Callback данные третьей кнопки
            ],
            [
                'text' => $answers[3]['text'],
                'callback_data' => "question_{$question->id}_answer_{$answers[3]['id']}" // Callback данные четвертой кнопки
            ]
        ];

        return $keyboard;
    }
}
