<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/telegram-webhook', [TelegramBotController::class, 'processingWebhook']);

Route::post('/telegram-webhook', [TelegramBotController::class, 'processingWebhook']);

Route::get('/dalle-callback/{chat_id}', [TelegramBotController::class, 'processDalleCallback'])->name('dalle.callback');

Route::post('/dalle-callback/{chat_id}', [TelegramBotController::class, 'processDalleCallback'])->name('dalle.callback');
