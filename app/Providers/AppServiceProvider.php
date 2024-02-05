<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB; // Добавь это
use Illuminate\Support\Facades\Log; // И это
use Telegram\Bot\Laravel\Facades\Telegram;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Telegram::addCommands([
            \App\Telegram\Commands\StartCommand::class,
            \App\Telegram\Commands\QuizCommand::class,
        ]);

        // Добавь этот блок кода для логирования SQL запросов
        if ($this->app->environment('local')) { // Логирование включается только в локальной среде
            DB::listen(function ($query) {
                Log::info('SQL Query', [
                    'query' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                ]);
            });
        }
    }
}

