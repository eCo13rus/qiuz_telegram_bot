<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\Question;
use App\Providers\ChatGPTService;

class TelegramBotController extends Controller
{
    private const CHAT_ID = 5241343729;
    protected $chatGPTService;

    public function __construct(ChatGPTService $chatGPTService)
    {
        $this->chatGPTService = $chatGPTService;
    }

    private function askChatGPT($text, $chatId)
    {
        return $this->chatGPTService->ask($text, $chatId);
    }

    public function sendMessage(Request $request)
    {
        $message = $request->input('message', 'Привет!');

        Telegram::sendMessage([
            'chat_id' => self::CHAT_ID,
            'text' => $message,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Message sent'
        ]);
    }

    public function handleWebhook(Request $request)
    {
        $update = TelegramFacade::commandsHandler(true);

        if ($update->isType('callback_query')) {
            $callback_query = $update->getCallbackQuery();
            $callback_data = $callback_query->getData();
            $message = $callback_query->getMessage();
            $chat_id = $message->getChat()->getId();

            $parts = explode('_', $callback_data);
            if (count($parts) === 4 && $parts[0] === 'question') {
                $question_id = (int) $parts[1];
                $answer_id = (int) $parts[3];

                $is_correct = Question::find($question_id)
                    ->answers()
                    ->where('id', $answer_id)
                    ->where('is_correct', true)
                    ->exists();

                if ($is_correct) {
                    $nextQuestionId = Question::where('id', '>', $question_id)->min('id');
                    if ($nextQuestionId) {
                        $nextQuestion = Question::with('answers')->find($nextQuestionId);
                        $keyboard = [];
                        foreach ($nextQuestion->answers as $answer) {
                            $keyboard[] = [
                                ['text' => $answer->text, 'callback_data' => "question_{$nextQuestion->id}_answer_{$answer->id}"]
                            ];
                        }
                        $text = 'Правильно! Следующий вопрос:' . PHP_EOL . $nextQuestion->text;
                        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                        Cache::put('chat_' . $chat_id . '_current_question_id', $nextQuestionId, 60 * 60);
                    } else {
                        $text = 'Поздравляем! Вы прошли все вопросы quiz.' . PHP_EOL . 'Спросите что-нибудь у ChatGPT.';
                        $reply_markup = null;
                        Cache::put('chat_' . $chat_id . '_state', 'ask_chatgpt');
                    }
                    TelegramFacade::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => $text,
                        'reply_markup' => $reply_markup
                    ]);
                } else {
                    $text = 'Неправильно. Попробуйте еще раз. Нажмите /quiz чтобы начать заново.';
                    TelegramFacade::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => $text,
                    ]);
                }

                TelegramFacade::answerCallbackQuery(['callback_query_id' => $callback_query->getId()]);
            }
        } elseif ($update->isType('message')) {
            $message = $update->getMessage();
            $chat_id = $message->getChat()->getId();

            $state = Cache::get('chat_' . $chat_id . '_state');

            if ($state === 'ask_chatgpt') {
                $chat_id = $message->getChat()->getId();
                $response = $this->askChatGPT($message->getText(), $chat_id);

                if (isset($response['choices'][0]['message']['content'])) {
                    $responseText = $response['choices'][0]['message']['content'];
                } else {
                    $responseText = 'Извините, не удалось получить ответ от ChatGPT.';
                }

                Cache::put('chat_' . $chat_id . '_state', 'promo');

                TelegramFacade::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $responseText
                ]);
            } elseif ($state === 'promo') {
                $promoText = "Вот ваш промокод: QWERTY123" . PHP_EOL .
                    'Вот ваша ссылка на сайт: https://example.com';
                TelegramFacade::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $promoText
                ]);
                Cache::forget('chat_' . $chat_id . '_state');
            }
        }

        return response()->json(['status' => 'success']);
    }
}
