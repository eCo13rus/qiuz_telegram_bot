<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\ChatGPTService;
use Telegram\Bot\Objects\Update;
use App\Services\Telegram\CallbackQueryService\CallbackQueryService;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\FileUpload\InputFile;


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
            $this->dalleMessage($update);
        }

        return response()->json(['status' => 'Ok']);
    }

    // Обрабатывает колбэк от SDXL API
    public function processDalleCallback(Request $request, $chatId)
    {
        Log::info("Получен колбэк от DALL-E 3", ['chatId' => $chatId, 'requestData' => $request->all()]);

        $data = $request->json()->all();

        if (!isset($data['request_id'])) {
            Log::error("Отсутствует request_id в данных колбэка", ['data' => $data]);
            return response()->json(['error' => 'Отсутствует request_id'], 400);
        }

        switch ($data['status']) {
            case 'processing':
                $this->handleProcessingStatus($data, $chatId);
                break;
            case 'success':
                $this->handleSuccessStatus($data, $chatId);
                break;
            default:
                Log::error("Некорректный статус в данных колбэка", ['data' => $data]);
                TelegramFacade::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Извините, произошла неизвестная ошибка.'
                ]);
        }

        return response()->json(['status' => 'success']);
    }

    // Обрабатывает статус 'success' колбэка от SDXLAPI
    protected function handleProcessingStatus($data, $chatId)
    {
        Log::info("Запрос все еще обрабатывается", ['data' => $data]);
        $imageUrl = $data['result'][0] ?? null;

        if ($imageUrl) {
            $this->sendImageToTelegram($imageUrl, $chatId);
        } else {
            TelegramFacade::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Ваше изображение все еще обрабатывается. Пожалуйста, подождите.'
            ]);
        }
    }

    // Отправляет изображение в чат Telegram
    protected function sendImageToTelegram($imageUrl, $chatId)
    {
        Log::info("Отправляем изображение в Telegram", ['imageUrl' => $imageUrl]);
        // Создаем экземпляр InputFile из URL изображения
        $photo = InputFile::create($imageUrl);
        TelegramFacade::sendPhoto([
            'chat_id' => $chatId,
            'photo' => $photo,
        ]);
    }

    // Обработка callback-запросов
    protected function handleCallbackQuery(CallbackQuery $callbackQuery)
    {
        $this->callbackQueryService->handleCallbackQuery($callbackQuery);
    }

    protected function dalleMessage(Update $update): void
    {
        // Получение экземпляра ChatGPTMessageService через Service Container
        $chatGPTMessageService = app()->make(\App\Services\Telegram\ChatGPTMessageService\ChatGPTMessageService::class);
        $chatGPTMessageService->handleMessage($update);
    }
}
