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
    // Обрабатывает неправильный ответ пользователя.
    public function handleIncorrectAnswer(int $chatId): void
    {
        $text = '❌ Неверно.' . PHP_EOL;
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

        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => "<strong>" . "Ответ:" . PHP_EOL .  PHP_EOL .  "✅ Верно!" . "</strong>" . PHP_EOL,
            'parse_mode' => 'HTML',
        ]);
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

        // Перебор всех изображений, связанных с вопросом.
        foreach ($question->pictures as $picture) {
            Log::info("Обрабатываем изображение для вопроса", ['question_id' => $question->id, 'picture_id' => $picture->id]);
            // Пытаемся получить telegram_file_id из базы данных или загружаем картинку и получаем её ID.
            $telegramFileId = $picture->telegram_file_id ?: $this->sendPictureAndGetFileId($picture, $chatId);
            // Если ID изображения получен, добавляем его в коллекцию для отправки.
            if ($telegramFileId) {
                $mediaGroup->push([
                    'type' => 'photo',
                    'media' => $telegramFileId,
                ]);
            }
        }
        // Если в коллекции есть изображения для отправки, оправляем их группой.
        if ($mediaGroup->isNotEmpty()) {
            Log::info("Отправка группы изображений", ['chat_id' => $chatId]);
            TelegramFacade::sendMediaGroup([
                'chat_id' => $chatId,
                'media' => $mediaGroup->toJson(), // Конвертация данных коллекции в формат JSON для отправки.
            ]);
        }

        // Отправка текста вопроса с клавиатурой ответов в том же чате.
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE), // Формирование inline клавиатуры.
            'parse_mode' => 'HTML', // Использование HTML тегов в тексте сообщения.
        ]);
        Log::info("Текст вопроса отправлен", ['chat_id' => $chatId, 'question_id' => $question->id]);
    }

    // Метод обрабатывает отправку изображения в Telegram и сохраняет полученный telegram_file_id если его нет в базу данных.
    protected function sendPictureAndGetFileId($picture, $chatId)
    {
        $imagePath = storage_path('app/public/' . $picture->path); // Получаем физический путь к файлу изображения.
        if (!file_exists($imagePath)) { // Если файл не существует, логируем ошибку.
            Log::error("Файл изображения не найден", ['imagePath' => $imagePath]);
            return null; // Прекращаем обработку и возвращаем null.
        }

        try {
            // Отправляем фото в чат Telegram и получаем ответ.
            $response = TelegramFacade::sendPhoto([
                'chat_id' => $chatId,
                'photo' => InputFile::create($imagePath, basename($imagePath)),
            ]);

            // Проверяем наличие фото в ответе и получаем telegram_file_id.
            if ($response && $response->getPhoto()) {
                $photos = $response->getPhoto();
                $telegramFileId = collect($photos)->last()->fileId; // Получаем последний telegram_file_id из списка фотографий.

                $picture->telegram_file_id = $telegramFileId; // Сохраняем telegram_file_id в объекте картинки.
                $picture->save(); // Сохраняем изменения в базе данных.
                Log::info("Фото успешно отправлено и telegram_file_id сохранен", ['picture_id' => $picture->id, 'telegram_file_id' => $telegramFileId]);

                return $telegramFileId; // Возвращаем telegram_file_id.
            }
        } catch (\Exception $e) {
            // В случае исключения логируем подробную информацию.
            Log::error("Ошибка при отправке изображения", ['exception' => $e->getMessage(), 'imagePath' => $imagePath]);
        }

        return null;
    }

    // Завершается квиз и сбрасывается состояние пользователя
    protected function completeQuiz(User $user, int $chatId): void
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
