<?php

namespace App\Http\Controllers;

use Illuminate\Validation\Rules\Enum;
use App\Ok\Enum\CheckConfirmed;
use Illuminate\Http\Request;
use stdClass;
use \App\Services\SerialNumbers;
use \App\Services\ExpressDelivery;
use \App\Http\Middleware\UserId;
use \App\Services\Products;
use \App\Services\Oplog;
use \App\Services\OplogApi;
use \App\Ok\SysError;
use \App\Services\M\IhuProduct;
use \App\Services\Attachments;
use \App\Services\ExpressOrders;
use \App\Ok\Enum\SerialType;
use \App\Services\ExpressAbnormalService;
use \App\Services\M\OrdersItemInfo;
use App\Services\M\User as MissuUser;


class ModelController extends Controller
{
    //产品型号模糊搜索
    public function modelSerach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                //'express_id' => 'integer|required',
                'model' => 'string|max:64',
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
            if(!isset($validated['model'])) $validated["model"] = "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;
        $data->list = Products::getInstance()->getProducts($validated["express_id"],$validated["model"]);
        return $this->renderJson($data);
    }

    //快递产品详情
    public function modelInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = Products::getInstance()->getProduct($validated['product_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        # 统计订货数量，一个快递型号不只一个pod单
        $data->quantity = ExpressOrders::getInstance()->getSumQuantity($validated['product_id']);
        if($data->abnormal_status ==0){ #无异常时，实到数量=订货数量
            $data->actual_quantity = $data->quantity;
        }
        if(empty($data->actual_model)){ #无异常时，实到型号=订货型号
            $data->actual_model = $data->model;
        }
        Products::getInstance()->updateModel($validated['product_id'],["quantity"=>$data->quantity]);

        #产品图片
        $data->attachment = Attachments::getInstance()->getAttachments( $validated['product_id'],2 );
        $flag = Attachments::getInstance()->getExceptional($data->id);
        $data->attachExceptional = $flag? 1 :0; //附件异常:0没异常，1有异常

        //$data->brand = IhuProduct::getInstance()->getModelByID($data->product->ihu_product_id);
        #序列号
        //[$data->serialNumbers,] = SerialNumbers::getInstance()->serialNumberList($validated);
        return $this->renderJson($data);
    }

    //编辑产品, 如果实际型号，数量不一致变更为异常单
    public function editModel(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',  //用于日志记录
                'product_id' => 'integer|required',
                'actual_model' => 'max:64',
                'actual_quantity' => 'integer|gt:0',
                'note' => 'max:255',
                'purchase_note'=> 'max:255',
            ]);
            $validated['note'] = !empty($validated['note']) ? htmlspecialchars($validated['note']) : "";
            $validated['purchase_note'] = !empty($validated['purchase_note']) ? json_decode($validated['purchase_note']) : "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = Products::getInstance()->getProduct($validated['product_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $order_id = $validated['order_id']; unset($validated['order_id']);

        #验证型号在产品表中是否存在
        $isExist = IhuProduct::getInstance()->isExist($validated["actual_model"]);
        //if(!$isExist){
        //    return $this->renderErrorJson([21, $validated['actual_model'].'产品型号不存在']);
        //}

        #业务，实际到货数量，到货型号不一致时 or 存在异常图片, 变更状态为异常快递;
        //$flag = Attachments::getInstance()->getExceptional($data->id); #去掉了

        //'异常状态: 0无异常，1 异常, 2 异常处理中；3异常已处理',
        if($validated["actual_model"] == $data->model && $validated["actual_quantity"] == $data->quantity){
            if($data->is_confirmed){
                $validated["abnormal_status"] = 2;
            }else{ #没有提交前
                $validated["abnormal_status"] = 0;
            }
        }else{
            $validated["abnormal_status"]=1;
        }

        $result = new stdClass();
        $result->affected = Products::getInstance()->updateModel($validated['product_id'],$validated);
        if($result->affected){
            ExpressDelivery::getInstance()->updateExpressDelivery($data->express_id, ["abnormal_submit"=>1]);   #编辑产生的异常快递，默认是已提交的
            ExpressAbnormalService::getInstance()->expressAbnormalStatus($data->express_id); #统计得到快递总的异常处理状态
            #记日志,有异常
            if($validated["abnormal_status"]){
                $str = $data->model."*".$data->quantity."被更改为".$validated["actual_model"]."*".$validated["actual_quantity"];
                Oplog::getInstance()->addCheckLog(UserId::$user_id, $order_id, "修改快递型号",$str);
            }
        }
        return $this->renderJson($result);
    }

    //采购编辑快递产品： 变更为已处理
    public function purchaseProcess(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',  //用于日志记录
                'product_id' => 'integer|required',
                'purchase_note'=> 'max:255', //可能会有多个采购备注  [{"note":"",“purchase_id":""},{"note":"",“purchase_id":""}]
            ]);
            $validated['purchase_note'] = !empty($validated['purchase_note']) ? json_decode($validated['purchase_note']) : "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = Products::getInstance()->getProduct($validated['product_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $result = new stdClass();
        $result->orderItem = [];
        #已提交则不能再提交
        if($data->is_confirmed || $data->model != $data->actual_model || $data->quantity != $data->actual_quantity){
            return $this->renderErrorJson([21,"已提交/型号数量和实到不一致"]);
        }

        #订单型号的到货型号对应，且数量相等，则将此数据同步到PI的序列号和验货数量上去 (在编辑产品接口处理)
        $data->user_id = UserId::$user_id;
        [$result->orderItem,$result->affect] = ExpressAbnormalService::getInstance()->productNumToPI($data);
        if(empty($validated['purchase_note'])){
            unset($validated['purchase_note']);
        }
        if($result->affect){
            ExpressAbnormalService::getInstance()->expressAbnormalStatus($data->express_id); #统计得到快递总的异常处理状态
            $express = ExpressDelivery::getInstance()->getExpressDelivery($data->express_id);
            \App\Services\MessageService::getInstance()->changeAbnormalStatus($data->user_id,[
                "tracking_number"=>$express->tracking_number,
                "express_id" =>$express->id,
                "order_id" =>$validated['order_id']
            ]);
        }
        return $this->renderJson($result);
    }


    //修改快递产品，批量添加/删除序列号
    //gd_serial_numbers
    //  `express_id` bigint(19) unsigned DEFAULT '0' COMMENT '快递ID(已弃用)',
    //  `product_id` bigint(19) unsigned DEFAULT '0' COMMENT '产品ID(已弃用)',
    public function updateModel(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => 'integer|required',
                'note' => 'string|max:256',
                'shelf_position'=>'string|max:64',
                'is_confirmed' => [new Enum(CheckConfirmed::class), 'filled'],  //是否确认，
                'serial_type' => [new Enum(SerialType::class),'required', 'filled'],  //序列号类型
                'serial_list' => 'string',  // [{"id":"","serial_number":"H7EC-N","quantity":"5"}]
            ]);
            $validated['note'] = !empty($validated['note']) ? htmlspecialchars($validated['note']) : "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $validated['user_id'] = UserId::$user_id;
        $serial_list = !empty($validated["serial_list"]) ? json_decode($validated["serial_list"]) :[];

        # 获取产品详情
        $product = Products::getInstance()->getProduct($validated['product_id']);
        $brand = IhuProduct::getInstance()->getModelByID($product->ihu_product_id);

        # 统计产品总的pod数量，一个快递型号不只一个pod单
        $totalQuantity = ExpressOrders::getInstance()->getSumQuantity($validated['product_id']);

        # 统计数量和组装数据
        $tmpQuantity=0;
        $repeat=[];
        foreach ($serial_list as $obj){
            $tmpQuantity += $obj->quantity;
            $repeat[$obj->serial_number] = ($repeat[$obj->serial_number] ?? 0) + 1;
        }
        # 判断数量
        if($tmpQuantity > $totalQuantity){
            return $this->renderErrorJson(SysError::SERIAL_NUMBER_ERROR);
        }
        # 判断序列号不能重复
        $tmp = [];
        foreach ($repeat as $key=>$obj){
            if($obj>1) $tmp[] = $key;
        }
        if(count($tmp)){
            return $this->renderErrorJson(21, "存在如下重复的序列号：".implode(",", $tmp) );
        }

        $data = new stdClass();
        #组装参数
        $param = [];  $updatParam=[];
        if($product->submit_status != 3){   # submit_status '提交状态: 1 待收,2 妥收,3预交',
            #如果产品状态为1 待收,2 妥收，则批量先删除再保存
            foreach ($serial_list as $obj){
                $obj->quantity = ($validated["serial_type"] == 1) ? 1 : $obj->quantity;
                $obj->serial_number = ($validated["serial_type"] == 3) ? "" : $obj->serial_number;
                $param[] = [
                    "id" => SerialNumbers::getInstance()->get_unique_id(),
                    "user_id" => $validated['user_id'],
                    "express_id" => $product->express_id,
                    "product_id" => $product->id,
                    "serial_number" => $obj->serial_number,
                    "quantity" => $obj->quantity,
                    "status" => 1,
                    "type" => $validated["serial_type"],
                ];
            }
            $data->affected = SerialNumbers::getInstance()->saveAllSerialNumber($param,$validated['product_id']);
        }
        else{
            #如果产品状态为3预交，则之前添加的序列号不能删除，只能修改和增加
            foreach ($serial_list as $obj)
            {
                $obj->quantity = ($validated["serial_type"] == 1) ? 1 : $obj->quantity;  #单一序列号数量固定为1
                $obj->serial_number = ($validated["serial_type"] == 3) ? "" : $obj->serial_number;

                $tmp = [
                    "user_id" => $validated['user_id'],
                    "express_id" => $product->express_id,
                    "product_id" => $product->id,
                    "serial_number" => $obj->serial_number,
                    "quantity" => $obj->quantity,
                    "status" => 1,
                    "type" => $validated["serial_type"],
                ];

                if(!empty($obj->id)){
                    if($validated["serial_type"] != 1){  #单一序列号不用修改数量，数量固定为1
                        $serial = SerialNumbers::getInstance()->getSerialNumberById($obj->id);
                        if($obj->quantity > $serial->quantity){  #只有数量有变更时才需要更新
                            $tmp["id"] = $obj->id;
                            $tmp["new_quantity"] = ($obj->quantity - $serial->quantity) + $serial->new_quantity;
                            $updatParam[] = $tmp;
                        }
                    }
                }else{
                    $tmp["id"] = SerialNumbers::getInstance()->get_unique_id();
                    $tmp["new_quantity"] = $obj->quantity;
                    $param[] = $tmp;
                }
            }
            $data->affected = SerialNumbers::getInstance()->updateSerialNumber($param,$updatParam);
        }

        if($data->affected){
            $param = ["note"=>$validated['note'], "shelf_position"=>$validated['shelf_position'],"is_confirmed"=>$validated['is_confirmed']];
            Products::getInstance()->updateModel($validated['product_id'],$param);
            ExpressDelivery::getInstance()->updateExpressDelivery($product->express_id,["is_confirmed"=>1]);
            Oplog::getInstance()->addExpLog($validated['user_id'], $validated['product_id'], "核对快递内产品", "{$product->model}×{$product->quantity}");
            OplogApi::getInstance()->addLog(UserId::$user_id, '修改快递内型号',sprintf("%s, %s", +$data->affected, json_encode($validated)));
        }

        # 队列处理: 立即同步调度任务
        \App\Jobs\ProductUpdate::dispatchSync($validated['product_id']);

        return $this->renderJson($data);
    }

    //快递产品列表,无用
    public function modelList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer|required',
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'model' => 'string|max:64',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;
        [$data->list, $data->total] = Products::getInstance()->getProductsPage($validated);
        foreach ($data->list as $item) {
            $item->serialNumber = SerialNumbers::getInstance()->getProductSerialNumbers($item->id);
            $item->brand = IhuProduct::getInstance()->getModelByID($item->ihu_product_id);

            # 产品关联图片或视频
            $item->attachments = Attachments::getInstance()->getAttachments($item->id,2);
        }

        return $this->renderJson($data);
    }

    //添加快递产品
    public function createModel(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer|required',
                'model' => 'required|max:64',
                'ihu_product_id' => 'required|integer',
                'note' => 'max:255',
                'serial_number_list'=>'required',  //增加序列号数组：[{"serial_number":"","quantity":0,"note":""}]
            ]);
            $validated['note'] = !empty($validated['note']) ? htmlspecialchars($validated['note']) : "";
            //$validated["serial_number_list"] = json_decode($validated["serial_number_list"],true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $validated['user_id'] = UserId::$user_id;

        if(preg_match('/[^\x00-\x80]/', $validated["model"]) ){
            return $this->renderErrorJson(21, "model不能包含中文");
        }
        # 判断型号是不是存在
        $res = IhuProduct::getInstance()->getModelByID($validated['ihu_product_id']);
        if(!$res){
            return $this->renderErrorJson(SysError::PRODUCT_NO_EXISTS_ERROR);
        }

        # 判断产品是否已经存在
        $product = Products::getInstance()->getExpProduct($validated['express_id'], $validated['model']);
        if ($product) {
            return $this->renderErrorJson(SysError::PRODUCT_EXISTS_ERROR);
        }

        # 判断产品品牌是不是西门子,是西门子则序列号必填,
        $tmp = array();$empty = 0;
        if(strstr(strtoupper($res->brand_name), "SIEMENS")){
            foreach ($validated["serial_number_list"] as $obj){
                if(empty($obj["serial_number"])){
                    return $this->renderErrorJson(21, "当前型号的品牌为西门子，序列号不能为空");
                }
            }
        }
        foreach ($validated["serial_number_list"] as $obj){
            $key = trim($obj["serial_number"]);

            if(!empty($obj["serial_number"])  && preg_match('/[^\x00-\x80]/', $obj["serial_number"]) ){
                return $this->renderErrorJson(21, "serial_number不能包含中文");
            }
            # 统计序列号，判断序列号有没有重复添加的
            if(!$key) {$empty += 1;}
            else{
                if(empty($tmp[$key])){$tmp[$key] = []; $tmp[$key] = 1;}
                else {$tmp[$key] += 1;}
            }
        }
        $str = array();
        foreach ($tmp as $k=>$v){
            if($v>1){ $str[] = $k;}
        }
        if(count($str) || $empty>1){
            if($empty>1){
                $str[] = "序列号为空";
            }
            $str = implode(",", $str);
            return $this->renderErrorJson(21, "如下序列号存在重复值：{$str}");
        }


        $data = new stdClass();
        $data->id = Products::getInstance()->createModel($validated);

        \App\Jobs\ProductUpdate::dispatchSync($data->id);
        OplogApi::getInstance()->addLog(UserId::$user_id, '新建快递内型号',sprintf("%s, %s", $data->id, json_encode($validated)));

        return $this->renderJson($data);
    }

    //删除快递产品
    public function deleteModel(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $product = Products::getInstance()->getProduct($validated['id']);
        if (!$product) {
            return $this->renderErrorJson(SysError::PRODUCT_ID_ERROR);
        }

        $data = new stdClass();

        //快递产品下存在序列号，不允许删除
        $isExits = SerialNumbers::getInstance()->isExitsByProductId($product->express_id, $product->id);
        if($isExits){
            return $this->renderErrorJson(SysError::PRODUCT_MODEL_EXISTS_SERIAL_NUMBER_ERROR);
        }
        $data->affected =Products::getInstance()->deleteModel($validated['id']);

        OplogApi::getInstance()->addLog(UserId::$user_id, '删除快递内型号',sprintf("%s, %s", +$data->affected, $validated['id']));

        return $this->renderJson($data);
    }

    //扫序列号
    public function scanSerialNumbers(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'serial_number' => 'required|min:1|max:512',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $info = SerialNumbers::getInstance()->getSerialNumberByNumber($validated['serial_number']);
        if (!$info) {
            return $this->renderErrorJson(SysError::SERIAL_NUMBER_NO_EXISTS_ERROR);
        }
        return $this->renderJson($info);
    }


}
