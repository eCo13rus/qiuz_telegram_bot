<?php

use Illuminate\Support\Facades\Route;
use App\Services\Telegram\SDXLCallbackService\SDXLCallbackService;
use App\Http\Controllers\TelegramBotController;
use App\Http\Controllers\RedirectController;

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

Route::get('/telegram-webhook', [TelegramBotController::class, 'processingWebhook'])->name('processingWebhook');

Route::post('/telegram-webhook', [TelegramBotController::class, 'processingWebhook'])->name('processingWebhook');

Route::get('/dalle-callback/{chat_id}', [SDXLCallbackService::class, 'processDalleCallback'])->name('dalle.callback');

Route::post('/dalle-callback/{chat_id}', [SDXLCallbackService::class, 'processDalleCallback'])->name('dalle.callback');

Route::get('/process-callback', [TelegramBotController::class, 'processCallback'])->name('processCallback');

Route::post('/process-callback', [TelegramBotController::class, 'processCallback'])->name('processCallback');

Route::get('/redirect/{userId}', [RedirectController::class, 'handleRedirect'])->name('neuroholst.redirect');

Route::get('/neurotexter-redirect/{userId}', [RedirectController::class, 'handleNeurotexterRedirect'])->name('neurotexter.redirect');
