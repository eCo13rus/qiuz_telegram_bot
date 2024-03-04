<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;
use App\Models\UserState;
use App\Models\User;
use App\Models\Question;
use Illuminate\Support\Facades\Cache;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Стартовая команда, выводит инструкции';

    public function getName(): string
    {
        return $this->name;
    }

    public function handle()
    {
        Log::info('Начало обработки команды', ['command' => $this->getName()]);

        // Получаем айди пользователя из объекта сообщения
        $telegramUserId = $this->update->getMessage()->getFrom()->getId();
        $arguments = explode(' ', $this->update->getMessage()->getText());
        $utmSource = count($arguments) > 1 ? $arguments[1] : null;

        // Проверяем, существует ли пользователь в таблице `users`, если не то создаем
        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);

        // Теперь, когда у нас есть пользователь, мы можем безопасно обновить/добавить состояние в `user_states`
        UserState::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'start', 'utm_source' => $utmSource],
        );

        $this->replyWithChatAction(['action' => Actions::TYPING]);

        $chat_id = $this->getUpdate()->getMessage()->getChat()->getId();
        $currentQuestionId = Cache::get('chat_' . $chat_id . '_current_question_id', function () {
            return Question::first()->id;
        });
        $question = Question::with('answers')->find($currentQuestionId);
        $keyboard = $this->createQuestionKeyboard($question);

        $this->replyWithMessage([
            'text' => "Привет! 🤗\nЭто квиз-игра с нашим НейроТекстером.\nПроходи квиз до конца и в конце получай подарки🎁\n\n<strong>ВОПРОС #1\n\n{$question->text}\n</strong>\n<em>Выберите вариант ответа:</em>",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    // Вычисляем сколько нужно вывести кнопок с ответами
    public static function createQuestionKeyboard($question): array
    {
        $keyboard = [];
        $answers = $question->answers->toArray();

        // Если текущий вопрос имеет ID 36, выстраиваем кнопки вертикально
        if ($question->id == 66) {
            foreach ($answers as $answer) {
                $keyboard[] = [[
                    'text' => $answer['text'],
                    'callback_data' => "question_{$question->id}_answer_{$answer['id']}"
                ]];
            }
        } else {
            // Исходная логика для других вопросов
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
        }

        return $keyboard;
    }
}
