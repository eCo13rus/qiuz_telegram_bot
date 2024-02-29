<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\SDXLService;
use Telegram\Bot\Objects\Update;
use App\Services\Telegram\CallbackQueryService\CallbackQueryService;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\FileUpload\InputFile;
use App\Services\Telegram\ServiceCheckSubscription\ServiceCheckSubscription;

class TelegramBotController extends Controller
{
    protected $SDXLService;
    protected $serviceCheckSubscription;
    protected $callbackQueryService;

    // Внедряем сервисы
    public function __construct(SDXLService $SDXLService, CallbackQueryService $callbackQueryService, ServiceCheckSubscription $serviceCheckSubscription)
    {
        $this->SDXLService = $SDXLService;
        $this->callbackQueryService = $callbackQueryService;
        $this->serviceCheckSubscription = $serviceCheckSubscription;
    }

    //Обрабатываем веб хук от кнопок телеграм
    public function processingWebhook(Request $request)
    {
        Log::info('Обновление Webhook в контроллере', ['update' => $request->all()]);

        $update = TelegramFacade::commandsHandler(true); // Получаем объект от обновлений Телеграм, сразу обработанный

        if ($update->isType('callback_query')) {
            $callbackQuery = $update->getCallbackQuery();
            // Проверяем, содержит ли callback_data префикс 'subscribed_'
            if (strpos($callbackQuery->getData(), 'subscribed_') === 0) {
                // Обрабатываем подписку
                $this->serviceCheckSubscription->handleSubscriptionCallback($callbackQuery);
            } else {
                // Обрабатываем другие типы callback-запросов
                $this->handleCallbackQuery($callbackQuery);
            }
        } elseif ($update->isType('message')) {
            // Обрабатываем обычные сообщения
            $this->sdxlMessage($update);
        }

        return response()->json(['status' => 'Ok']);
    }

    // Веб от кнопки подписки
    public function processCallback(Request $request)
    {
        Log::info('Обработка callback в контроллере', ['input' => $request->all()]);

        $update = TelegramFacade::commandsHandler(true);
        $callbackQuery = $update->getCallbackQuery();
        Log::info('data', ['callbackQuery' => $callbackQuery]);

        if ($callbackQuery) {
            $this->serviceCheckSubscription->handleSubscriptionCallback($callbackQuery);
        }

        return response()->json(['status' => 'success']);
    }

    // Обработка callback-запросов
    public function handleCallbackQuery(CallbackQuery $callbackQuery)
    {
        $this->callbackQueryService->handleCallbackQuery($callbackQuery);
    }

    protected function sdxlMessage(Update $update): void
    {
        // Получение экземпляра SDXLMessageService через Service Container
        $SdxlMessageService = app()->make(\App\Services\Telegram\SDXLMessageService\SDXLMessageService::class);
        $SdxlMessageService->handleMessage($update);
    }
}
