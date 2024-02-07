<?php

namespace App\Services\Telegram\CallbackQueryService;

use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Telegram\Commands\QuizCommand;
use App\Models\Question;
use Telegram\Bot\Objects\CallbackQuery;
use App\Models\UserState;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Telegram\Bot\FileUpload\InputFile;

class CallbackQueryService
{
    // Получаем информацию с кнопок(ответов пользователя)
    public function handleCallbackQuery(CallbackQuery $callbackQuery): void
    {
        $callbackData = $callbackQuery->getData();
        $message = $callbackQuery->getMessage();
        $chatId = $message->getChat()->getId();

        try {
            $parts = explode('_', $callbackData);
            if (count($parts) === 4 && $parts[0] === 'question') {
                // Передаем объект $callbackQuery как третий параметр
                $this->processCallbackData($parts, $chatId, $callbackQuery);
            }

            TelegramFacade::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            if (strpos($e->getMessage(), 'bot was blocked by the user') !== false) {
                Log::warning('Пользователь заблокировал бота при взаимодействии с inline-клавиатурой', ['chatId' => $chatId]);

                // Обновление статуса пользователя в базе данных как "неактивный"
                $user = User::where('telegram_id', $chatId)->first();
                if ($user) {
                    $user->update(['status' => 'неактивный']); // Предполагается, что у модели User есть атрибут status
                    Log::info('Статус пользователя обновлен на неактивный', ['userId' => $chatId]);
                }
            } else {
                throw $e; // Переброс других исключений для обработки в другом месте
            }
        }
    }

    // Обрабатывает данные обратного вызова от Telegram, связанные с викториной.
    protected function processCallbackData(array $parts, int $chatId, CallbackQuery $callbackQuery): void
    {
        // Извлечение данных пользователя и вопроса из обратного вызова
        $telegramUserId = $callbackQuery->getFrom()->getId();
        $currentQuestionId = (int) $parts[1];
        $currentAnswerId = (int) $parts[3];

        // Поиск или создание записи пользователя в БД
        $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);

        // Проверка правильности ответа
        $isCorrect = Question::find($currentQuestionId)
            ->answers()
            ->where('id', $currentAnswerId)
            ->where('is_correct', true)
            ->exists();

        try {
            if ($isCorrect) {
                $this->handleCorrectAnswer($user, $currentQuestionId, $chatId);
            } else {
                $this->handleIncorrectAnswer($chatId);
            }
        } catch (QueryException $exception) {
            // Обработка исключения при возникновении ошибки запроса к БД
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, произошла ошибка. Пожалуйста, попробуйте ещё раз.'
            ]);
        }
    }

    // Обрабатываем правильный ответ пользователя на вопрос.
    protected function handleCorrectAnswer($user, $currentQuestionId, $chatId): void
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
    protected function sendCurrentQuestionExplanation($currentQuestionId, $chatId): void
    {
        $currentQuestion = Question::find($currentQuestionId);

        if (!empty($currentQuestion->explanation)) {
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
    protected function sendNextQuestion($user, $currentQuestionId, $chatId): bool
    {
        $nextQuestionId = Question::where('id', '>', $currentQuestionId)->min('id');
        $nextQuestion = Question::with(['answers', 'pictures'])->find($nextQuestionId);
        $nextQuestionStrong = '<strong>' . "{$nextQuestionId}{$nextQuestion}" . '</strong>' ;

        if ($nextQuestionStrong) {
            $text = $nextQuestion->text . PHP_EOL;
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

    // Если у вопроса есть картинка, отправляет ее вместе с вопросом или просто только вопрос
    protected function sendQuestion(Question $question, $text, $keyboard, $chatId): void
    {
        if ($question->pictures->isNotEmpty()) {
            $firstPicture = true;
            foreach ($question->pictures as $picture) {
                $imagePath = storage_path('app/public/' . $picture->path);

                if ($firstPicture) {
                    // Для первого изображения добавляем подпись с текстом вопроса
                    TelegramFacade::sendPhoto([
                        'chat_id' => $chatId,
                        'photo' => InputFile::create($imagePath, basename($imagePath)),
                        'caption' => $text,
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                        'parse_mode' => 'HTML',
                    ]);
                    $firstPicture = false;
                } else {
                    // Для остальных изображений отправляем без подписи
                    TelegramFacade::sendPhoto([
                        'chat_id' => $chatId,
                        'photo' => InputFile::create($imagePath, basename($imagePath)),
                        // Подпись и кнопки не добавляем
                    ]);
                }
            }
        } else {
            // Если у вопроса нет изображений, отправляем только вопрос
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                'parse_mode' => 'HTML',
            ]);
        }
    }

    // Завершает квиз и сбрасывает его состояние
    protected function completeQuiz($user, $chatId): void
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



    // Обрабатывает неправильный ответ пользователя.
    protected function handleIncorrectAnswer($chatId): void
    {
        $text = 'Не правильно 😔. Подумайте еще раз.';
        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
