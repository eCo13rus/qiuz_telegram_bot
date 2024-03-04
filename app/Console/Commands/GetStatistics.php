<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GetStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:stat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Статистика бота';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $utmSources = DB::table('user_states')
            ->select('utm_source')
            ->distinct()
            ->pluck('utm_source');

        foreach ($utmSources as $source) {
            $this->line("\nВсего пользователей пришли из: [\"{$source}\"]");

            // Только нажали старт
            $justStartedCount = DB::table('user_states')
                ->where('utm_source', $source)
                ->where('state', 'start')
                ->distinct('user_id')
                ->count('user_id');

            // В процессе прохождения квиза
            $inProgressCount = DB::table('user_states')
                ->where('utm_source', $source)
                ->where('state', 'quiz_in_progress')
                ->distinct('user_id')
                ->count('user_id');

            // Прошли квиз
            $completedCount = DB::table('user_states')
                ->where('state', 'quiz_completed')
                ->where('utm_source', $source)
                ->distinct('user_id')
                ->count('user_id');

            // Подсчёт пользователей, сгенерировавших изображение
            $imageGeneratedCount = DB::table('user_states')
                ->where('utm_source', $source)
                ->where('state', 'image_generated')
                ->distinct('user_id')
                ->count('user_id');

            // Перешли на нейротекстер
            $neurotexterCount = DB::table('users')
                ->join('user_states', 'users.id', '=', 'user_states.user_id')
                ->where('users.clicked_on_neurotexter_link', 1)
                ->where('user_states.utm_source', $source)
                ->count();

            // Перешли на нейрохолст
            $neuroholstCount = DB::table('users')
                ->join('user_states', 'users.id', '=', 'user_states.user_id')
                ->where('users.clicked_on_link', 1)
                ->where('user_states.utm_source', $source)
                ->count();

            // Подписали на нейровед
            $subscribedToNeurovedCount = DB::table('users')
                ->join('user_states', 'users.id', '=', 'user_states.user_id')
                ->where('users.is_subscribed', 1)
                ->where('user_states.utm_source', $source)
                ->count();

            $this->line("Всего пользователей просто нажали старт: {$justStartedCount}");
            $this->line("Всего пользователей нажали старт и в процессе прохождения квиза: {$inProgressCount}");
            $this->line("Всего пользователей нажали старт и дошли до конца квиза: {$completedCount}");
            $this->line("Всего пользователей прошли квиз и сгенерировали изображение: {$imageGeneratedCount}");
            $this->line("Всего пользователей перешли на нейротекстер: {$neurotexterCount}");
            $this->line("Всего пользователей перешли на нейрохолст: {$neuroholstCount}");
            $this->line("Всего пользователей подписались на нейровед: {$subscribedToNeurovedCount}");
        }

        $this->line("\nОбщий подсчет по всем пользователям:");

        // Только нажали старт
        $totalJustStartedCount = DB::table('user_states')
            ->where('state', 'start')
            ->distinct('user_id')
            ->count('user_id');

        // В процессе прохождения квиза
        $totalInProgressCount = DB::table('user_states')
            ->where('state', 'quiz_in_progress')
            ->distinct('user_id')
            ->count('user_id');

        // Прошли квиз
        $totalCompletedCount = DB::table('user_states')
            ->where('state', 'quiz_completed')
            ->distinct('user_id')
            ->count('user_id');

        // Подсчёт пользователей, сгенерировавших изображение
        $totalImageGeneratedCount = DB::table('user_states')
            ->where('state', 'image_generated')
            ->distinct('user_id')
            ->count('user_id');

        // Перешли на нейротекстер
        $totalNeurotexterCount = DB::table('users')
            ->join('user_states', 'users.id', '=', 'user_states.user_id')
            ->where('users.clicked_on_neurotexter_link', 1)
            ->count();

        // Перешли на нейрохолст
        $totalNeuroholstCount = DB::table('users')
            ->join('user_states', 'users.id', '=', 'user_states.user_id')
            ->where('users.clicked_on_link', 1)
            ->count();

        // Подписались на нейровед
        $totalSubscribedToNeurovedCount = DB::table('users')
            ->join('user_states', 'users.id', '=', 'user_states.user_id')
            ->where('users.is_subscribed', 1)
            ->count();

        $this->line("Всего пользователей просто нажали старт: {$totalJustStartedCount}");
        $this->line("Всего пользователей нажали старт и в процессе прохождения квиза: {$totalInProgressCount}");
        $this->line("Всего пользователей нажали старт и дошли до конца квиза: {$totalCompletedCount}");
        $this->line("Всего пользователей прошли квиз и сгенерировали изображение: {$totalImageGeneratedCount}");
        $this->line("Всего пользователей перешли на нейротекстер: {$totalNeurotexterCount}");
        $this->line("Всего пользователей перешли на нейрохолст: {$totalNeuroholstCount}");
        $this->line("Всего пользователей подписались на нейровед: {$totalSubscribedToNeurovedCount}");
    }
}
