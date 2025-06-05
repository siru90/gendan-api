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


//内部系统产品表同步CN
class ProductInternalCN implements ShouldQueue
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
       //Log::channel('sync')->info('ES ProductInternalCN handle() -begin-- ');


        $startTime = microtime(true);
        $product_id = "";
        $lastKey = "product.es.last.id.v01.inter.cn";  #  内部系统产品表 同步cn
        $last = Redis::command("get", [$lastKey]);
        $last = $last?:1;  #5776662115678962326


        $tb = \App\Services\M\IhuProduct::getInstance()->tb
            ->select("product_id","model",'belong_to_product_id')
            ->whereRaw("model NOT LIKE ?", ["%NOTUSED%"])
            ->where('Enable', '0'); # Enable 0正常, 1删除
        if ($product_id) {
            $tb = $tb->where('product_id', $product_id);
        }
        elseif ($last) {
            $tb = $tb->where('product_id', '>', +$last);
        }
        else{
            $begin = date("Y-m-d")." 00:00:00";
            //$begin = "2024-04-12 00:00:00";
            $end = date("Y-m-d")." 23:59:59";
            $tb = $tb->whereBetween('date_modified',[$begin,$end]);
        }

        $tb->chunkById(100, function (\Illuminate\Support\Collection $rows) use ($startTime, $product_id, $lastKey) {

            $index = \App\Ok\Search::INDEX_PRODUCT_INTERNAL;
            $client = \App\Ok\Search::getInstance()->getClient();  #

            foreach ($rows as $row) {
                $params = [
                    'index' => $index,
                    'id' => (int)$row->product_id,
                    'body' => [
                        'product_id' => (int)$row->product_id,
                        'belong_to_product_id' => (int)$row->belong_to_product_id,
                        'model' => (string)$row->model,
                    ],
                ];
                try{
                    $response = $client->index($params);
                }
                catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
                    unset($client,$tb);
                    echo $e->getMessage(), "\n";
                    Log::channel('sync')->info('ES ProductInternalCN handle() -$e-- '.json_encode($e));
                }

                # 如果不是单个更新(即批量更新)，15秒更新一次
                if (!$product_id) {
                    //Log::channel('sync')->info('----eeee---- handle() redis set --- '.$lastKey. "=".$row->product_id);
                    Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
                    if (abs(microtime(true) - $startTime) > 15) {
                        if (Locker::lock("000.InterIndexUpdate.run.lock", 15)) {

                            # 队列延迟分配, 每15秒扫描一次
                            \App\Jobs\ProductInternalCN::dispatch()->delay(15);
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
