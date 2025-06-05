<?php

namespace App\Http\Controllers;

use \App\Services\M2\IhuProduct;
use Illuminate\Support\Facades\Log;
use \Illuminate\Support\Facades\Redis;

class DeleteProductController extends Controller
{

    //删除外部系统型号中有NOTUSED的
    public function deleteEXTERNAL(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => 'string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new \stdClass();
        $product_id = !empty($validated["product_id"])?: 0;

        //删除单个
        if (!empty($product_id)) {
            $index = \App\Ok\Search::INDEX_PRODUCT;
            $params = [
                'index' => $index,
                'id'    => $product_id,
            ];
            $client = \App\Ok\Search::getInstance()->getClient();
            $clientDE = \App\Ok\Search::getInstance()->getClientDE();
            $clientUS = \App\Ok\Search::getInstance()->getClientUS();
            $clientXJP = \App\Ok\Search::getInstance()->getClientXJP();
            $clientBX = \App\Ok\Search::getInstance()->getClientBX();

            $res = $client->delete($params);
            $resDE = $clientDE->delete($params);
            $resUS = $clientUS->delete($params);  // 已删除到217463
            $resXJP = $clientXJP->delete($params);  //217463
            $resBX = $clientBX->delete($params);

            $data->id = $product_id;
            $this->renderJson($data);
        }

        $lastKey = "product.es.last.id.v12.del";
        $last = Redis::command("get", [$lastKey]);
        $last = $last?:1;


        #批量删除
        $tb = \App\Services\M2\IhuProduct::getInstance()->tb
            ->select("product_id","model")
            ->whereRaw("model LIKE ?", ["%NOTUSED%"])
            ->where('Enable', '0') # Enable 0正常, 1删除
            ->orderBy("product_id","asc");

        $tb = $tb->where('product_id', '>', +$last);
        $data->ids =[];

        $tb->chunkById(1000, function (\Illuminate\Support\Collection $rows) use ($data,$lastKey) {
            $index = \App\Ok\Search::INDEX_PRODUCT;

            //$client = \App\Ok\Search::getInstance()->getClient();
            $clientDE = \App\Ok\Search::getInstance()->getClientDE();
            $clientUS = \App\Ok\Search::getInstance()->getClientUS();
            $clientXJP = \App\Ok\Search::getInstance()->getClientXJP();
            $clientBX = \App\Ok\Search::getInstance()->getClientBX();

            foreach ($rows as $row) {
                $params = [
                    'index' => $index,
                    'id'    => $row->product_id,
                ];
                try {
                    //$res = $client->delete($params);
                    $resUS = $clientUS->delete($params); // 已删除到217463
                    $resXJP = $clientXJP->delete($params);  //217463
                    $resDE = $clientDE->delete($params);
                    $resBX = $clientBX->delete($params);
                    //var_dump($res,$resDE,$resUS,$resXJP,$resBX);
                }catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
                    Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
                    continue;
                }
                Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
            }

            //unset($client,$tb);
            return true;
        }, 'product_id');

        return $this->renderJson($data);
    }


    //删除内部系统型号中有NOTUSED的
    public function deleteInternal(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => 'string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new \stdClass();
        $product_id = !empty($validated["product_id"])?: 0;

        $lastKey = "product.es.last.id.internal.del";
        $last = Redis::command("get", [$lastKey]);
        $last = $last?:1;

        $tb = \App\Services\M\IhuProduct::getInstance()->tb
            ->select("product_id","model")
            ->whereRaw("model LIKE ?", ["%NOTUSED%"])
            ->where('Enable', '0') # Enable 0正常, 1删除
            ->orderBy("product_id","asc");

        if (!empty($product_id)) {
            $tb = $tb->where('product_id', $product_id);
        }else{
            $tb = $tb->where('product_id', '>', +$last);
        }

        $data->ids =[];
        $tb->chunkById(100, function (\Illuminate\Support\Collection $rows) use ($data,$lastKey) {
            $index = \App\Ok\Search::INDEX_PRODUCT_INTERNAL;

            $client = \App\Ok\Search::getInstance()->getClient();
            foreach ($rows as $row) {
                $params = [
                    'index' => $index,
                    'id'    => $row->product_id,
                ];
                try {
                    $res = $client->delete($params);
                    var_dump($res);
                }catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
                    //return $this->renderErrorJson(\App\Ok\SysError::ES_NOT_FOUND);
                    continue;
                }

                // 检查响应
                /*if ($res['acknowledged']) {
                    $data->ids[] = $row->product_id;
                } else {
                    // 你可以在这里处理错误，例如打印错误信息
                    echo "Error deleting document.";
                    // 可能还需要查看 $response['forced_refresh'] 或其他相关字段
                }*/
                Redis::command("set", [$lastKey, $row->product_id, ['EX' => 3600 * 24]]);
            }
            unset($client,$tb);
            return true;
        }, 'product_id');

        return $this->renderJson($data);
    }


}
