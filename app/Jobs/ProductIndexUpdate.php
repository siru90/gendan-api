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


class ProductIndexUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const LAST_KEY = 'product.es.last.id.v12';

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
       // Log::channel('sync')->info('ES ProductIndexUpdate handle() -begin-- ');

        # 更新Product的任务
        $startTime = microtime(true);
        $product_id = $this->product_id;
        $lastKey = self::LAST_KEY;
        $last = Redis::command("get", [$lastKey]);
        //$last = $last? $last:0;

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
            ->select("product_id","model","product_name")
            ->whereRaw("model NOT LIKE ?", ["%NOTUSED%"])
            ->where('Enable', '0'); # Enable 0正常, 1删除
        if ($product_id) {
            # 更新单个产品
            $tb = $tb->where('product_id', $product_id);
        }
        elseif ($last) {
            # 从上次跟新到最后一条开始
            $tb = $tb->where('product_id', '>', +$last)->where('date_modified',">=",$begin);
        }
        else{
            # 每天更新的数据同步到ES
            $tb = $tb->whereBetween('date_modified',[$begin,$end]);
        }

        $tb->chunkById(1000, function (\Illuminate\Support\Collection $rows) use ($startTime, $product_id, $lastKey) {
            //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$rows-- '.count($rows));
            $index = \App\Ok\Search::INDEX_PRODUCT;

            $client = \App\Ok\Search::getInstance()->getClient();
            $clientUS = \App\Ok\Search::getInstance()->getClientUS();
            $clientDE = \App\Ok\Search::getInstance()->getClientDE();
            Log::channel('sync')->info('ES ProductIndexUpdate handle() -$client-- '.json_encode($client));
            Log::channel('sync')->info('ES ProductIndexUpdate handle() -$clientUS-- '.json_encode($clientUS));
            Log::channel('sync')->info('ES ProductIndexUpdate handle() -$clientDE-- '.json_encode($clientDE));


            foreach ($rows as $row) {
                //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$row-- ');
                $newRow = new \stdClass();
                $newRow->product_id = $row->product_id;
                $newRow->model = $row->model;
                $newRow->product_name = $row->product_name;
                //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$newRow-- '.json_encode($newRow));
                try{
                    $res=$client->index([
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ]);
                    $resUS=$clientUS->index([
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ]);
                    $resDE=$clientDE->index([
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

                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$res-- '.json_encode($res));
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$resUS-- '.json_encode($resUS));
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$resDE-- '.json_encode($resDE));
                }
                catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
                    unset($client);
                    echo $e->getMessage(), "\n";
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$e-- '.json_encode($e));
                }


                # 如果不是单个更新(即批量更新)，15秒更新一次
                if (!$product_id) {
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() redis set --- '.$lastKey. "=".$row->product_id);
                    Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24 * 7]]);
                    if (abs(microtime(true) - $startTime) > 15) {
                        if (Locker::lock("000.ProductIndexUpdate.run.lock", 15)) {
                            # 队列延迟分配,每15秒扫描一次
                            \App\Jobs\ProductIndexUpdate::dispatch()->delay(15);
                        }
                        return false;
                    }
                }


            }

            unset($client,$clientUS,$clientDE);
            return true;
        }, 'product_id');
    }

}
?>
