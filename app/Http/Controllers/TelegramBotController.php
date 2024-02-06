<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\ChatGPTService;
use Telegram\Bot\Objects\Update;
use App\Services\Telegram\CallbackQueryService\CallbackQueryService;
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
        //Log::info('Webhook update', ['update' => $request->all()]);
        
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

    protected function chatGPTMessage(Update $update): void
    {
        // Получение экземпляра ChatGPTMessageService через Service Container
        $chatGPTMessageService = app()->make(\App\Services\Telegram\ChatGPTMessageService\ChatGPTMessageService::class);
        $chatGPTMessageService->handleMessage($update);
    }
}
