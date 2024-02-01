<?php

namespace App\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class ChatGPTService
{
    protected $client;
    protected $apiKey;

    public function __construct()
    {
        $this->client = new Client(); // Инициализация HTTP клиента
        $this->apiKey = config('services.chatgpt.api_key'); // Получаем API ключ из конфигурации
    }

    public function ask($question, $chatId)
    {
        Log::info("Выполняется запрос к gen-api.ru", ['question' => $question, 'chatId' => $chatId]);

        try {
            $messages = json_encode([
                [
                    'role' => 'user',
                    'content' => $question
                ]
            ]);

            $response = $this->client->post(
                'https://api.gen-api.ru/api/v1/networks/chat-gpt-4-turbo',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'messages' => $messages,
                        'is_sync' => true,
                    ],
                ]
            );

            $bodyRaw = (string) $response->getBody();
            Log::info("Сырой ответ от gen-api.ru", ['responseBody' => $bodyRaw]);
            $body = json_decode($bodyRaw, true);

            $body = json_decode((string) $response->getBody(), true);

            Log::info("Успешный ответ от gen-api.ru", ['body' => $body]);

            return $body['output'] ?? $body;
        } catch (RequestException $e) {
            Log::error('Ошибка при запросе к gen-api.ru: ' . $e->getMessage());

            Log::error('Детали ошибки запроса', [
                'request' => $e->getRequest() ? (string) $e->getRequest()->getBody() : 'пустой боди',
                'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'пусто ответ',
            ]);

            return response()->json([
                'error' => 'Ошибка при запросе к ChatGPT.',
                'details' => $e->getMessage()
            ], 422);
        }
    }

    public function handleRequest($question, $chatId)
    {
        $response = $this->ask($question, $chatId); // Используйте существующий метод ask для отправки вопроса

        try {
            $promoText = "Твой промокод: QWERTY123" . PHP_EOL .
                'Твоя ссылка на сайт: https://example.com 😁';

            $responseText = isset($response['choices'][0]['message']['content'])
                ? $response['choices'][0]['message']['content'] . "\n" . PHP_EOL . $promoText
                : 'Извините, не удалось получить ответ от ChatGPT.';

            return $responseText;
        } catch (\Exception $e) {
            Log::error('Error handling ChatGPT request', ['exception' => $e->getMessage()]);
            return 'Извините, произошла ошибка при обработке вашего запроса.';
        }
    }
}
