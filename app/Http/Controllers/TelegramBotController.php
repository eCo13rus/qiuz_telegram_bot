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
    //private const CHAT_ID = 5241343729;
    protected $chatGPTService;

    public function __construct(ChatGPTService $chatGPTService)
    {
        $this->chatGPTService = $chatGPTService;
    }

    private function askChatGPT($text, $chatId)
    {
        return $this->chatGPTService->ask($text, $chatId);
    }

    public function handleWebhook(Request $request)
    {
        $update = TelegramFacade::commandsHandler(true);

        if ($update->isType('callback_query')) {
            $this->handleCallbackQuery($update);
        } elseif ($update->isType('message')) {
            $this->handleMessage($update);
        }

        return response()->json(['status' => 'Ok']);
    }

    protected function handleCallbackQuery($update)
    {
        $callback_query = $update->getCallbackQuery();
        $callback_data = $callback_query->getData();
        $message = $callback_query->getMessage();
        $chat_id = $message->getChat()->getId();

        $parts = explode('_', $callback_data);
        if (count($parts) === 4 && $parts[0] === 'question') {
            $this->processQuizCallbackQuery($parts, $chat_id);
        }

        TelegramFacade::answerCallbackQuery(['callback_query_id' => $callback_query->getId()]);
    }

    protected function handleMessage($update)
    {
        $message = $update->getMessage();
        $chat_id = $message->getChat()->getId();
        $messageText = $message->getText();

        Log::info(['response' => $messageText]);

        // Проверка, не является ли сообщение командой
        if (!$this->isCommand($messageText)) {
            $this->requestChatGPT($chat_id, $messageText);
        }
    }

    protected function isCommand(string $messageText): bool
    {
        $commands = [
                    '/start', 
                    '/quiz',
                    ];

        return in_array($messageText, $commands);
    }

    protected function processQuizCallbackQuery($parts, $chat_id)
    {
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
                $text = 'Красачик! Следующий вопрос:' . PHP_EOL . $nextQuestion->text;
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            } else {
                $text = 'Достойно! Прошел quiz.' . PHP_EOL . 'Теперь спроси что-нибудь у ChatGPT:';
                $reply_markup = null;
            }
            TelegramFacade::sendMessage([
                'chat_id' => $chat_id,
                'text' => $text,
                'reply_markup' => $reply_markup
            ]);
        } else {
            $text = 'Ну ты гонишь?. Попробуй еще раз. Нажми /quiz';
            TelegramFacade::sendMessage([
                'chat_id' => $chat_id,
                'text' => $text,
            ]);
        }
    }

    protected function requestChatGPT($chat_id, $messageText)
    {
        try {
            $response = $this->askChatGPT($messageText, $chat_id);

            $promoText = "Вот ваш промокод: QWERTY123" . PHP_EOL .
                'Вот ваша ссылка на сайт: https://example.com';

            $responseText = $response['choices'][0]['message']['content'] . "\n" . PHP_EOL . $promoText ?? 'Извините, не удалось получить ответ от ChatGPT.';
        } catch (\Exception $e) {
            Log::error('Error asking ChatGPT', ['exception' => $e->getMessage()]);
            $responseText = 'Извините, произошла ошибка при запросе к ChatGPT.';
        }

        TelegramFacade::sendMessage([
            'chat_id' => $chat_id,
            'text' => $responseText
        ]);
    }
}
