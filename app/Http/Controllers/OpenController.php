<?php

namespace App\Http\Controllers;

use \App\Services\M2\IhuProduct;
use Illuminate\Support\Facades\Log;

class OpenController extends Controller
{

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
        $data = new \stdClass();
        $data->list = \App\Services\M2\IhuProduct::getInstance()->getByModel($validated["model"]);

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

        return $this->renderJson($data);
    }

    //搜索外部系统型号
    public function search(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'k' => 'required|string',
                'match_whole_word' => 'required|int',  //0分词匹配，1整个单词匹配
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                //'region'=>'required|string',  //参数值：CN/US/DE
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 30;
            if (!isset($validated['region'])) $validated['region'] = "CN";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new \stdClass();
        $index = \App\Ok\Search::INDEX_PRODUCT;
        $from = max(0, ($validated["page"] - 1) * $validated["size"]);
        $size = $validated["size"];
        $k = $validated["k"];

        $region = $request->header('region');
        $client = $this->getClient($region);

        try {
            $length = mb_strlen($k);
            $k = \App\Ok\EsOk::ok($k);
            if ($validated["match_whole_word"]) {
                $k1 = sprintf('"%s"', $k);
            } else {
                $k1 = sprintf('%s', $k);
            }
            $queries = [];
            if ($length == 0) {
                $query1 = '*:*';
                $query2 = '*:*';
            } elseif ($length == 1) {
                $query1 = sprintf('model: %s', $k1);
                $query2 = sprintf('product_name: %s', $k1);
            } elseif ($length == 2) {
                $query1 = sprintf('model: %s model.n2: %s', $k1, $k1);
                $query2 = sprintf('product_name.n2: %s', $k1);
            } elseif ($length == 3) {
                $query1 = sprintf('model: %s model.n3: %s', $k1, $k1);
                $query2 = sprintf('product_name.n3: %s', $k1);
            } else {
                $query1 = sprintf('model: %s model.n2: %s model.n3: %s model.n4: %s model.n5: %s', $k1, $k1, $k1, $k1, $k1);
                $query2 = sprintf('product_name.n2: %s product_name.n3: %s product_name.n4: %s product_name.n5: %s', $k1, $k1, $k1, $k1);
            }
            $queries[] = sprintf("(%s)", $query1);
            $query = implode(' AND ', $queries);
            //$query .= " OR ". sprintf("(%s)", $query2);

            $data->queries = $query;
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

            try {
                #从es里得到model匹配的IDS
                $result = $client->search([
                    'index' => $index,
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
            } catch (\Elasticsearch\Common\Exceptions\NoNodesAvailableException $e) {
                unset($client);
                //echo $e->getMessage(), "\n";
                Log::channel('search')->info('ES ProductIndexUpdate handle() -$e-- ' . json_encode($e));

                return $this->renderErrorJson("1", $e->getMessage() . $e->getFile());
            }
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
                $data->ids[] = $item['_source']['product_id'];
            }

            # 拿ids到数据库过滤条件，并查出相关数据返回
            $data->list = \App\Services\M2\IhuProduct::getInstance()->ok([
                'ids' => $data->ids,
                'status' => '',
                'enable' => "",
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

            #如果ES里没查到，到数据库里查
            if(empty($data->list)){
                $data->list = \App\Services\M2\IhuProduct::getInstance()->getByModel($k);
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
            }


        } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
            return $this->renderErrorJson(\App\Ok\SysError::ES_NOT_FOUND);
        }
        $data->ids = count($data->ids) ? $data->ids : [0];
        return $this->renderJson($data);
    }


    //添加到ES中
    public function addProductToEs($product_id,$model)
    {
        $index = "gd_ihu_product_search_model_01";

        //添加
        $client = \App\Ok\Search::getInstance()->getClient();
        $clientUs = \App\Ok\Search::getInstance()->getClientUS();
        $clientDe = \App\Ok\Search::getInstance()->getClientDE();
        $clientBx = \App\Ok\Search::getInstance()->getClientBX();
        $clientXjp = \App\Ok\Search::getInstance()->getClientXJP();
        $clientUSE = \App\Ok\Search::getInstance()->getClientUSE();


        $newRow = new \stdClass();
        $newRow->product_id = $product_id;
        $newRow->model = $model;
        try{
            $param = [
                'index' => $index,
                'id' => $newRow->product_id,
                'body' => $newRow,
            ];
            $res=$client->index($param);
            $resUs=$clientUs->index($param);
            $resDe=$clientDe->index($param);
            $resBx=$clientBx->index($param);
            $resXjp=$clientXjp->index($param);
            $resUSE=$clientUSE->index($param);
        }
        catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $e){
            echo $e->getMessage(), "\n";
            Log::channel('search')->info('ES ProductIndexUpdate handle() -$e-- '.json_encode($e));
        }
    }

    //获取客户端
    public function getClient($region)
    {
        $map = [
            "CN" => \App\Ok\Search::getInstance()->getClient(),
            "DE" => \App\Ok\Search::getInstance()->getClientDE(),
            "US" => \App\Ok\Search::getInstance()->getClientUS(),
            "BX" => \App\Ok\Search::getInstance()->getClientBX(),
            "SG" => \App\Ok\Search::getInstance()->getClientXJP(),
            "USE" => \App\Ok\Search::getInstance()->getClientUSE(),
        ];
        $urlMap = [
            "CN" => env('ELASTICSEARCH_HOST'),
            "DE" => env('ELASTICSEARCH_HOST_DE'),
            "US" => env('ELASTICSEARCH_HOST_US'),
            "BX" => env('ELASTICSEARCH_HOST_BR'),
            "SG" => env('ELASTICSEARCH_HOST_SG'),
            "USE" => env('ELASTICSEARCH_HOST_USE'),
        ];
        $client = null;
        $url = $urlMap[$region];
        $output = \App\Ok\Curl::getInstance()->curlGet($url);
        if($output){
            $client = $map[$region];
        }else{
            unset($urlMap[$region]);
            foreach ($urlMap as $key=>$val){
                $output = \App\Ok\Curl::getInstance()->curlGet($val);
                if($output){
                    $client = $map[$key];
                    break;
                }
            }
        }
        return $client;
    }


    //监控代码文件：代码文件是否与CN一致，如果不一致会发邮件提醒
    public function rootPath(\Illuminate\Http\Request $request):\Illuminate\Http\JsonResponse
    {
        $rootPath = dirname($_SERVER['DOCUMENT_ROOT']."../");
        $data = new \stdClass();
        $data->appRootPath = $rootPath;
        $data->patterns = ["php","env", "json"];
        return $this->renderJson($data,200);
    }

}
