<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramResponseException;
use App\Models\UserState;
use App\Models\User;

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
        try {
            Log::info('Начало обработки команды', ['command' => $this->getName()]);

            // Получаем айди пользователя из объекта сообщения
            $telegramUserId = $this->update->getMessage()->getFrom()->getId();

            // Проверяем, существует ли пользователь в таблице `users`, если не то создаем
            $user = User::firstOrCreate(['telegram_id' => $telegramUserId]);

            // Теперь, когда у нас есть пользователь, мы можем безопасно обновить/добавить состояние в `user_states`
            UserState::updateOrCreate(
                ['user_id' => $user->id],
                ['state' => 'start']
            );

            $this->replyWithChatAction(['action' => Actions::TYPING]);
            $this->replyWithMessage(['text' => "Привет! 🤗\nЭто квиз-игра с нашим ботом.\nЧтобы продолжить, используйте команду /quiz."]);
        } catch (TelegramResponseException $e) {
            if ($e->getCode() == 403) {
                Log::error("Ошибка: бот был заблокирован пользователем. Исключение: {$e->getMessage()}");
            } else {
                Log::error("Произошла ошибка с Telegram API: {$e->getMessage()}");
            }
        }
    }
}
