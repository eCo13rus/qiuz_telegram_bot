<?php

namespace App\Services\Telegram\SDXLCallbackService;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use Telegram\Bot\FileUpload\InputFile;
use Illuminate\Http\Request;

class SDXLCallbackService
{
     // Обрабатывает колбэк от SDXL API
     public function processDalleCallback(Request $request, $chatId)
     {
         Log::info("Получен колбэк от SDXL", ['chatId' => $chatId, 'requestData' => $request->all()]);
 
         $data = $request->json()->all();
 
         if (!isset($data['request_id'])) {
             Log::error("Отсутствует request_id в данных колбэка", ['data' => $data]);
             return response()->json(['error' => 'Отсутствует request_id'], 400);
         }
 
         switch ($data['status']) {
             case 'processing':
                 $this->handleProcessingStatus($data, $chatId);
                 break;
            //  case 'success':
            //      $this->handleSuccessStatus($data, $chatId);
            //      break;
             default:
                 Log::error("Некорректный статус в данных колбэка", ['data' => $data]);
                 TelegramFacade::sendMessage([
                     'chat_id' => $chatId,
                     'text' => 'Извините, произошла неизвестная ошибка.'
                 ]);
         }
 
         return response()->json(['status' => 'success']);
     }
 
     // Обрабатывает статус 'success' колбэка от SDXL API
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
}