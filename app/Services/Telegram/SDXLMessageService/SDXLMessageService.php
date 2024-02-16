<?php

namespace App\Services\Telegram\SDXLMessageService;

use App\Models\User;
use App\Models\UserState;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\SDXLService;
use Telegram\Bot\Objects\Update;

// Сервис для обработки текстовых сообщений от пользователей прохождения викторины.
class SDXLMessageService
{
    protected $sdxlService;

    public function __construct(SDXLService $sdxlService)
    {
        $this->sdxlService = $sdxlService;
    }

    //Обработка текстового сообщения от юзера SDXL и проверка его состония
    public function handleMessage(Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId(); // Идентификатор в Telegram
        $messageText = $message->getText();

        Log::info('Received message', ['chatId' => $chatId, 'userId' => $userId, 'messageText' => $messageText]);

        try {
            // Проверяем, что $messageText не null и не является командой
            if ($messageText !== null && !$this->isCommand($messageText)) {
                Log::debug('Смотрим если есть пользователь в базе', ['telegram_id' => $userId]);

                $user = User::firstOrCreate(['telegram_id' => $userId]);

                Log::info($user->wasRecentlyCreated ? 'Новый пользователь' : 'Existing user', ['userId' => $userId]);

                $userState = $user->state()->first();

                if (is_null($userState) || $userState->state !== 'quiz_completed') {
                    Log::warning('Не закончил квиз', ['userId' => $userId]);
                    TelegramFacade::sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Сначало завершите викторину перед тем, как сделать запрос.'
                    ]);
                } else {
                    Log::info('Завершил квиз', ['userId' => $userId]);
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
            } else {
                throw $e; // Переброс других исключений для обработки в другом месте
            }
        }
    }

    //Проверка на ввод команд или сообщения
    protected function isCommand(string $messageText): bool
    {
        $commandsClasses = config('telegram.bots.mybot.commands', []);
        foreach ($commandsClasses as $commandClass) {
            $commandInstance = new $commandClass; // Создание экземпляра класса команды
            if ($messageText === '/' . $commandInstance->getName()) { // Проверка на соответствие имени команды
                return true;
            }
        }
        return false;
    }

    //В конце квиза юзер вводит сообщение в SDXL на генерацию изображения,отправляеем запрос и возвращаем ответ пользователю.
    protected function requestSDXL(int $chat_id, string $messageText): void
    {
        $responseText = $this->sdxlService->handleRequest($messageText, $chat_id);

        TelegramFacade::sendMessage([
            'chat_id' => $chat_id,
            'text' => $responseText
        ]);

        $user = User::where('telegram_id', $chat_id)->first();

        if ($user) {
            // Обновляем или создаем состояние пользователя, устанавливаем его в начальное состояние
            $initialState = 'initial_state';
            UserState::updateOrCreate(
                ['user_id' => $user->id],
                ['state' => $initialState],
            );

            Log::info('Состояние пользователя сбрасывается после взаимодействия с SDXL', ['userId' => $user->id]);
        } else {
            Log::error('Пользователь не найден при попытке сбросить состояние', ['chatId' => $chat_id]);
        }
    }
}
