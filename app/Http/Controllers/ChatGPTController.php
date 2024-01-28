<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use Illuminate\Support\Facades\Cache;
use App\Providers\ChatGPTService; 
use Illuminate\Support\Facades\Log;


class ChatGPTController extends Controller
{
    protected $chatGPTService;

    public function __construct(ChatGPTService $chatGPTService)
    {
        $this->chatGPTService = $chatGPTService;
    }

    public function handleRequest(Request $request, $chatId)
    {
        $messageText = $request->input('message'); // Получаем текст сообщения от пользователя

        $response = $this->chatGPTService->ask($messageText, $chatId);
        Log::info('Ответ от ChatGPTService', ['response' => $response]);

        if (isset($response['choices'][0]['message']['content'])) {
            $responseText = 'Ответ от ChatGPT: ' . $response['choices'][0]['message']['content'];
        } else {
            $responseText = 'Извините, не удалось получить ответ от ChatGPT.';
        }

        TelegramFacade::sendMessage([
            'chat_id' => $chatId,
            'text' => $responseText,
        ]);


        Cache::pull('chat_' . $chatId . '_state');
    }
}
