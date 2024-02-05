<?php

namespace App\Http\Controllers;

use App\Models\UserState;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram as TelegramFacade;
use App\Providers\ChatGPTService;
use Telegram\Bot\Objects\Update;
use App\Services\Telegram\CallbackQueryService\CallbackQueryService;
use Telegram\Bot\Objects\CallbackQuery;

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
            $this->ChatGPTMessage($update);
        }

        return response()->json(['status' => 'Ok']);
    }

    // Обработка callback-запросов
    protected function handleCallbackQuery(CallbackQuery $callbackQuery)
    {
        $this->callbackQueryService->handleCallbackQuery($callbackQuery);
    }

    //Обработка текстового сообщения от юзера ChatGPT
    protected function ChatGPTMessage(Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId(); // Идентификатор в Telegram
        $messageText = $message->getText();

        Log::info('Received message', ['chatId' => $chatId, 'userId' => $userId, 'messageText' => $messageText]);

        if (!$this->isCommand($messageText)) {
            Log::debug('Trying to find or create user with telegram_id', ['telegram_id' => $userId]);

            // Попытка найти пользователя в базе данных по telegram_id или создать нового
            $user = User::firstOrCreate(['telegram_id' => $userId]);

            Log::info($user->wasRecentlyCreated ? 'New user registered' : 'Existing user', ['userId' => $userId]);

            $userState = $user->state()->first();

            if (is_null($userState) || $userState->state !== 'quiz_completed') {
                Log::warning('User has not completed the quiz', ['userId' => $userId]);
                TelegramFacade::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Пожалуйста, заверши викторину перед тем, как задать вопрос. Используй команду /quiz для продолжения.'
                ]);
            } else {
                Log::info('User completed the quiz, processing message', ['userId' => $userId]);
                $this->requestChatGPT($chatId, $messageText);
            }
        }
    }


    //Проверка на ввод команд или сообщения
    protected function isCommand(string $messageText): bool
    {
        $commandsClasses = config('telegram.bots.mybot.commands', []);
        foreach ($commandsClasses as $commandClass) {
            $commandInstance = new $commandClass; // Создание экземпляра класса команды
            if ($messageText === '/' . $commandInstance->getName()) { // Проверка на соответствие имени команды
                return true;
            }
        }
        return false;
    }

    //В конце квиза юзер вводит сообщение ChatGPT,отправляеем запрос и возвращаем ответ пользователю.
    protected function requestChatGPT(int $chat_id, string $messageText): void
    {
        $responseText = $this->chatGPTService->handleRequest($messageText, $chat_id);

        TelegramFacade::sendMessage([
            'chat_id' => $chat_id,
            'text' => $responseText
        ]);

        $user = User::where('telegram_id', $chat_id)->first();

        if ($user) {
            // Обновляем или создаем состояние пользователя, устанавливаем его в начальное состояние
            $initialState = 'initial_state'; // Замени это значение на фактическое начальное состояние
            UserState::updateOrCreate(
                ['user_id' => $user->id], // Условия поиска
                ['state' => $initialState] // Новые значения
            );

            Log::info('User state reset to initial after ChatGPT interaction', ['userId' => $user->id]);
        } else {
            Log::error('User not found when trying to reset state after ChatGPT interaction', ['chatId' => $chat_id]);
        }
    }
}
