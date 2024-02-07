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

    // Вычисляем сколько нужно вывести кнопок с ответами
    public static function createQuestionKeyboard($question): array
    {
        $keyboard = [];
        $answers = $question->answers->toArray();

        for ($i = 0; $i < count($answers); $i += 2) {
            $row = [];

            if (isset($answers[$i])) {
                $row[] = [
                    'text' => $answers[$i]['text'],
                    'callback_data' => "question_{$question->id}_answer_{$answers[$i]['id']}"
                ];
            }

            if (isset($answers[$i + 1])) {
                $row[] = [
                    'text' => $answers[$i + 1]['text'],
                    'callback_data' => "question_{$question->id}_answer_{$answers[$i + 1]['id']}"
                ];
            }

            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }
        return $keyboard;
    }
}
