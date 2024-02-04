<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramResponseException; // Импортируем исключение

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
            $this->replyWithChatAction(['action' => Actions::TYPING]);
            $this->replyWithMessage(['text' => 'Привет! Это квиз-игра с нашим ботом. Чтобы продолжить, выбери команду /quiz']);
        } catch (TelegramResponseException $e) {
            if ($e->getCode() == 403) {
                Log::error("Ошибка: бот был заблокирован пользователем. Исключение: {$e->getMessage()}");
            } else {
                Log::error("Произошла ошибка с Telegram API: {$e->getMessage()}");
            }
            // В этом месте можно добавить дополнительную логику обработки ошибки,
            // например, удалить пользователя из списка активных чатов, если такой есть.
        }
    }
}
