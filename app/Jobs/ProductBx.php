<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use \Illuminate\Support\Facades\Redis;
use \App\Ok\Locker;
use Illuminate\Support\Facades\Log;


class ProductBx implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    private ?int $product_id;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $product_id = null)
    {
        $this->product_id = $product_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
       //Log::channel('sync')->info('ES bx handle() -begin-- ');

        $startTime = microtime(true);
        $product_id = "";
        $lastKey = "product.es.last.id.v12.bx";  # 1720692711236353975 已存在
        $last = Redis::command("get", [$lastKey]);
        $last = $last?:1;  #768989

        $today = date('Y-m-d');
        $begin = date("Y-m-d", strtotime($today . ' -7 day'))." 00:00:00";  #前7天开始
        $end = date("Y-m-d")." 23:59:59";
        $lastOne = \App\Services\M2\IhuProduct::getInstance()->tb->select("product_id")->where('Enable', '0')->orderByDesc("product_id")->limit(1)->first();
        if($last == $lastOne->product_id){
            $firstOne = \App\Services\M2\IhuProduct::getInstance()->tb->select("product_id")->where('Enable', '0')->whereBetween('date_modified',[$begin,$end])
                ->orderBy("product_id","asc")->limit(1)->first();
            $last = $firstOne->product_id;
        }

        $tb = \App\Services\M2\IhuProduct::getInstance()->tb
            ->select("product_id","model")
            ->whereRaw("model NOT LIKE ?", ["%NOTUSED%"])
            ->where('Enable', '0') # Enable 0正常, 1删除
            ->orderBy("product_id","asc");
        if ($product_id) {
            $tb = $tb->where('product_id', $product_id);
        }
        elseif ($last) {
            $tb = $tb->where('product_id', '>', +$last);
        }
        else{
            $begin = date("Y-m-d")." 00:00:00";
            $end = date("Y-m-d")." 23:59:59";
            $tb = $tb->whereBetween('date_modified',[$begin,$end]);
        }

        $tb->chunkById(100, function (\Illuminate\Support\Collection $rows) use ($startTime, $product_id, $lastKey) {
            //var_dump($rows);return;
            //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$rows-- '.count($rows));
            $index = \App\Ok\Search::INDEX_PRODUCT;

            $clientBX = \App\Ok\Search::getInstance()->getClientBX();  #
            $clientXJP = \App\Ok\Search::getInstance()->getClientXJP();
            $clientUSE = \App\Ok\Search::getInstance()->getClientUSE();

            //Log::channel('sync')->info('---- -$clientBX-- '.json_encode($clientBX));
            //Log::channel('sync')->info('---- -$clientXJP-- '.json_encode($clientXJP));

            foreach ($rows as $row) {

                //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$row-- ');
                $newRow = new \stdClass();
                $newRow->product_id = $row->product_id;
                $newRow->model = $row->model;
                //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$newRow-- '.json_encode($newRow));
                try{
                    $tmp = [
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ];

                    $res=$clientBX->index($tmp);
                    $resXJP=$clientXJP->index($tmp);
                    $resUSE=$clientUSE->index($tmp);

                    //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$res-- '.json_encode($res));
                }
                catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
                    unset($clientBX,$tb);
                    echo $e->getMessage(), "\n";
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$e-- '.json_encode($e));
                }

                # 如果不是单个更新(即批量更新)，15秒更新一次
                if (!$product_id) {
                    //Log::channel('sync')->info('----eeee---- handle() redis set --- '.$lastKey. "=".$row->product_id);
                    Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
                    if (abs(microtime(true) - $startTime) > 15) {
                        if (Locker::lock("000.bxIndexUpdate.run.lock", 15)) {

                            # 队列延迟分配, 每15秒扫描一次
                            \App\Jobs\ProductBx::dispatch()->delay(15);
                        }
                        return false;
                    }
                }

            }

            unset($clientBX,$tb);
            return true;
        }, 'product_id');




    }

}
?>
