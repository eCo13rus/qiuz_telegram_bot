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
            $this->line("\nВсего пользователей пришли из - [\"{$source}\"]\n");

            // Общее количество пользователей для текущего utm_source
            $totalUsersForSource = DB::table('user_states')
                ->where('utm_source', $source)
                ->distinct('user_id')
                ->count('user_id');

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

            // Расчет процентов для каждой категории
            $percentJustStarted = $totalUsersForSource > 0 ? round(($justStartedCount / $totalUsersForSource) * 100, 2) : 0;
            $percentInProgress = $totalUsersForSource > 0 ? round(($inProgressCount / $totalUsersForSource) * 100, 2) : 0;
            $percentCompleted = $totalUsersForSource > 0 ? round(($completedCount / $totalUsersForSource) * 100, 2) : 0;
            $percentImageGenerated = $totalUsersForSource > 0 ? round(($imageGeneratedCount / $totalUsersForSource) * 100, 2) : 0;
            $percentNeurotexter = $totalUsersForSource > 0 ? round(($neurotexterCount / $totalUsersForSource) * 100, 2) : 0;
            $percentNeuroholst = $totalUsersForSource > 0 ? round(($neuroholstCount / $totalUsersForSource) * 100, 2) : 0;
            $percentSubscribedToNeuroved = $totalUsersForSource > 0 ? round(($subscribedToNeurovedCount / $totalUsersForSource) * 100, 2) : 0;

            // Вывод с процентами
            $this->line("Всего пользователей просто нажали старт: {$justStartedCount} ({$percentJustStarted}%)");
            $this->line("Всего пользователей в процессе прохождения квиза: {$inProgressCount} ({$percentInProgress}%)");
            $this->line("Всего пользователей завершили квиз: {$completedCount} ({$percentCompleted}%)");
            $this->line("Всего пользователей сгенерировали изображение: {$imageGeneratedCount} ({$percentImageGenerated}%)");
            $this->line("Всего пользователей перешли на нейротекстер: {$neurotexterCount} ({$percentNeurotexter}%)");
            $this->line("Всего пользователей подписались на нейровед: {$subscribedToNeurovedCount} ({$percentNeuroholst}%)");
            $this->line("Всего пользователей перешли на нейрохолст: {$neuroholstCount} ({$percentSubscribedToNeuroved}%)\n");
        }

        $this->line("\nОбщий подсчет по всем пользователям:\n");

        $totalUsersCount = DB::table('users')->distinct('id')->count('id');

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

        // Вывод с процентами общий
        $percentJustStarted = $totalUsersCount > 0 ? round(($totalJustStartedCount / $totalUsersCount) * 100, 2) : 0;
        $percentInProgress = $totalUsersCount > 0 ? round(($totalInProgressCount / $totalUsersCount) * 100, 2) : 0;
        $percentCompleted = $totalUsersCount > 0 ? round(($totalCompletedCount / $totalUsersCount) * 100, 2) : 0;
        $percentImageGenerated = $totalUsersCount > 0 ? round(($totalImageGeneratedCount / $totalUsersCount) * 100, 2) : 0;
        $percentNeurotexter = $totalUsersCount > 0 ? round(($totalNeurotexterCount / $totalUsersCount) * 100, 2) : 0;
        $percentNeuroholst = $totalUsersCount > 0 ? round(($totalNeuroholstCount / $totalUsersCount) * 100, 2) : 0;
        $percentSubscribedToNeuroved = $totalUsersCount > 0 ? round(($totalSubscribedToNeurovedCount / $totalUsersCount) * 100, 2) : 0;

        $this->line("Всего пользователей в боте: {$totalUsersCount}");
        $this->line("Всего пользователей просто нажали старт: {$totalJustStartedCount} ({$percentJustStarted}%)");
        $this->line("Всего пользователей нажали старт и в процессе прохождения квиза: {$totalInProgressCount} ({$percentInProgress}%)");
        $this->line("Всего пользователей нажали старт и дошли до конца квиза: {$totalCompletedCount} ({$percentCompleted}%)");
        $this->line("Всего пользователей прошли квиз и сгенерировали изображение: {$totalImageGeneratedCount} ({$percentImageGenerated}%)");
        $this->line("Всего пользователей перешли на нейротекстер: {$totalNeurotexterCount} ({$percentNeurotexter}%)");
        $this->line("Всего пользователей подписались на нейровед: {$totalSubscribedToNeurovedCount} ({$percentSubscribedToNeuroved}%)");
        $this->line("Всего пользователей перешли на нейрохолст: {$totalNeuroholstCount} ({$percentNeuroholst}%)");
    }
}
