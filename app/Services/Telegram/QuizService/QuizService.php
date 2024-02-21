<?php

namespace App\Services\Telegram\QuizService;

use App\Telegram\Commands\QuizCommand;
use App\Models\UserState;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Models\GeneralPicture;
use App\Services\Telegram\CallbackQueryService\CallbackQueryService;
use Illuminate\Support\Facades\View;

class QuizService
{
    protected $callbackQueryService;

    public function __construct(CallbackQueryService $callbackQueryService)
    {
        $this->callbackQueryService = $callbackQueryService;
    }

    // Загружает следующий вопрос и если есть обновляет состояние пользователя
    public function sendNextQuestion(User $user, int $currentQuestionId, int $chatId): bool
    {
        $questionIndex = Question::where('id', '<=', $currentQuestionId)->count() + 1; // Вычисляем индекс текущего вопроса

        $nextQuestionId = Question::where('id', '>', $currentQuestionId)->min('id');

        $nextQuestionExists = Question::where('id', $nextQuestionId)->exists();

        if (!$nextQuestionExists) {
            return false; // Если следующего вопроса нет, завершаем квиз
        }

        $nextQuestion = Question::with(['answers', 'pictures'])->find($nextQuestionId);
        if ($nextQuestion) {
            $text =  '<strong>' . 'ВОПРОС #' . $questionIndex . PHP_EOL . PHP_EOL . $nextQuestion->text . '</strong>';
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
        // Сначала отправляем текст вопроса.
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        $allImagesHaveIds = true;
        $mediaGroup = collect();

        // Перебираем изображения
        foreach ($question->pictures as $picture) {
            if (!$picture->telegram_file_id) {
                // Получаем id изображений и если надо сохраняем .
                $this->fetchAndSaveTelegramFileId($picture, $chatId);
                $allImagesHaveIds = false; // Отмечаем, что некоторые изображения были загружены.
            }
            if ($picture->telegram_file_id) {
                // Добавляем изображения в группу, если у него уже есть telegram_file_id.
                $mediaGroup->push([
                    'type' => 'photo',
                    'media' => $picture->telegram_file_id,
                ]);
            }
        }
        // Если есть изображения, то отправляем их в следующем сообщении.
        if ($mediaGroup->isNotEmpty()) {
            if ($allImagesHaveIds) {
                // Если все картинки уже загружены на сервера Telegram и имеют ID
                // Отправляем группу изображений.
                TelegramFacade::sendMediaGroup([
                    'chat_id' => $chatId,
                    'media' => $mediaGroup->toJson(),
                ]);
            }
        }

        // Отправка клавиатуры отдельным сообщением.
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => '<em>' . 'Выберите вариант ответа:' . '</em>',
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
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

                    return $telegramFileId;
                }
            } catch (\Exception $e) {
                // Обработка исключений.
                Log::error("Exception while sending image: {$e->getMessage()}");
            }
        }
        return null;
    }

    // Обновляет таблицу с ответами для текущего пользователя
    public function resetUserQuizResponses(User $user): void
    {
        $user->quizResponses()->delete();
        Log::info("Ответы пользователя {$user->id} сброшены.");
    }

    // Завершается квиз и сбрасывается состояние пользователя
    public function completeQuiz(User $user, int $chatId): void
    {
        Log::info("Завершение квиза для пользователя {$user->id} в чате {$chatId}");

        $messageContent = View::make('telegram.quiz_completed')->render();

        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageContent,
            'parse_mode' => 'HTML',
        ]);

        $score = $this->calculateQuizResults($user);

        // Отправка результата квиза и фото звания
        $resultMessages = $this->callbackQueryService->getResultMessage($score);

        // Отправляем текст с званием
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $resultMessages['title'],
            'parse_mode' => 'HTML',
        ]);

        // Отправляем фото, соответствующее званию
        $telegramFileId = $this->fetchResultImage($score, $chatId);
        if ($telegramFileId) {
            TelegramFacade::sendPhoto([
                'chat_id' => $chatId,
                'photo' => $telegramFileId,
            ]);
        }

        // Отправляем дополнительное сообщение
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $resultMessages['additional'],
            'parse_mode' => 'HTML',
        ]);

        // Сбрасываем предыдущие ответы
        $this->resetUserQuizResponses($user);

        UserState::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'quiz_completed', 'current_question_id' => null]
        );

        Log::info("Квиз завершен для пользователя {$user->id}");
    }

    //  Считает колличество правильных ответов
    public function calculateQuizResults(User $user): int
    {
        // Получаем количество правильных ответов, выбранных пользователем
        $correctAnswersCount = $user->quizResponses()
            ->where('is_correct', true)
            ->count();
        Log::info("Подсчет результатов квиза для пользователя {$user->id}. Правильных ответов: {$correctAnswersCount}");

        return $correctAnswersCount;
    }

    // Определяет звание пользователя и выдает соответствующее изображение
    protected function fetchResultImage(int $score, $chatId)
    {
        if ($score <= 2) {
            $imagePath = 'questions/photo6.jpeg'; // Для звания "Ученик"
        } elseif ($score <= 5) {
            $imagePath = 'questions/photo5.jpeg'; // Для звания "Уверенный юзер"
        } else {
            $imagePath = 'questions/photo4.jpeg'; // Для звания "Всевидящее око"
        }

        // Пытаемся найти изображение в базе данных
        $generalPicture = GeneralPicture::firstOrCreate(['path' => $imagePath]);

        // Если у изображения нет telegram_file_id, загружаем и сохраняем
        if (!$generalPicture->telegram_file_id) {
            // Загружаем изображение и получаем telegram_file_id, если его нет
            $telegramFileId = $this->fetchAndSaveTelegramFileId($generalPicture, $chatId);
            if ($telegramFileId) {
                return $telegramFileId; // Используем полученный telegram_file_id для отправки
            }
        } else {
            return $generalPicture->telegram_file_id; // Используем существующий telegram_file_id для отправки
        }

        return null;
    }
}
