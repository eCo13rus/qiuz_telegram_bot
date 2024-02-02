<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Models\Question;
use App\Providers\ChatGPTService;
use App\Telegram\Commands\QuizCommand;
use Telegram\Bot\Objects\Update;

class TelegramBotController extends Controller
{
    protected $chatGPTService;

    // Внедряем зависимость
    public function __construct(ChatGPTService $chatGPTService)
    {
        $this->chatGPTService = $chatGPTService;
    }

    //Обрабатываем веб хук от телеграм
    public function processingWebhook(Request $request)
    {
        $update = TelegramFacade::commandsHandler(true); // получаем объект от обновлений, сразу обработанный

        if ($update->isType('callback_query')) {
            $this->handleCallbackQuery($update);
        } elseif ($update->isType('message')) {
            $this->ChatGPTMessage($update);
        }

        return response()->json(['status' => 'Ok']);
    }

    // Получаем информацию с кнопок(ответов пользователя)
    protected function handleCallbackQuery(Update $update): void
    {
        $callback_query = $update->getCallbackQuery();
        // $logData = json_encode($callback_query, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // Log::info("callback_query: {$logData}");


        $callback_data = $callback_query->getData();
        // Log::info('Callback Data:', (array) $callback_data);

        $message = $callback_query->getMessage();
        $chat_id = $message->getChat()->getId();

        $parts = explode('_', $callback_data);
        if (count($parts) === 4 && $parts[0] === 'question') {
            $this->callback_Request($parts, $chat_id);
        }

        TelegramFacade::answerCallbackQuery(['callback_query_id' => $callback_query->getId()]);
    }

    //Обработка текстового сообщения от юзера ChatGPT
    protected function ChatGPTMessage(Update $update): void
    {
        $message = $update->getMessage();
        $chat_id = $message->getChat()->getId();

        $messageText = $message->getText();
        //$logMessage = json_encode($messageText);
        //Log::info('response', ['messageText' => $logMessage]);

        // Проверка, не является ли сообщение командой
        if (!$this->isCommand($messageText)) {
            $this->requestChatGPT($chat_id, $messageText);
        }
    }

    //Проверка на ввод команд или сообщения
    protected function isCommand(string $messageText): bool
    {
        $commands = [
            '/start',
            '/quiz',
        ];

        return in_array($messageText, $commands);
    }

    //Метод определяет был ли выбран правильный ответ
    protected function callback_Request(array $parts, int $chat_id):void
    {
        $currentQuestion_id = (int) $parts[1];
        $currentAnswer_id = (int) $parts[3];

        $is_correct = Question::find($currentQuestion_id)
            ->answers()
            ->where('id', $currentAnswer_id)
            ->where('is_correct', true)
            ->exists();

        if ($is_correct) {
            $nextQuestionId = Question::where('id', '>', $currentQuestion_id)->min('id');
            if ($nextQuestionId) {
                $nextQuestion = Question::with('answers')->find($nextQuestionId);
                $keyboard = QuizCommand::createQuestionKeyboard($nextQuestion);
                $text = 'Красавчик! Следующий вопрос:' . PHP_EOL . $nextQuestion->text;
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

    //В конце квиза юзер вводит сообщение ChatGPT,отправляеем запрос и возвращаем ответ пользователю.
    protected function requestChatGPT(int $chat_id,string $messageText): void
    {
        $responseText = $this->chatGPTService->handleRequest($messageText, $chat_id); // Используйте новый метод handleRequest

        TelegramFacade::sendMessage([
            'chat_id' => $chat_id,
            'text' => $responseText
        ]);
    }
}
