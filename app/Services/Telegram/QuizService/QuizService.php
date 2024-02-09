<?php

namespace App\Services\Telegram\QuizService;

use App\Telegram\Commands\QuizCommand;
use App\Models\UserState;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Keyboard\Keyboard;

class QuizService
{
    // Обрабатывает неправильный ответ пользователя.
    public function handleIncorrectAnswer(int $chatId): void
    {
        $text = 'Не правильно 😔. Подумайте еще раз.';
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    // Обрабатываем правильный ответ пользователя на вопрос.
    public function handleCorrectAnswer(User $user, int $currentQuestionId, int $chatId): void
    {
        // Логируем начало обработки правильного ответа
        Log::info("Начало обработки правильного ответа пользователя {$user->id} на вопрос {$currentQuestionId}");

        // Отправка объяснения текущего вопроса, если оно есть
        $this->sendCurrentQuestionExplanation($currentQuestionId, $chatId);

        // Отправка следующего вопроса или завершение квиза, если вопросы закончились
        if (!$this->sendNextQuestion($user, $currentQuestionId, $chatId)) {
            $this->completeQuiz($user, $chatId);
        }
    }

    // Обрабатывает правильный ответ пользователя, отправляя объяснение текущего вопроса и загружая следующий вопрос.
    protected function sendCurrentQuestionExplanation(int $currentQuestionId, int $chatId): void
    {
        $currentQuestion = Question::find($currentQuestionId);

        if (!empty($currentQuestion->explanation)) { // Проверяем, есть ли объяснение для текущего вопроса
            $explanationText = '<em>' . $currentQuestion->explanation . '</em>';
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $explanationText,
                'parse_mode' => 'HTML',
            ]);
            Log::info("Отправлено объяснение для вопроса {$currentQuestionId}");
        }
    }

    // Загружает следующий вопрос и если есть обновляет состояние пользователя
    protected function sendNextQuestion(User $user, int $currentQuestionId, int $chatId): bool
    {
        $nextQuestionId = Question::where('id', '>', $currentQuestionId)->min('id');

        // Проверяем, существует ли следующий вопрос
        $nextQuestionExists = Question::where('id', $nextQuestionId)->exists();

        if (!$nextQuestionExists) {
            return false; // Если следующего вопроса нет, завершаем квиз.
        }

        $nextQuestion = Question::with(['answers', 'pictures'])->find($nextQuestionId);
        if ($nextQuestion) {
            $text = '<strong>' . $nextQuestion->text . '</strong>' . PHP_EOL;
            $keyboard = QuizCommand::createQuestionKeyboard($nextQuestion);

            $this->sendQuestion($nextQuestion, $text, $keyboard, $chatId);

            UserState::updateOrCreate(
                ['user_id' => $user->id],
                ['state' => 'quiz_in_progress', 'current_question_id' => $nextQuestionId]
            );

            Log::info("Отправлен следующий вопрос {$nextQuestionId} пользователю {$user->id}");

            return true;
        }
        return false;
    }

    // Если у вопроса есть картинка, отправляет ее вместе с вопросом или только вопрос
    protected function sendQuestion(Question $question, string $text, array $keyboard, int $chatId): void
    {
        Log::info("Начало отправки вопроса", ['question_id' => $question->id, 'chat_id' => $chatId]);

        if ($question->pictures->isNotEmpty()) {
            $mediaGroup = collect();

            foreach ($question->pictures as $picture) {
                Log::info("Обработка изображения", ['picture_id' => $picture->id]);

                if ($picture->telegram_file_id) {
                    Log::info("Использование существующего telegram_file_id", 
                    ['telegram_file_id' => $picture->telegram_file_id]);

                    $mediaItem = [
                        'type' => 'photo',
                        'media' => $picture->telegram_file_id,
                    ];
                } else {
                    $imagePath = storage_path('app/public/' . $picture->path);
                    Log::info("Отправка нового изображения", ['image_path' => $imagePath]);
                    $mediaItem = [
                        'type' => 'photo',
                        'media' => InputFile::create($imagePath, basename($imagePath)),
                    ];
                }

                $mediaGroup->push($mediaItem);
            }

            Log::info("Отправка группы изображений", ['media_group_count' => $mediaGroup->count()]);
            TelegramFacade::sendMediaGroup([
                'chat_id' => $chatId,
                'media' => $mediaGroup,
            ]);
        }

        // Отправляем текст вопроса и клавиатуру отдельным сообщением после изображений
        Log::info("Отправка текстового сообщения с вопросом и клавиатурой");
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text, // Текст вопроса всегда отправляется, независимо от наличия изображений
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            'parse_mode' => 'HTML',
        ]);
        Log::info("Конец отправки вопроса");
    }


    // Завершает квиз и сбрасывает его состояние
    protected function completeQuiz(User $user, int $chatId): void
    {
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => '<strong>' . 'НейроТекстер' . '</strong>' . '

Кажется вы уже прониклись нейросетями. Самое время попробовать свои навыки в деле. Поможет вам в этом НейроТекстер. 

Сгенерируйте изображение собаки, которая катается на скейтборде по торговому центру.
            
Просто отправьте запрос сообщением и через минуту НейроТекстер пришлёт результат. Посмотрим, что у вас получится.

- С этим заданием по дефолту справятся все, то есть здесь не нужна система оценивания. 
            ',

            'parse_mode' => 'HTML',
        ]);

        UserState::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'quiz_completed', 'current_question_id' => null]
        );

        Log::info("Квиз завершен для пользователя {$user->id}");
    }
}
