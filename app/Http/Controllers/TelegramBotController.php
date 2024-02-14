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


class TelegramBotController extends Controller
{
    protected $SDXLService;

    protected $callbackQueryService;

    // Внедряем сервисы
    public function __construct(SDXLService $SDXLService, CallbackQueryService $callbackQueryService)
    {
        $this->SDXLService = $SDXLService;
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
            $this->sdxlMessage($update);
        }

        return response()->json(['status' => 'Ok']);
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
