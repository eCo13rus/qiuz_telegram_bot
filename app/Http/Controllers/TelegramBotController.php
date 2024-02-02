<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\ChatGPTService;
use Telegram\Bot\Objects\Update;
use App\Services\CallbackQueryService;
use Nette\Utils\Callback;
use Telegram\Bot\Objects\CallbackQuery;

class TelegramBotController extends Controller
{
    protected $chatGPTService;

    protected $callbackQueryService;

    // Внедряем сервисы
    public function __construct(ChatGPTService $chatGPTService, CallbackQueryService $callbackQueryService)
    {
        $this->chatGPTService = $chatGPTService;
        $this->callbackQueryService = $callbackQueryService;
    }

    //Обрабатываем веб хук от телеграм
    public function processingWebhook(Request $request)
    {
        $update = TelegramFacade::commandsHandler(true); // получаем объект от обновлений Телеграм, сразу обработанный

        if ($update->isType('callback_query')) {
            $this->handleCallbackQuery($update->getCallbackQuery());
        } elseif ($update->isType('message')) {
            $this->ChatGPTMessage($update);
        }

        return response()->json(['status' => 'Ok']);
    }

    // Обработка callback-запросов
    protected function handleCallbackQuery(CallbackQuery $callbackQuery)
    {
        $this->callbackQueryService->handleCallbackQuery($callbackQuery);
    }

    //Обработка текстового сообщения от юзера ChatGPT
    protected function ChatGPTMessage(Update $update): void
    {
        $message = $update->getMessage();
        $chat_id = $message->getChat()->getId();

        $messageText = $message->getText();
        //$logMessage = json_encode($messageText);
        //Log::info('response', ['messageText' => $logMessage]);

        // Проверка, не является ли сообщение командой
        if (!$this->isCommand($messageText)) {
            $this->requestChatGPT($chat_id, $messageText);
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


    //В конце квиза юзер вводит сообщение ChatGPT,отправляеем запрос и возвращаем ответ пользователю.
    protected function requestChatGPT(int $chat_id, string $messageText): void
    {
        $responseText = $this->chatGPTService->handleRequest($messageText, $chat_id);

        TelegramFacade::sendMessage([
            'chat_id' => $chat_id,
            'text' => $responseText
        ]);
    }
}
