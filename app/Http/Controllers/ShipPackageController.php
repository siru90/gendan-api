<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \App\Ok\SysError;
use \App\Http\Middleware\UserId;
use \App\Services\M\ShipTaskPackage;
use \App\Services\Oplog;
use \App\Services\M\ShipTask;
use \App\Services\M\IhuProductSpecs;
use \App\Services\M\ShipTaskItem;


class ShipPackageController extends Controller
{
    #配置RabbitMq的队列，交换机，路由key
    protected string $queue;
    protected string $exchange;
    protected string $routeKey;

    public function __construct(){
        $so_queue = \Illuminate\Support\Facades\Config::get('app.so_rabbitmq');
        $this->queue = $so_queue["queue"];
        $this->exchange = $so_queue["exchange"];
        $this->routeKey = $so_queue["routeKey"];
    }

    //添加包裹
    public function addPack(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'shiptask_id' => 'required|integer|gt:0',
                'long' => 'numeric|gt:0',
                'wide' => 'numeric|gt:0',
                'high' => 'numeric|gt:0',
                'netWeight' => 'numeric|gt:0',  //净重
                //'grossWeight' => 'required|numeric|gt:0',  //毛重
                'volume_weight' => 'numeric',
                'shiptask_item_id' => 'string',  // [{"id":"","num":"","specs_id":""}]
            ]);
            $validated["grossWeight"] =0;
            $validated["long"] = !empty($validated["long"])?$validated["long"]:0;
            $validated["wide"] = !empty($validated["wide"])?$validated["wide"]:0;
            $validated["high"] = !empty($validated["high"])?$validated["high"]:0;
            $validated["netWeight"] = !empty($validated["netWeight"])?$validated["netWeight"]:0;
            $validated["volume_weight"] = !empty($validated["volume_weight"])?$validated["volume_weight"]:0;
            $itemList = !empty($validated["shiptask_item_id"]) ? json_decode($validated["shiptask_item_id"],true) :[];

        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        # 判断发货任务id是否存在
        $task = ShipTask::getInstance()->getByIdF1($validated['shiptask_id']);
        if (!$task) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        $userId = UserId::$user_id;
        $validated['createTime'] = date('Y-m-d H:i:s');
        $validated['create_user'] = $userId;

        #处理打包型号数据
        $totalPackgeNum = 0;
        if(!empty($itemList)) {
            foreach ($itemList as $obj) {
                $totalPackgeNum += $obj["num"];
            }
        }
        $validated["package_quantity"] = $totalPackgeNum; #打包总件数

        ShipTask::getInstance()->updateShipTask($validated['shiptask_id'],["Update_tiem"=>date("Y-m-d H:i:s")]);
        $result = new \stdClass();
        $result->id = ShipTaskPackage::getInstance()->addPack($validated);
        if ($result->id) {
            # 添加日志
            $note = sprintf("%s × %s × %s", $validated['long'], $validated['wide'], $validated['high']);
            Oplog::getInstance()->addSoLog($userId, $task->Shiptask_id, "添加一个包裹", $note);

            # 同步到RabbitMq
            $validated["package_id"] = $result->id;
            $validated['crm_shiptask_id'] = $task->crm_shiptask_id;
            $validated['shiptask_item_id'] = [];
            if(!empty($validated["shiptask_item_id"])){
                $packModel = $this->returnSpecs(json_decode($validated["shiptask_item_id"]));
                $validated['shiptask_item_id'] = $packModel;
            }
            unset($validated["volume_weight"]);
            $messageBody = array(
                "method"=>"addPack",
                "params"=>$validated,
            );
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);
        }
        return $this->renderJson($result);
    }

    //移除包裹
    public function rmPack(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'package_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userId = UserId::$user_id;

        # 查询包裹id对应的记录是否存在
        $pack = ShipTaskPackage::getInstance()->getPackById($validated['package_id']);
        if (!$pack) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        ShipTask::getInstance()->updateShipTask($pack->shiptask_id,["Update_tiem"=>date("Y-m-d H:i:s")]);

        $result = new \stdClass();
        $result->affected = ShipTaskPackage::getInstance()->rmPack($validated['package_id']);
        if ($result->affected) {
            $title = "删除一个包裹";
            $note = sprintf("%s × %s × %s", $pack->long, $pack->wide, $pack->high);
            # 添加日志
            Oplog::getInstance()->addSoLog($userId, $pack->shiptask_id, $title, $note);

            # 同步到RabbitMq
            $messageBody = array(
                "method"=>"rmPack",
                "params"=>$validated,
            );
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);
        }
        return $this->renderJson($result);
    }

    //编辑包裹
    public function editPack(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'package_id' => 'required|integer|gt:0',
                'long' => 'numeric|gt:0',
                'wide' => 'numeric|gt:0',
                'high' => 'numeric|gt:0',
                'netWeight' => 'numeric|gt:0',
                //'grossWeight' => 'required|numeric|gt:0',
                'volume_weight' => 'numeric',
                'shiptask_item_id' => 'string|max:200',  // [{"id":"","num":"","specs_id":""}] id型号，specs_id规格id，num数量
            ]);
            $validated["grossWeight"] =0;
            $itemList = !empty($validated["shiptask_item_id"]) ? json_decode($validated["shiptask_item_id"],true) :[];
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        # 查询包裹id对应的记录是否存在
        $pack = ShipTaskPackage::getInstance()->getPackById($validated['package_id']);
        if (!$pack) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        #处理打包型号数据
        if(!empty($itemList)){
            $totalPackgeNum = 0;
            foreach ($itemList as $obj){
                $totalPackgeNum += $obj["num"];
            }
            $validated["package_quantity"] = $totalPackgeNum; #打包总件数
            unset($validated["long"],$validated["wide"],$validated["high"],$validated["netWeight"]);
        }
        else{
            unset($validated["shiptask_item_id"]);
        }
        ShipTask::getInstance()->updateShipTask($pack->shiptask_id,["Update_tiem"=>date("Y-m-d H:i:s")]);

        $result = new \stdClass();
        $result->affected = ShipTaskPackage::getInstance()->updatePack($validated["package_id"], $validated);
        if ($result->affected) {
            # 添加日志
            $note = sprintf("%s × %s × %s", $pack->long, $pack->wide, $pack->high);
            Oplog::getInstance()->addSoLog(UserId::$user_id, $pack->shiptask_id, "编辑一个包裹", $note);

            # 同步到RabbitMq
            $pack = ShipTaskPackage::getInstance()->getPackById($validated['package_id']);
            $packModel = "";
            if(!empty($pack->shiptask_item_id)){
                $packModel = $this->returnSpecs(json_decode($pack->shiptask_item_id));  #解析包裹里的型号
            }
            $shiptask = ShipTask::getInstance()->getByIdF1($pack->shiptask_id);
            $messageBody = array(
                "method"=>"editPack",
                "params"=>[
                    "package_id" => $pack->id,
                    "long" => $pack->long,
                    "wide" => $pack->wide,
                    "high" => $pack->high,
                    "netWeight" => $pack->netWeight,
                    "grossWeight" => $pack->grossWeight,
                    "shiptask_id" => $pack->shiptask_id,
                    "crm_shiptask_id" => $shiptask->crm_shiptask_id,
                    "shiptask_item_id"=> $packModel,
                ],
            );
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);
        }
        return $this->renderJson($result);
    }

    private function returnSpecs($tmpArray)
    {
        if(empty($tmpArray)) return [];

        $soItem = [];
        foreach ($tmpArray as $obj){
            #处理shiptask_item_id，增加crm_shiptask_item_id??
            $obj->products_id = 0;
            if(empty($soItem[$obj->id])){
                $si = ShipTaskItem::getInstance()->getItemById($obj->id);
                $obj->products_id = $si->products_id;
                $soItem[$obj->id] = $si->crm_shiptask_item_id;
            }
            $obj->crm_shiptask_item_id = $soItem[$obj->id];

            #处理规格，sku为空的情况
            if(!$obj->specs_id){
                $res = IhuProductSpecs::getInstance()->DefaultSpecs($obj->products_id);
                $obj->specs_id = $res->specs_id;
                $obj->specs_name = $res->specs_name;
                $obj->condition = $res->condition;
                $obj->sku = $res->sku;
            }else{
                $spec =  IhuProductSpecs::getInstance()->getById($obj->specs_id);
                $obj->specs_name = $spec->specs_name;
                $obj->condition = $spec->condition;
                $obj->sku = $spec->sku;
            }
        }
        return $tmpArray;
    }
}
