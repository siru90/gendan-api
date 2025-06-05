<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use \Illuminate\Support\Facades\Redis;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:test1';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test command1';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        //\App\Jobs\ProductBx::dispatch();   #同步巴西，新加坡的ES数据

        # 更新Product的任务
        $startTime = microtime(true);
        $product_id = null;

        $lastKey = "product.es.last.id.v12.use";
        $last = Redis::command("get", [$lastKey]);
        $last = $last?:0;


        $begin = date("Y-m-d")." 00:00:00";
        $end = date("Y-m-d")." 23:59:59";
        $lastOne = \App\Services\M2\IhuProduct::getInstance()->tb->select("product_id")->where('Enable', '0')->orderByDesc("product_id")->limit(1)->first();
        if($last == $lastOne->product_id){
            $firstOne = \App\Services\M2\IhuProduct::getInstance()->tb->select("product_id")
                ->where('Enable', '0')->where('date_modified',">=",$begin)
                ->orderBy("product_id","asc")->limit(1)->first();

            //$last = $firstOne->product_id;
        }

        $tb = \App\Services\M2\IhuProduct::getInstance()->tb
        ->select("product_id","model")
        ->whereRaw("model NOT LIKE ?", ["%NOTUSED%"])
        ->where('Enable', '0'); # Enable 0正常, 1删除
        if ($product_id) {
            # 更新单个产品
            $tb = $tb->where('product_id', $product_id);
        }
        elseif ($last) {
            # 从上次跟新到最后一条开始
            //$tb = $tb->where('product_id', '>', +$last)->whereBetween('date_modified',[$begin,$end]);
            $tb = $tb->where('product_id', '>', +$last);
        }
        else{
            $begin = date("Y-m-d")." 00:00:00";
            $end = date("Y-m-d")." 23:59:59";
            $tb = $tb->whereBetween('date_modified',[$begin,$end]);
        }


        $tb->chunkById(1000, function (\Illuminate\Support\Collection $rows) use ($startTime, $product_id, $lastKey) {
            $index = \App\Ok\Search::INDEX_PRODUCT;
            $clientUSE = \App\Ok\Search::getInstance()->getClientUSE();

            foreach ($rows as $row) {
                //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$row-- ');
                $newRow = new \stdClass();
                $newRow->product_id = $row->product_id;
                $newRow->model = $row->model;
                //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$newRow-- '.json_encode($newRow));
                try{
                    $res=$clientUSE->index([
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ]);

                    if ($res['result'] != 'created' && $res['result'] != 'updated') {
                        echo json_encode($res), "\n";
                        die;
                    }
                    if ($res['result'] == 'created') {
                        echo "+";
                    } elseif ($res['result'] == 'updated') {
                        echo "u";
                    }
                }
                catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
                    echo $e->getMessage(), "\n";
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$e-- '.json_encode($e));
                }
                Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
            }

            return true;
        }, 'product_id');

    }

}
