<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;

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
        $this->replyWithChatAction(['action' => Actions::TYPING]);
        $this->replyWithMessage(['text' => 'Привет! Это квиз-игра с нашим ботом. Чтобы продолжить, выбери команду /quiz']);
    }
}
