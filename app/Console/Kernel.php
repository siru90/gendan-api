<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands=[
        Commands\CrmShipTaskSync::class,
        Commands\CrmFillZeroSync::class,
        Commands\Check2TaochunfuSync::class,
        Commands\WebSocket::class,
    ];

    /**
     * Define the application's command schedule.
     * 任务调度: 定时任务
     * 把下列语句加到在服务器中添加 Cron 条目
     *  * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
     */
    protected function schedule(Schedule $schedule): void
    {
        //$schedule->command('app:index-update')->dailyAt("3:00");  //索引定时任务，每天凌晨3点运行
        //$schedule->command('test:test1')->everyMinute();

        #本地不执行
        //$schedule->command('app:check2_taochunfu')->everyFiveMinutes();
        //$schedule->command('app:crm-fill-zero-data')->dailyAt("2:00");
        //$schedule->command('app:express-deliver')->dailyAt("3:00"); //快递物流定时任务，每天凌晨3点运行
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
