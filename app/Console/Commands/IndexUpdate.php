<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class IndexUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:index-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'ES index update';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        # 半夜重建索引数据结构和索引
        #----外部系统
        //\App\Jobs\ProductIndexMappingsUpdate::dispatch();
        //\App\Jobs\ProductIndexUpdate::dispatch();
        //\App\Jobs\ProductBx::dispatch();   #同步巴西，新加坡的ES数据

        # ---内部系统
        //\App\Jobs\ProductInternalCN::dispatch();   #同步内部系统产品数据 到CN
    }
}
