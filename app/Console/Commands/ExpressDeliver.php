<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use \App\Services\ExpressDelivery;

class ExpressDeliver extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:express-deliver';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '快递物流状态更新定时任务';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // 1. nohup php /mnt/www/t-api/artisan queue:listen --queue=default --tries=3  执行这条语句消费队列

        Log::channel('sync')->info('更新快递物流: ------');
        \App\Jobs\ExpressDeliverUpdate::dispatch();


    }

}

?>
