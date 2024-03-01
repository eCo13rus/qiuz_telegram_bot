<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RedirectController extends Controller
{
    // Метод чекает переход пользователя на neuro-holst.ru и сохраняет в базе
    public function handleRedirect(Request $request, $userId)
    {
        Log::info('Перенаправление пользователя', ['userId' => $userId, 'requestData' => $request->all()]);

        $user = User::where('telegram_id', $userId)->first();
        if ($user) {
            $user->clicked_on_link = true;
            $user->save();

            Log::info('Статус clicked_on_link обновлён', ['userId' => $userId]);
            return redirect()->away('https://neuro-holst.ru/');
        } else {
            Log::error('Пользователь не найден', ['userId' => $userId]);
            return redirect('/');
        }
    }

    // Метод чекает переход пользователя на neuro-texter.ru и сохраняет в базе
    public function handleNeurotexterRedirect(Request $request, $userId)
    {
        Log::info('Перенаправление пользователя', ['userId' => $userId, 'requestData' => $request->all()]);

        $user = User::where('telegram_id', $userId)->first();
        if ($user) {
            $user->clicked_on_neurotexter_link = true;
            $user->save();

            Log::info('Статус clicked_on_neurotexter_link обновлён', ['userId' => $userId]);

            return redirect()->away('https://neuro-texter.ru/');
        } else {
            Log::error('Пользователь не найден', ['userId' => $userId]);
            return redirect('/');
        }
    }
}
