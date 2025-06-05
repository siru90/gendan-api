<?php
namespace App\Http\Controllers;


use \App\Services\ExpressDelivery;
use Illuminate\Support\Facades\Log;
use \Illuminate\Support\Facades\Redis;
use \App\Ok\Locker;
use  \App\Services\M\ShipTask;
use \App\Services\M\ShipTaskItem;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\Attachments;

class TestController extends Controller
{

    public function createInterMapping(\Illuminate\Http\Request $request)
    {
        #创建内部系统ES
        $this->createMapping();
        return;
    }

    //同步ES
    public function productES(\Illuminate\Http\Request $request)
    {
        $requestID = $request->get("last_id");
        $requestKey = $request->get("last_key");

        $lastKey = "product.es.last.id.v01.inter.cn";  #  内部系统产品表 同步cn
        Redis::command("set", [$lastKey, 1, ['EX' => 3600 * 24]]);
        \App\Jobs\ProductInternalCN::dispatchSync();   #同步内部系统产品数据 到CN
        return;

        #创建内部系统ES
        //$this->createMapping();
        //return;


        # 创建mapping
        //\App\Jobs\ProductIndexMappingsUpdate::dispatch();
        //$this->productMapping();
        //return ;


        # 调用队列
        //\App\Jobs\ProductIndexUpdate::dispatch();
        //return;

        #同步巴西，新加坡数据
        //this->baxi();
        //return;



        # 直接执行队列里代码
        //Log::channel('sync')->info('ES ProductIndexUpdate handle() -begin-- ');

        # 更新Product的任务
        $startTime = microtime(true);
        $product_id = null;

        //$lastKey = "product.es.last.id.v12";
        $lastKey = "product.es.last.id.v12.cn";
        $last = Redis::command("get", [$lastKey]);

        if(!empty($requestKey)) $lastKey = $requestKey;
        if(!empty($requestID)) $last = $requestID;



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
            //$begin = "2024-04-12 00:00:00";
            $end = date("Y-m-d")." 23:59:59";
            $tb = $tb->whereBetween('date_modified',[$begin,$end]);
        }

        $tb->chunkById(5000, function (\Illuminate\Support\Collection $rows) use ($startTime, $product_id, $lastKey) {
            $index = \App\Ok\Search::INDEX_PRODUCT;

            $client = \App\Ok\Search::getInstance()->getClient();
            $clientUS = \App\Ok\Search::getInstance()->getClientUS();
            $clientDE = \App\Ok\Search::getInstance()->getClientDE();

            //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$client-- '.json_encode($client));


            foreach ($rows as $row) {
                $newRow = new \stdClass();
                $newRow->product_id = $row->product_id;
                $newRow->model = $row->model;

                try{
                    $res=$client->index([
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ]);

                    $res=$clientUS->index([
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ]);
                    $res=$clientDE->index([
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

                    //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$res-- '.json_encode($res));
                    //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$resUS-- '.json_encode($resUS));
                    //Log::channel('sync')->info('ES ProductIndexUpdate handle() -$resDE-- '.json_encode($resDE));
                }
                catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
                    unset($client,$clientUS,$clientDE);
                    echo $e->getMessage(), "\n";
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$e-- '.json_encode($e));
                }


                # 如果不是单个更新(即批量更新)，15秒更新一次
                if (!$product_id) {
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() redis set --- '.$lastKey. "=".$row->product_id);

                    var_dump($row->product_id);
                    Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
                    if (abs(microtime(true) - $startTime) > 15) {
                        if (Locker::lock("000.ProductIndexUpdate.run.lock", 15)) {
                            # 队列延迟分配,每15秒扫描一次
                            //\App\Jobs\ProductIndexUpdate::dispatch()->delay(15);
                        }
                        return false;
                    }
                }


            }

            unset($client,$clientUS,$clientDE);
            return true;
        }, 'product_id');

    }


    //同步巴西
    public function baxi(){

        $startTime = microtime(true);
        $product_id = "";
        $lastKey = "product.es.last.id.v12.bx";
        $last = Redis::command("get", [$lastKey]);
        $last = $last?:18000;

        //var_dump($last);die;

        //Log::channel('sync')->info('----'.$lastKey.'---'.$last);

        $tb = \App\Services\M2\IhuProduct::getInstance()->tb
            ->select("product_id","model")
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
            $index = \App\Ok\Search::INDEX_PRODUCT;
            $clientBX = \App\Ok\Search::getInstance()->getClientBX();
            $clientXJP = \App\Ok\Search::getInstance()->getClientXJP();

            //Log::channel('sync')->info('---- -$clientBX-- '.json_encode($clientBX));
            //Log::channel('sync')->info('---- -$clientXJP-- '.json_encode($clientXJP));

            foreach ($rows as $row) {
                $newRow = new \stdClass();
                $newRow->product_id = $row->product_id;
                $newRow->model = $row->model;

                try{
                    $res=$clientBX->index([
                        'index' => $index,
                        'id' => $newRow->product_id,
                        'body' => $newRow,
                    ]);

                    $res=$clientXJP->index([
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
                    unset($clientBX,$clientXJP);
                    echo $e->getMessage(), "\n";
                    Log::channel('sync')->info('ES ProductIndexUpdate handle() -$e-- '.json_encode($e));
                }

                if (!$product_id) {
                    Log::channel('sync')->info('----eeee---- handle() redis set --- '.$lastKey. "=".$row->product_id);
                    Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
                }
            }

            unset($clientBX,$clientXJP);
            return true;
        }, 'product_id');
    }


    //创建外部系统ES mapping
    private function productMapping()
    {

        $index = \App\Ok\Search::INDEX_PRODUCT;
        //$client = \App\Ok\Search::getInstance()->getClient();
        //$clientUS = \App\Ok\Search::getInstance()->getClientUS();
        //$clientDE = \App\Ok\Search::getInstance()->getClientDE();
        //$clientBX = \App\Ok\Search::getInstance()->getClientBX();
        //$clientXJP = \App\Ok\Search::getInstance()->getClientXJP();
        $clientUSE = \App\Ok\Search::getInstance()->getClientUSE();


        //Log::channel('sync')->info('ES ProductIndexMappingsUpdate  $client:' .json_encode($client));
        //Log::channel('sync')->info('ES ProductIndexMappingsUpdate  $clientUS:' .json_encode($clientUS));
        //Log::channel('sync')->info('ES ProductIndexMappingsUpdate  $clientDE:' .json_encode($clientDE));

        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'index' => [
                        'max_ngram_diff' => 3,
                    ],
                    'analysis' => [
                        'analyzer' => [
                            'ng1_analyzer' => [
                                'tokenizer' => 'ng1_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng2_analyzer' => [
                                'tokenizer' => 'ng2_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng3_analyzer' => [
                                'tokenizer' => 'ng3_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng4_analyzer' => [
                                'tokenizer' => 'ng4_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng5_analyzer' => [
                                'tokenizer' => 'ng5_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                        ],
                        'tokenizer' => [
                            'ng1_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 1,
                                'max_gram' => 1,
                            ],
                            'ng2_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 2,
                                'max_gram' => 2,
                            ],
                            'ng3_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 3,
                                'max_gram' => 3,
                            ],
                            'ng4_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 4,
                                'max_gram' => 4,
                            ],
                            'ng5_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 5,
                                'max_gram' => 5,
                            ],
                        ],
                    ],
                    'number_of_shards' => 1,
                    'number_of_replicas' => 2,
                ],
                'mappings' => [
                    'properties' => [
                        'product_id' => ['type' => 'long',],
                        'model' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                        'product_name' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        try {
            //$settings = $client->indices()->create($params);
            //$settingsUS = $clientUS->indices()->create($params);
            //$settingsDE = $clientDE->indices()->create($params);
            //$settingsBX = $clientBX->indices()->create($params);
            //$settingsXJP = $clientXJP->indices()->create($params);
            $settingsUSE = $clientUSE->indices()->create($params);

        } catch (\Exception $e) {
            echo $e->getMessage(), "\n";
            Log::channel('sync')->info('ES ProductIndexMappingsUpdate() Exception:'.json_encode( $e->getMessage().'file:'.$e->getFile().$e->getLine()) );
        }


    }


    //创建内部系统ES
    public function createMapping():array
    {
        //$index = "internal_product_search_model_02";
        $index = \App\Ok\Search::INDEX_PRODUCT_INTERNAL;
        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'index' => [
                        'max_ngram_diff' => 3,
                    ],
                    'analysis' => [
                        'analyzer' => [
                            'ng1_analyzer' => [
                                'tokenizer' => 'ng1_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng2_analyzer' => [
                                'tokenizer' => 'ng2_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng3_analyzer' => [
                                'tokenizer' => 'ng3_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng4_analyzer' => [
                                'tokenizer' => 'ng4_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng5_analyzer' => [
                                'tokenizer' => 'ng5_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                        ],
                        'tokenizer' => [
                            'ng1_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 1,
                                'max_gram' => 1,
                            ],
                            'ng2_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 2,
                                'max_gram' => 2,
                            ],
                            'ng3_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 3,
                                'max_gram' => 3,
                            ],
                            'ng4_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 4,
                                'max_gram' => 4,
                            ],
                            'ng5_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 5,
                                'max_gram' => 5,
                            ],
                        ],
                    ],
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
                'mappings' => [
                    'properties' => [
                        'product_id' => ['type' => 'long',],
                        'belong_to_product_id' => ['type' => 'long',],
                        'model' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $client = \App\Ok\Search::getInstance()->getClient();
        $settings = $client->indices()->create($params);
        return $settings;
    }



    public function createPIMapping()
    {

        $this->logGroup();
        //\App\Jobs\PisIndexUpdate::dispatch();

        # 更新PI的任务
        $lastKey = "gd.pis.es.last.id.v1";
        $last = Redis::command("get", [$lastKey]);
        $last = !empty($last)? $last:0;

        $startTime = microtime(true);
        $order_id = "";  //order_id从哪里传过来的？

        $tb = \App\Services\M\Orders::getInstance()->tb->where("enable",1);
        if ($order_id) {
            $tb = $tb->where('order_id', $order_id);
        } elseif ($last) {
            $tb = $tb->where('order_id', '>', +$last);
        }

        $tb->chunkById(1000, function (\Illuminate\Support\Collection $rows) use ($startTime, $order_id, $lastKey) {
            $index = \App\Ok\Search::INDEX_PIS;
            $client = \App\Ok\Search::getInstance()->getClient();
            foreach ($rows as $row) {
                //$purchaser_id = $this->getPurchaserId($row);

                $newRow = new \stdClass();
                $newRow->order_id = $row->order_id;
                $newRow->sales_id = trim($row->Sales_User_ID);
                $newRow->country_id = +\App\Services\M\CustomerInfo::getInstance()->getCountryIdById($row->Customer_Seller_info_id);
                $newRow->pi_name = $row->PI_name;
                $newRow->create_time = $row->CreateTime;
                $newRow->product_names = [];
                $newRow->purchaser_id = [];

                $row->items = \App\Services\M\OrdersItemInfo::getInstance()->getOrderItems($row->order_id);
                foreach ($row->items as $item) {
                    //$this->ok1($item, $newRow);
                    $newRow->purchaser_id[] = $item->Purchaser_id;
                    $newRow->product_names[] = $item->product_name_pi;
                }
                $client->index([
                    'index' => $index,
                    'id' => $newRow->order_id,
                    'body' => $newRow,
                ]);

                # 如果不是单个更新(即批量更新)，15秒更新一次
                if (!$order_id) {
                    Redis::command("set", [$lastKey, $row->order_id, ['EX' => 3600 * 24]]);
                    if (abs(microtime(true) - $startTime) > 15) {
                        if (Locker::lock("000.PisIndexUpdate.run.lock", 15)) {
                            # 队列延迟分配,每15秒扫描一次
                            \App\Jobs\PisIndexUpdate::dispatch()->delay(15);
                        }
                        return false;
                    }
                }
            }
            unset($client);
            return true;
        }, 'order_id');
        //return;

        /*
        $index = \App\Ok\Search::INDEX_PIS;
        $client = \App\Ok\Search::getInstance()->getClient();
        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'index' => [
                        'max_ngram_diff' => 3,
                    ],
                    'analysis' => [
                        'analyzer' => [
                            'ng1_analyzer' => [
                                'tokenizer' => 'ng1_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng2_analyzer' => [
                                'tokenizer' => 'ng2_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng3_analyzer' => [
                                'tokenizer' => 'ng3_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng4_analyzer' => [
                                'tokenizer' => 'ng4_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng5_analyzer' => [
                                'tokenizer' => 'ng5_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                        ],
                        'tokenizer' => [
                            'ng1_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 1,
                                'max_gram' => 1,
                            ],
                            'ng2_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 2,
                                'max_gram' => 2,
                            ],
                            'ng3_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 3,
                                'max_gram' => 3,
                            ],
                            'ng4_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 4,
                                'max_gram' => 4,
                            ],
                            'ng5_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 5,
                                'max_gram' => 5,
                            ],
                        ],
                    ],
                    'number_of_shards' => 1,
                    'number_of_replicas' => 2,
                ],
                'mappings' => [
                    'properties' => [
                        'order_id' => ['type' => 'long',],
                        'sales_id' => ['type' => 'long',],
                        'country_id' => ['type' => 'long',],
                        'purchaser_id' => ['type' => 'long',],
                        'pi_name' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                        'product_names' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                        'create_time' => [
                            'type' => 'date',
                        ],
                    ],
                ],
            ],
        ];
        $settings = $client->indices()->create($params);

        */
    }

    public function testGetCurl(\Illuminate\Http\Request $request)
    {
        # 调用curl，发送消息到
        $url = "mailapi.files99.com:7880/api/search_model?page=1&size=30&k=M41/MV41/25/70&match_whole_word=1&region=CN";
        $output = \App\Ok\Curl::getInstance()->curlGet($url);
        var_dump($output);
    }


    public function testPostcurl(\Illuminate\Http\Request $request)
    {
        # 调用curl，发送消息到
        $url = "http://wulian.files99.com/api/systemMessage/add";
        $data = [
            "event_name"=>"PurchaseOrder_Signed",
            "event_params"=>[
                "purchaseorder_id"=>16510,
            ]
        ];
        $output = \App\Ok\Curl::getInstance()->curlPost($url, $data);
    }

    public function sendMessage(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        //$messageBody = [1,2,3];
        //\App\Ok\RabbitmqConnection::getInstance()->push("gd_order_queue_test","genDanShipTask","gd.order.key.test",$messageBody);

        Log::channel('sync')->info('666666666eeeee66');

        //$message ='{"method":"sendShiptask","params":{"Shiptask_id":0,"crm_shiptask_id":1724639012586307473,"State":2,"Shipdatetime":"2024-08-26 10:25:38"}}';
        //$messageBody = json_decode($message,true);
        //\App\Ok\RabbitmqConnection::getInstance()->push("crm_so_queue","CRMShipTask","crm.so.key",$messageBody);


        $message ='{"shiptask":{"order_id":"1719904382878820276","Shiptask_name":"SYY240702128602","Sales_User_ID":"1","Customer_Seller_info_id":"9570","address_customer_info_id":"1715672472680413579","Country_id":"525","State":1,"Sort":389061,"Enable":0,"Update_tiem":"2024-07-02 15:15:38","create_time":"2024-07-02 15:15:38","create_user":"1","Weight":25,"weight_unit":"kg","org_id":"1111111","COMPANY_ID":"12553","Shiptask_id":"1719904538826255888"},"shiotask_item":[{"ShioTask_item_id":"1719904538836596137","Shiptask_id":"1719904538826255888","products_id":"1719829298291912552","Purchaseorder_detailed_id":null,"products_Name":"Test-5","Leading_name":"In Stock","Model":"Test-5","Qtynumber":"1","Brand":"1177","Brand_name":"ABRACON LLC","Weight":"25","Purchaser_id":"1717118889100000533","Picture_url":null,"Sort":"127672","State":"1","Comments":null,"create_time":"2024-07-02 15:15:38","create_user":"1","type":"0","order_info_id":"1719904382904023383","weight_unit":"kg","crm_shiptask_id":"0","crm_shioTask_item_id":"0","crm_order_info_id":"0"}]}';
        $messageBody = json_decode($message,true);
        //\App\Ok\RabbitmqConnection::getInstance()->push("outside_shiptask_test","outside_shiptask_test","outside_shiptask_test",$messageBody);


        $message ='{"method":"addAttachment","params":{"crm_files_id":"1715774788100001968","path":"http:\/\/kernal-1256119944.cos.ap-guangzhou.myqcloud.com\/6644a540cbea5.jpg","disk":"tencent","name":"q1q141sxxdj","user_id":1,"crm_shiptask_id":1715774719612958527}}';
        $messageBody = json_decode($message,true);
        //\App\Ok\RabbitmqConnection::getInstance()->push("crm_so_queue_test","CRMShipTask","crm.so.key.test",$messageBody);

        //editShiptask
        $message ='{"method":"editShiptask","params":{"Country_id":"505","Shipdatetime":"2024-09-19 10:30:29","Shipping_cost":"","currency":"","crm_shiptask_id":"1725260009315282685"}}';
        $messageBody = json_decode($message,true);
        //\App\Ok\RabbitmqConnection::getInstance()->push("crm_so_queue_test","CRMShipTask","crm.so.key.test",$messageBody);


        $message = '{"method":"syncKey","params":[{"Shiptask_id":40340,"ShioTask_item_id":132660,"crm_shiptask_item_id":"1725504464307447122","crm_shiptask_id":"1725504464298467453"}]}';
        $messageBody = json_decode($message,true);
        \App\Ok\RabbitmqConnection::getInstance()->push("gd_so_queue_test","genDanShipTask","gd.so.key.test",$messageBody);


        //\App\Ok\RabbitmqConnection::getInstance()->push("crm_so_queue_test","CRMShipTask","crm.so.key.test","quit");

        //\App\Ok\RabbitmqConnection::getInstance()->push("crm_so_queue","CRMShipTask","crm.so.key","quit");

        return $this->renderJson(new \stdClass());
    }

    public function consumeMessage()
    {
        //  \App\Ok\RabbitmqConnection::getInstance()->consume("gd_so_queue","genDanShipTask","gd.so.key");
        //  \App\Ok\RabbitmqConnection::getInstance()->consume("outside_shiptask","outside_shiptask","outside_shiptask");
        \App\Ok\RabbitmqConnection::getInstance()->consume("crm_so_queue_test","CRMShipTask","crm.so.key.test");
    }


    public function testSync(\Illuminate\Http\Request $request)
    {
        $this->logGroup();
        Log::channel('sync')->info('666666666eeeee66');


        $message = '{"shiptask":{"order_id":"1724913189483735586","Sales_User_ID":"1","Customer_Seller_info_id":"1720508164710689340","address_customer_info_id":"1720509701629568699","Country_id":"526","State":"1","Sort":"419191","Enable":"0","Update_tiem":"2024-08-29 15:03:31","create_time":"2024-08-29 15:03:31","create_user":"1","Weight":20,"weight_unit":"kg","org_id":"1111111","COMPANY_ID":"12971","Shiptask_id":"1724915011864476362","merge_so":1},"shiotask_item":[{"ShioTask_item_id":"1724915011895602110","Shiptask_id":"1724915011864476362","products_id":"1724913110989711738","Purchaseorder_detailed_id":null,"products_Name":"MR-BKCNS1-20M-H-C(ES)","Leading_name":"2-3 weeks","Model":"MR-BKCNS1-20M-H-C(ES)","Qtynumber":"20","Brand":"2","Brand_name":"MITSUBISHI","Weight":"20","Purchaser_id":"1721099492449361335","Picture_url":null,"Sort":"210144","State":"1","Comments":null,"create_time":"2024-08-29 15:03:31","create_user":"1","type":"0","order_info_id":"1724913189523541991","weight_unit":"kg","crm_shiptask_id":"0","crm_shioTask_item_id":"0","crm_order_info_id":"0","audit_satus":"0","reject_quantity":"0","serial_numbers":null,"form_status":null},{"ShioTask_item_id":"1724915012129827156","Shiptask_id":"1724915011864476362","products_id":"1724913110989711738","Purchaseorder_detailed_id":null,"products_Name":"MR-BKCNS1-20M-H-C(ES)","Leading_name":"2-3 weeks","Model":"MR-BKCNS1-20M-H-C(ES)","Qtynumber":"10","Brand":"2","Brand_name":"MITSUBISHI","Weight":"20","Purchaser_id":"1721099492449361335","Picture_url":null,"Sort":"210144","State":"1","Comments":null,"create_time":"2024-08-29 15:03:32","create_user":"1","type":"0","order_info_id":"1724913189523541991","weight_unit":"kg","crm_shiptask_id":"0","crm_shioTask_item_id":"0","crm_order_info_id":"0","audit_satus":"0","reject_quantity":"0","serial_numbers":null,"form_status":null}]}';
        $body = json_decode($message,true);

        //var_dump($body);
        $res = \App\Services\Sync\ShipTaskSync::getInstance()->saveShipTask($body);

        var_dump($res);
    }

    //改变日志权限
    public function logGroup()
    {
        $logPath = storage_path('logs');
        exec("chown www:www -R $logPath/*.log");
    }

}


?>
