<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;
use App\Http\Controllers\ChatGPTController;
use App\Services;


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

// Route::get('/send-message', [TelegramBotController::class, 'sendMessage']);

// Route::post('/send-message', [TelegramBotController::class, 'sendMessage']);

Route::get('/telegram-webhook', [TelegramBotController::class, 'handleWebhook']);

Route::post('/telegram-webhook', [TelegramBotController::class, 'handleWebhook']);

Route::post('/chatgpt/callback/{chat_id}', [ChatGPTController::class, 'handleRequest'])->name('chatgpt.request');
