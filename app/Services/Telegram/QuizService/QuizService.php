<?php

namespace App\Services\Telegram\QuizService;

use App\Telegram\Commands\QuizCommand;
use App\Models\UserState;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class QuizService
{
    // Обрабатывает правильный ответ пользователя, отправляя объяснение текущего вопроса и загружая следующий вопрос.
    public function sendCurrentQuestionExplanation(int $currentQuestionId, int $chatId): void
    {
        $currentQuestion = Question::find($currentQuestionId);

        if (!empty($currentQuestion->explanation)) { // Проверяем, есть ли объяснение для текущего вопроса
            $explanationText = '<em>' . '🔸' . $currentQuestion->explanation . '</em>';
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $explanationText,
                'parse_mode' => 'HTML',
            ]);
            Log::info("Отправлено объяснение для вопроса {$currentQuestionId}");
        }
    }

    // Загружает следующий вопрос и если есть обновляет состояние пользователя
    public function sendNextQuestion(User $user, int $currentQuestionId, int $chatId): bool
    {
        $totalQuestions = Question::count(); // Получаем общее количество вопросов
        $questionIndex = Question::where('id', '<=', $currentQuestionId)->count() + 1; // Вычисляем индекс текущего вопроса

        $nextQuestionId = Question::where('id', '>', $currentQuestionId)->min('id');

        $nextQuestionExists = Question::where('id', $nextQuestionId)->exists();

        if (!$nextQuestionExists) {
            return false; // Если следующего вопроса нет, завершаем квиз
        }

        $nextQuestion = Question::with(['answers', 'pictures'])->find($nextQuestionId);
        if ($nextQuestion) {
            $text = '<strong>' . 'ВОПРОС #' . $questionIndex . PHP_EOL . PHP_EOL . $nextQuestion->text . PHP_EOL . '</strong>';
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

    // Метод для отправки вопроса пользователю и отправки изображений, связанных с вопросом.
    protected function sendQuestion(Question $question, string $text, array $keyboard, int $chatId): void
    {
        $mediaGroup = collect();
        $allImagesHaveIds = true;

        // Проверяем каждое изображение и определяем, нужно ли оно будет загружено.
        foreach ($question->pictures as $picture) {
            if (!$picture->telegram_file_id) {
                // Получаем id изображений.
                $this->fetchAndSaveTelegramFileId($picture, $chatId);
                $allImagesHaveIds = false; // Отмечаем, что некоторые изображения были загружены.
            }
            if ($picture->telegram_file_id) {
                // Добавляем изображение в группу, если у него уже есть telegram_file_id.
                $mediaGroup->push([
                    'type' => 'photo',
                    'media' => $picture->telegram_file_id,
                ]);
            }
        }

        // Отправляем группу изображений, если все изображения были уже загружены ранее или в этом сеансе.
        if ($mediaGroup->isNotEmpty() && $allImagesHaveIds) {
            TelegramFacade::sendMediaGroup([
                'chat_id' => $chatId,
                'media' => $mediaGroup->toJson(),
            ]);
        }

        // Отправка текста вопроса с клавиатурой ответов.
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
            'parse_mode' => 'HTML',
        ]);
    }

    // Если в базе нет сохраненных фото получаем локально по пути где они лежат и сохраняем их telegram_file_id 
    protected function fetchAndSaveTelegramFileId($picture, $chatId)
    {
        $imagePath = storage_path('app/public/' . $picture->path);
        if (file_exists($imagePath)) {
            try {
                // Отправляем фото в Telegram для получения telegram_file_id.
                $response = TelegramFacade::sendPhoto([
                    'chat_id' => $chatId,
                    'photo' => InputFile::create($imagePath, basename($imagePath)),
                ]);

                if ($response && $response->getPhoto()) {
                    // Получаем telegram_file_id из ответа и сохраняем в модель изображения.
                    $photos = $response->getPhoto();
                    $telegramFileId = collect($photos)->last()->fileId;
                    $picture->telegram_file_id = $telegramFileId;
                    $picture->save();
                }
            } catch (\Exception $e) {
                // Обработка исключений.
                Log::error("Exception while sending image: {$e->getMessage()}");
            }
        }
    }

    // Завершается квиз и сбрасывается состояние пользователя
    public function completeQuiz(User $user, int $chatId): void
    {
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => '<strong>' . 'ВОПРОС #7' . '

🤩 Кажется вы уже прониклись нейросетями. Самое время попробовать свои навыки в деле. Поможет вам в этом [НейроТекстер](https://neuro-texter.ru/). 

Сгенерируйте изображение собаки, которая катается на скейтборде по магазину. 
                            
🖥 Просто отправьте запрос сообщением и через минуту [НейроТекстер](https://neuro-texter.ru/) пришлёт результат. Посмотрим, что у вас получится.
            ' . '</strong>',

            'parse_mode' => 'HTML',
        ]);

        UserState::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'quiz_completed', 'current_question_id' => null]
        );

        Log::info("Квиз завершен для пользователя {$user->id}");
    }
}
