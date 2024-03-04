<?php

namespace App\Services\Telegram\SDXLMessageService;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\SDXLService;
use App\Services\Telegram\QuizService\QuizService;
use Telegram\Bot\Objects\Update;

// Сервис для обработки текстовых сообщений от пользователей прохождения викторины.
class SDXLMessageService
{
    protected $sdxlService;
    protected $quizService;

    public function __construct(SDXLService $sdxlService, QuizService $quizService)
    {
        $this->sdxlService = $sdxlService;
        $this->quizService = $quizService;
    }

    //Обработка текстового сообщения от юзера SDXL и проверка его состония
    public function handleMessage(Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId(); // Идентификатор в Telegram
        $messageText = $message->getText();

        // Проверяем, содержит ли текст сообщения команду /start с параметрами
        if ($this->isStartCommandWithParameter($messageText)) {
            // Если содержит, то пропускаем обработку этого сообщения
            return;
        }

        Log::info('Получено сообщение', ['chatId' => $chatId, 'userId' => $userId, 'messageText' => $messageText]);

        try {
            // Проверяем, что $messageText не null и не является командой
            if ($messageText !== null && !$this->isCommand($messageText)) {
                Log::debug('Смотрим если есть пользователь в базе', ['telegram_id' => $userId]);

                $user = User::firstOrCreate(['telegram_id' => $userId]);

                Log::info($user->wasRecentlyCreated ? 'Новый пользователь' : 'Existing user', ['userId' => $userId]);

                $userState = $user->state()->first();

                if ($userState && $userState->state === 'image_generated') {
                    TelegramFacade::sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Вы уже делали запрос на генерацию изображения 🙄.',
                        'parse_mode' => 'HTML',
                    ]);
                } elseif (is_null($userState) || $userState->state !== 'quiz_completed') {
                    Log::warning('Не закончил квиз', ['userId' => $userId]);
                    TelegramFacade::sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Сначало завершите викторину перед тем, как сделать запрос. 🤓'
                    ]);
                } else {
                    Log::info('Завершил квиз и делает запрос на генерацию изображения', ['userId' => $userId]);

                    // Генерация и отправка изображения
                    $this->requestSDXL($chatId, $messageText);
                }
            }
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            if (strpos($e->getMessage(), 'bot was blocked by the user') !== false) {
                Log::warning('Пользователь заблокировал бота', ['userId' => $userId]);

                // Обновление статуса пользователя в базе данных как "неактивный"
                $user = User::where('telegram_id', $userId)->first();
                if ($user) {
                    $user->update(['status' => 'неактивный']);
                    Log::info('Статус пользователя обновлен на неактивный', ['userId' => $userId]);
                }
            }
        }
    }

    // Проверяет есть ли после старт параметры
    protected function isStartCommandWithParameter(string $messageText): bool
    {
        $parts = explode(' ', $messageText);
        return $parts[0] === '/start' && count($parts) > 1;
    }

    //Проверка на ввод команд или сообщения
    protected function isCommand(string $messageText): bool
    {
        // Разделяем текст сообщения по пробелам
        $parts = explode(' ', $messageText);

        // Берём первую часть сообщения, которая должна быть командой
        $commandText = $parts[0];

        $commandsClasses = config('telegram.bots.mybot.commands', []);
        foreach ($commandsClasses as $commandClass) {
            $commandInstance = new $commandClass; // Создание экземпляра класса команды
            if ($commandText === '/' . $commandInstance->getName()) { // Проверка на соответствие имени команды
                return true;
            }
        }
        return false;
    }

    //В конце квиза юзер вводит сообщение в SDXL на генерацию изображения,отправляем запрос и возвращаем ответ пользователю.
    protected function requestSDXL(int $chatId, string $messageText): void
    {
        Log::info('Запрос SDXL на генерацию изображения', ['chatId' => $chatId, 'messageText' => $messageText]);
        $response = $this->sdxlService->handleRequest($messageText, $chatId);

        if (isset($response['text'])) {
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => $response['text']
            ]);
        }
    }
}
