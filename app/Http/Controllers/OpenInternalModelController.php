<?php

namespace App\Http\Controllers;

use \App\Services\M2\IhuProduct;
use Illuminate\Support\Facades\Log;

class OpenInternalModelController extends Controller
{
    //public string $index = 'ihu_product_search_model_0333';
    public string $index = \App\Ok\Search::INDEX_PRODUCT_INTERNAL;

    //添加到ES
    public function addProduct(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => 'integer',
                'model' => 'required|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $data = $this->searchModelToEs($validated["model"]);
        return $this->renderJson($data);
    }

    private function searchModelToEs($model)
    {
        $data = new \stdClass();
        $data->list = \App\Services\M\IhuProduct::getInstance()->getByModel($model);

        foreach ($data->list as $key=>$obj){
            if (strpos($obj->model, "NOTUSED") !== false) {  #字符串包含字符串NOTUSED
                unset($data->list[$key]);
            } else {
                //echo "字符串不包含目标子字符串";
                #新增到ES里
                $this->addProductToEs($obj->product_id,$obj->model);
                $data->ids[] = $obj->product_id;
            }
        }
        return $data;
    }

    //内部系统型号搜索
    public function search(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $index1 = $this->index;

        $data = new \stdClass();
        $page = $data->page = (int)$request->get('page', 1);
        $size = $data->size = (int)$request->get('size', 20);
        $args = $data->args = new \stdClass();
        $args->k = (string)$request->get('k');
        $args->match_whole_word = (string)$request->get('match_whole_word'); #0分词匹配, 1整词匹配, 2型号完全匹配关键词
        $args->belong_to_product_id = (string)$request->get('belong_to_product_id', '-1,-2');
        $args->language_id = (string)$request->get('language_id', 1);
        $args->status = (string)$request->get('status', 1);
        $args->enable = (string)$request->get('enable', 0);
        $k = $args->k;

        $from = max(0, ($page - 1) * $size);
        $args->belong_to_product_id = explode(',', $args->belong_to_product_id);
        $args->belong_to_product_id = array_map(function ($val) {
            return intval($val);
        }, $args->belong_to_product_id);
        $args->belong_to_product_id = array_values(array_filter($args->belong_to_product_id));

        if((mb_strlen($k) >=4)){
            //$this->searchModelToEs($k);
        }

        $client = \App\Ok\Search::getInstance()->getClient();
        try {
            $length = mb_strlen($k);
            $k = \App\Ok\EsOk::ok($k);
            if ($args->match_whole_word) {
                $k1 = sprintf('"%s"', $k);
            } else {
                $k1 = sprintf('%s', $k);
            }
            $queries = [];
            if ($length == 0) {
                $query1 = '*:*';
            } elseif ($length == 1) {
                $query1 = sprintf('model: %s', $k1);
            } elseif ($length == 2) {
                $query1 = sprintf('model.n2: %s', $k1);
            } elseif ($length == 3) {
                $query1 = sprintf('model.n3: %s', $k1);
            } else {
                $query1 = sprintf('model: %s model.n2: %s model.n3: %s model.n4: %s model.n5: %s', $k1,$k1, $k1, $k1, $k1);
            }
            $queries[] = sprintf("(%s)", $query1);
            /*if (count($args->belong_to_product_id)) {
                $ok = $args->belong_to_product_id;
                $ok = array_map(function ($val) {
                    if ($val < 0) $val = '\\' . $val;
                    return $val;
                }, $ok);
                $query2 = sprintf("belong_to_product_id:(%s)", implode(' OR ', $ok));
                $queries[] = sprintf("(%s)", $query2);
            }*/
            $query = implode(' AND ', $queries);
            $data->debug = $query;
            if ($length > 1) {
                $highlight_fields = [
                    "model.n2" => [
                        "pre_tags" => ["<em>"],
                        "post_tags" => ["</em>"],
                    ],
                    "model.n3" => [
                        "pre_tags" => ["<em>"],
                        "post_tags" => ["</em>"],
                    ],
                    "model.n4" => [
                        "pre_tags" => ["<em>"],
                        "post_tags" => ["</em>"],
                    ],
                    "model.n5" => [
                        "pre_tags" => ["<em>"],
                        "post_tags" => ["</em>"],
                    ],
                ];
            } else {
                $highlight_fields = [
                    "model" => [
                        "pre_tags" => ["<em>"],
                        "post_tags" => ["</em>"],
                    ],
                ];
            }
            $result = $client->search([
                'index' => $index1,
                'from' => $from,
                'size' => $size,
                'body' => [
                    'query' => [
                        'query_string' => [
                            'default_field' => 'model',
                            'query' => $query,
                        ],
                    ],
                    "highlight" => [
                        "fields" => $highlight_fields,
                    ],
                ],
            ]);
            $data->took = $result['took'] ?? null;
            $hits = $result['hits'];
            $data->total = $hits['total']['value'];
            $data->ids = $data->highlight = [];
            foreach ($hits['hits'] as $item) {
                if (!isset($item['highlight']['model'])) $item['highlight']['model'] = [];
                if (isset($item['highlight']['model.n2'])) {
                    $item['highlight']['model'] = array_merge($item['highlight']['model'], $item['highlight']['model.n2']);
                    unset($item['highlight']['model.n2']);
                }
                if (isset($item['highlight']['model.n3'])) {
                    $item['highlight']['model'] = array_merge($item['highlight']['model'], $item['highlight']['model.n3']);
                    unset($item['highlight']['model.n3']);
                }
                if (isset($item['highlight']['model.n4'])) {
                    $item['highlight']['model'] = array_merge($item['highlight']['model'], $item['highlight']['model.n4']);
                    unset($item['highlight']['model.n4']);
                }
                if (isset($item['highlight']['model.n5'])) {
                    $item['highlight']['model'] = array_merge($item['highlight']['model'], $item['highlight']['model.n5']);
                    unset($item['highlight']['model.n5']);
                }
                $item['highlight']['model'] = array_values(array_unique($item['highlight']['model']));
                $data->highlight[$item['_source']['product_id']] = $item['highlight'] ?? (object)[];

                if($args->match_whole_word == 2){  #字符串 == 关键字
                    if($item["_source"]["model"] == $args->k)  $data->ids[] = $item['_source']['product_id'];
                }else{
                    $data->ids[] = $item['_source']['product_id'];
                }
                #不等于-1，-2的产品ID也去查下
                if(!empty($item['_source']['belong_to_product_id']) && !in_array($item['_source']['belong_to_product_id'], [-1,-2]) && !in_array($item['_source']['belong_to_product_id'], $data->ids)){
                    $modelsOne = \App\Services\M\IhuProduct::getInstance()->getModelByProductId($item['_source']['belong_to_product_id']);
                    if($modelsOne)
                    {
                        if($item["_source"]["model"] == $args->k){  #字符串 == 关键字
                            $data->ids[] = $item['_source']['belong_to_product_id'];
                        }
                        if($args->match_whole_word != "2"){  #增加匹配模糊搜索
                            $tmpModel = str_replace(array(' ', '-','/'), '', $modelsOne->model);   #去掉字符串中空格，横杠，存在查询字符串；
                            if ( strpos($modelsOne->model, $args->k) !== false  ||  strpos($tmpModel, $args->k) !== false ) {  #或者字符串存在关键词
                                $data->ids[] = $item['_source']['belong_to_product_id'];
                            }
                        }
                    }
                }
                // $data->debug_list[] = $item['_source'];
            }
            //$data->ids = array_unique($data->ids);  加了会有问题：去除重复值会列出索引键值
            $data->list = \App\Services\M\IhuProduct::getInstance()->ok([
                'ids' => $data->ids,
                'belong_to_product_id' => $args->belong_to_product_id,
                'language_id' => $args->language_id,
                'status' => "", //$args->status,
                'enable' => "", //$args->enable,
            ]);

            $tmpList = [];
            foreach ($data->list as $key=> $item) {
                $item->highlight = $data->highlight[$item->product_id] ?? (object)[];
                $tmpList[$item->product_id] = $item;
            }
            $data->list=[];
            foreach ($data->ids as $id){
                if(!empty($tmpList[$id])) $data->list[] = $tmpList[$id];
            }
            unset($tmpList,$data->highlight);

            //如果$data->list是空的
            /*if(empty($data->list) && (mb_strlen($k) >=4)){
                $productId = 0;
                if(\App\Ok\Locker::lock("model_{$k}", 1)) {
                    [$response, $productId] = $this->insert($args->k);
                }
                $data->list = \App\Services\M\IhuProduct::getInstance()->ok([
                    'ids' => [$productId],
                    'belong_to_product_id' => $args->belong_to_product_id,
                    'language_id' => "",
                    'status' => "",
                    'enable' => "",
                ]);
                $data->ids[] = $productId;

                //var_dump($data->list);

            }*/
        } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
            return $this->renderErrorJson(\App\Ok\SysError::ES_NOT_FOUND);
        }
        $data->ids = count($data->ids)? $data->ids :[0];
        return $this->renderJson($data);
    }


    //修改单个产品索引
    public function update(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $k = (string)$request->get('k');
        if(mb_strlen($k) <4) return $this->renderJson(new \stdClass());

        if(\App\Ok\Locker::lock("model_{$k}", 1)) {
            [$response, $productId] = $this->insert($k);
        }
        $list = \App\Services\M2\IhuProduct::getInstance()->ok([
            'ids' => [$productId],
            'belong_to_product_id' => [],
            'language_id' => "",
            'status' => "",
            'enable' => "",
        ]);

        //$data = new \stdClass();
        return $this->renderJson($list);
    }

    //搜索没有时，新增到ES和数据库
    private function insert($key):array
    {
        $key = $this->filterProductModel($key);

        #新增到外部系统数据库
        [$productId,$descId] = \App\Services\M2\IhuProduct::getInstance()->addProduct($key);
        Log::channel('search')->info("\n OpenController insert() {$key} productId = ". $productId);
        Log::channel('search')->info("\n OpenController insert() descId = ". $descId);


        #新增到内部系统数据库
        $affect = \App\Services\M\IhuProduct::getInstance()->addProduct($key,$productId,$descId);
        Log::channel('search')->info("\n OpenController insert(){$productId} affect = ". $affect);


        #新增到ES里
        $client = \App\Ok\Search::getInstance()->getClient();
        $params = [
            'index' => $this->index,
            'id' => $productId,
            'body' => [
                'product_id' => $productId,
                'belong_to_product_id' => -2,
                'model' => trim($key),
            ],
        ];
        try{
            $response = $client->index($params);
        }
        catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
            unset($client);
            echo $e->getMessage(), "\n";
            Log::channel('search')->info('ES insert method Exception -$e-- '.json_encode($e));
        }

        Log::channel('search')->info("\n ES insert method: response = ". json_encode($response));
        return [$response,$productId];
    }


    private function addProductToEs($productId,$key)
    {
        #新增到ES里
        $client = \App\Ok\Search::getInstance()->getClient();
        $params = [
            'index' => $this->index,
            'id' => $productId,
            'body' => [
                'product_id' => $productId,
                'belong_to_product_id' => -2,
                'model' => trim($key),
            ],
        ];
        try{
            $response = $client->index($params);
        }
        catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
            unset($client);
            echo $e->getMessage(), "\n";
            Log::channel('sync')->info('ES insert method Exception -$e-- '.json_encode($e));
        }
    }



    //过滤
    private function filterProductModel($model): string
    {
        if(empty($model)) return '';
        $origin = $model;
        $toEmptys = [' ',"\n","\t", '"', "'"];
        $model   = str_replace($toEmptys, ' ', $model);
        $search  = ['——', '（', '）', '，', '。','【','】','：','？','！'];
        $replace = ['-',  '(', ')',  ',',  '.', '[', ']',':','?','!'];
        $model = str_replace($search, $replace, $model);
        $model   = preg_replace("/\s+/", ' ', $model);
        $model   = preg_replace("/\s*\-\s*/", '-', $model);
        $model = trim($model);

        if($model!=$origin){
            Log::channel('search')->info("\n App\Services\M\IhuProduct filterProductModel() --model:{$model},origin:{$origin}");
        }
        return $model;
    }
}
?>
