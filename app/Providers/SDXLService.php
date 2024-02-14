<?php

namespace App\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class SDXLService
{
    protected $client;
    protected $apiKey;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiKey = config('services.chatgpt.api_key');
    }

    // Запрос к ChatGPT
    // public function queryChatGPTApi(string $question, int $chatId): array
    // {
    //     Log::info("Выполняется запрос к gen-api.ru", ['question' => $question, 'chatId' => $chatId]);

    //     try {
    //         $messages = json_encode([
    //             [
    //                 'role' => 'user',
    //                 'content' => $question
    //             ]
    //         ]);

    //         $response = $this->client->post(
    //             'https://api.gen-api.ru/api/v1/networks/chat-gpt-4-turbo',
    //             [
    //                 'headers' => [
    //                     'Authorization' => 'Bearer ' . $this->apiKey,
    //                     'Accept' => 'application/json',
    //                 ],
    //                 'json' => [
    //                     'messages' => $messages,
    //                     'is_sync' => true,
    //                 ],
    //             ]
    //         );

    //         $bodyRaw = (string) $response->getBody();
    //         Log::info("Сырой ответ от gen-api.ru", ['responseBody' => $bodyRaw]);
    //         $body = json_decode($bodyRaw, true);

    //         $body = json_decode((string) $response->getBody(), true);

    //         Log::info("Успешный ответ от gen-api.ru", ['body' => $body]);

    //         return $body['output'] ?? $body;
    //     } catch (RequestException $e) {
    //         Log::error('Ошибка при запросе к gen-api.ru: ' . $e->getMessage());

    //         Log::error('Детали ошибки запроса', [
    //             'request' => $e->getRequest() ? (string) $e->getRequest()->getBody() : 'пустой боди',
    //             'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'пусто ответ',
    //         ]);

    //         return ([
    //             'error' => 'Ошибка при запросе к ChatGPT.',
    //             'details' => $e->getMessage()
    //         ]);
    //     }
    // }

    // // Обработка запрос от ChatGPT
    // public function handleRequest(string $question, int $chatId): string
    // {
    //     $response = $this->queryChatGPTApi($question, $chatId);

    //     try {
    //         $responseText = isset($response['choices'][0]['message']['content'])
    //             ? $response['choices'][0]['message']['content'] : 'Извините, не удалось получить ответ от ChatGPT.';

    //         return $responseText;
    //     } catch (\Exception $e) {
    //         Log::error('Error handling ChatGPT request', ['exception' => $e->getMessage()]);
    //         return 'Извините, произошла ошибка при обработке вашего запроса.';
    //     }
    // }

    public function queryDalleApi(string $prompt, int $chatId): array
    {
        $callbackUrl = "https://af8a1faee7ec00e6872ed2e68ed42be4.serveo.net/dalle-callback/" . $chatId;

        Log::info("Выполняется запрос к gen-api.ru для генерации изображения", ['prompt' => $prompt, 'chatId' => $chatId, 'callbackUrl' => $callbackUrl]);

        try {
            $response = $this->client->post(
                'https://api.gen-api.ru/api/v1/networks/sdxl',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'prompt' => $prompt,
                        'callback_url' => $callbackUrl,
                        'num_outputs' => 1,
                        'translate_input' => true,
                        'width' => 1024,
                        'height' => 1024,
                    ],
                ]
            );

            $bodyRaw = (string) $response->getBody();
            Log::info("Сырой ответ от gen-api.ru для генерации изображения", ['responseBody' => $bodyRaw]);

            $body = json_decode($bodyRaw, true);
            Log::info("Успешный ответ от gen-api.ru для генерации изображения", ['body' => $body]);

            if (!isset($body['request_id'])) {
                Log::error('Отсутствует request_id в ответе от gen-api.ru', ['responseBody' => $body]);
                return ['error' => 'Отсутствует request_id в ответе от gen-api.ru'];
            }

            return ['request_id' => $body['request_id']];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('Ошибка при запросе к gen-api.ru для генерации изображения: ' . $e->getMessage());
            return ['error' => 'Ошибка при запросе к gen-api.ru.', 'details' => $e->getMessage()];
        }
    }

    public function handleRequest(string $prompt, int $chatId): string
    {
        $response = $this->queryDalleApi($prompt, $chatId);

        if (isset($response['request_id'])) {
            // Возвращаем сообщение о том, что запрос на генерацию принят и находится в обработке
            return "Ваш запрос на генерацию изображения принят и находится в обработке. Мы отправим вам фото, как только оно будет готово.";
        } else {
            // Возвращаем сообщение об ошибке, если запрос на генерацию не был успешно обработан
            return "Извините, произошла ошибка при обработке вашего запроса на генерацию изображения.";
        }
    }
}