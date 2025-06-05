<?php

namespace App\Http\Controllers;

use \Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Ok\Enum\SignStatus;
use App\Ok\Enum\CheckStatus;
use App\Ok\Enum\CheckConfirmed;
use \App\Ok\SysError;
use \App\Services\M\Orders;
use \App\Services\M\PurchaseOrder;
use \App\Services\M\ExpressCompany;
use \App\Services\ExpressDelivery;
use \App\Http\Middleware\UserId;
use \App\Services\Products;
use \App\Services\Attachments;
use \App\Services\OplogApi;
use \App\Services\Message;
use \App\Services\SerialNumbers;
use \App\Services\M\ShipTask;
use \App\Services\Oplog;
use \App\Services\ExpressOrders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Ok\Enum\OrderSourceStatus;


//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;


use stdClass;


class ExpressController extends Controller
{
    //快递物流
    public function ExpressLogistics(Request $request): \Illuminate\Http\JsonResponse
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

        //获取快递信息
        $data = ExpressDelivery::getInstance()->getExpressDelivery($validated['id']);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }

        $param = [
            'com'=>'auto', //com: 快递公司字母简称,可以从接口"快递公司查询" 中查到该信息 可以使用"auto"代替表示自动识别,不推荐大面积使用auto，建议尽量传入准确的公司编码。
            'nu'=> $data->tracking_number, //快递单号
            'receiver_phone'=> $data->receiver_phone??"", // 收/寄件人手机号后四位，顺丰快递必须填写本字段
            'sender_phone'=>$data->sender_phone??"",
        ];
        $data->list = \App\Services\AliDeliver::getInstance()->aliDeliverShowapi($param);
        return $this->renderJson($data);
    }

    //快递详情
    public function expressInfo(Request $request): \Illuminate\Http\JsonResponse
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

        //获取快递信息
        $data = ExpressDelivery::getInstance()->getExpressDelivery($validated['id']);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        if(empty($data->actual_purchaser_id) && $data->purchaser_id){
            $data->actual_purchaser_id = $data->purchaser_id;
        }
        if ($data->actual_purchaser_id) {
            //$data->purchaser = MissuUser::getInstance()->getUserByIds(explode(",", $data->purchaser_id));
            $data->actual_purchaser_id = MissuUser::getInstance()->getUserByIds(explode(",", $data->actual_purchaser_id));
        }

        //快递渠道 如:顺丰,圆通
        $data->channel = ExpressCompany::getInstance()->getChannel($data->channel_id);

        //快递的图片
        $data->attachments = Attachments::getInstance()->getAttachments($data->id,1);

        //产品列表:gd_products
        $data->products = Products::getInstance()->getProducts($data->id);
        foreach ($data->products as $product) {

            # 产品关联的订单: 有可能PI一样，POd不一样
            $product->pis = ExpressOrders::getInstance()->getExpressOrderByProductId($product->id);
            MissuUser::getInstance()->fillUsers($product->pis, 'Sales_User_ID', 'sales');

            foreach ($product->pis as $pi) {
                #获取PI对应的Pod
                [$pi->expressPod, $pi->totalQuantity]= ExpressOrders::getInstance()->getExpressPodByPi($pi->order_item_id,$pi->product_id);
                MissuUser::getInstance()->fillUsers($pi->expressPod, 'purchaser_id', 'purchaser');

                # PI相关发货任务
                $pi->sos = ShipTask::getInstance()->getByOrderId($pi->order_id);
            }

            # 产品关联图片或视频
            $product->attachments = Attachments::getInstance()->getAttachments($product->id,1);
        }

        return $this->renderJson($data);
    }

    //获取快递附件
    public function getExpressAttachments(Request $request): \Illuminate\Http\JsonResponse
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
        //获取快递信息
        $data = ExpressDelivery::getInstance()->getExpressDelivery($validated['id']);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        //快递的图片
        $data->attachments = Attachments::getInstance()->getAttachments($data->id,1);
        return $this->renderJson($data);
    }

    //修改快递
    public function updateExpress(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',  //用于日志记录
                'id' => 'integer|required',
                'channel_id' => 'integer',
                'tracking_number' => 'max:30',
                'actual_purchaser_id' => 'string|min:1',
                //'submit_status' => [new Enum(SignStatus::class), 'filled'],  //提交状态
                'receiver_phone'=>'string', // 收/寄件人手机号后四位，顺丰快递必须填写本字段
                'sender_phone'=>'string',
                'note' => 'string',
                'check_status' => [new Enum(CheckStatus::class),'required', 'filled'],
                //'is_confirmed' => [new Enum(CheckConfirmed::class), 'filled'],  //是否确认
            ]);
            $validated['actual_purchaser_id'] = !empty($validated['actual_purchaser_id']) ? $validated['actual_purchaser_id'] : "";
            $validated['note'] = !empty($validated['note']) ? htmlspecialchars($validated['note']) : "";
        }
        catch (\Illuminate\Validation\ValidationException|\App\Exceptions\OkException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $order_id = $validated['order_id']; unset($validated['order_id']);
        $validated['user_id'] = UserId::$user_id;

        if(isset($validated["tracking_number"]) && !preg_match('/^[A-Za-z0-9]+$/', $validated["tracking_number"]) ){
            return $this->renderErrorJson(21, "tracking_number只能是数字和字母");
        }
        //验证手机号码
        if(!empty($validated["receiver_phone"]) && !preg_match('/^1[3456789]\d{9}$/', $validated["receiver_phone"]) ){
            return $this->renderErrorJson(21, "receiver_phone参数错误");
        }
        if(!empty($validated["sender_phone"]) && !preg_match('/^1[3456789]\d{9}$/', $validated["sender_phone"]) ){
            return $this->renderErrorJson(21, "sender_phone参数错误");
        }
        # 判断快递存在
        $oldExpress = ExpressDelivery::getInstance()->getById($validated['id']);
        if (!$oldExpress) {
            return $this->renderErrorJson(SysError::EXP_ID_ERROR);
        }

        # 修改快递，更新产品的提交状态
        $data = new stdClass();
        $data->affected = ExpressDelivery::getInstance()->updateExpressDelivery($validated['id'], $validated);

        #记日志
        $condition = ($validated["channel_id"] != $oldExpress->channel_id) || ($validated["tracking_number"] != $oldExpress->tracking_number);
        if($data->affected && ($condition)){
            $oldChannel = ExpressCompany::getInstance()->getChannel($oldExpress->channel_id);
            $newChannel = ExpressCompany::getInstance()->getChannel($validated["channel_id"]);
            $str = $oldExpress->tracking_number."-".$oldChannel->expName."被更改为".$validated["tracking_number"]."-".$newChannel->expName;
            Oplog::getInstance()->addCheckLog($validated['user_id'], $order_id, "修改快递单号",$str);
            Oplog::getInstance()->addExpLog($validated['user_id'], $validated['id'], "修改快递", "{$str}");
        }
        return $this->renderJson($data);
    }

    //国家列表
    public function countryList(Request $request): \Illuminate\Http\JsonResponse
    {
        $result = new \stdClass();
        $list = Redis::command("get", ["gd_coutry_list"]);
        $result->list = json_decode($list);
        unset($list);
        if(empty($result->list)){
            $result->list = \App\Services\M\Country::getInstance()->getCountries();
            Redis::command("set", ["gd_coutry_list", json_encode($result->list), ['EX' => 3600 * 24 * 7]]);
        }
        return $this->renderJson($result);
    }

    //物流渠道列表
    public function getShippingWays(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = new stdClass();
        $list = Redis::command("get", ["gd_shipping_ways"]);
        $data->list = json_decode($list);
        unset($list);
        if(empty($data->list)){
            $data->list = \App\Ok\Enum\ShippingWays::map();
            Redis::command("set", ["gd_shipping_ways", json_encode($data->list), ['EX' => 3600 * 24 * 7]]);
        }
        return $this->renderJson($data);
    }

//----

    //异常快递列表
    public function abnormalList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'purchaser_id' => 'integer|gte:0',
                'abnormal_status' => 'integer|gte:0',
                'start_time' => 'string',
                'end_time' => 'string',
                'keyword' => 'string',  //['tracking_number', 'PI_name'];
                'keyword_type' => 'string', //默认显示型号，型号、PI、PO、快递单号
                'delivery_source' => [new Enum(OrderSourceStatus::class),], //订单来源：1个人的，2被分享的，3分享给别人的, 采购角色才有这个选项
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 10;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if(!empty($validated["abnormal_status"]) && !in_array($validated["abnormal_status"], [1,2,3,4]) ){
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $userID = UserId::$user_id;
        $data = new \stdClass();
        $data->list=[]; $data->total=0;

        $purchaser_id = !empty($validated["purchaser_id"])? $validated["purchaser_id"] : 0;
        $validated["user_id"] = $validated["express_id"] = $validated["purchaser_id"] = [];
        if($purchaser_id){
            $validated["purchaser_id"][] = $purchaser_id;
        }

        # 判断用户是否是管理员
        $userIds = MissuUser::getInstance()->isNotAdmin($userID);
        $info = MissuUser::getInstance()->getUserInfo($userID);
        $validated["user_id"][] = $userID;
        $validated["user_group_id"] = $info->user_group_id;

        if($userIds){
            if($info->user_group_id ==1){  #销售
                $orderIds = Orders::getInstance()->returnOrderIds(["sales_user_id"=>$userID]);
                $tmpIds = ExpressOrders::getInstance()->returnExpressId(["order_ids"=>$orderIds]);
                $validated["express_id"] = array_merge($validated["express_id"],$tmpIds);
            }
            else if($info->user_group_id == 3 && empty($validated["delivery_source"])){
                #权限分享
                [$purcharseIds,$expressIds] = \App\Services\PermissionShare::getInstance()->expressPermission($userID);

                #先判断搜索的有没有采购人员
                if(!empty($purchaser_id)) {
                    #查单个po分享，当前登录的采购，给搜索的采购单个po权限
                    #$expressIds = \App\Services\PermissionSingle::getInstance()->getExpressByShareId($purchaser_id,[$userID]);
                    $expressIds = \App\Services\PermissionSingle::getInstance()->getExpressByPurcharseId($userID,$purchaser_id);

                    #判断采购ID有全局权限
                    if(count($purcharseIds) && in_array($purchaser_id, $purcharseIds)){
                        $validated["purchaser_id"] = [$purchaser_id];
                    }
                    else if(count($expressIds)){
                        //$validated["permission_purchaser_id"] = [$purchaser_id];
                        $validated["permission_express_id"] = $expressIds;
                        $validated["permission_and"] = "and";
                    }
                    else {
                        return $this->renderJson($data);
                    }
                }
                else{
                    if(count($purcharseIds)){
                        $validated["user_id"] = array_merge($validated["user_id"],$purcharseIds);
                    }
                    if(count($expressIds)){
                        $validated["permission_express_id"] = array_merge($validated["express_id"],$expressIds);
                        $validated["permission_and"] = "or";
                    }
                }
            }
            else if($info->user_group_id==3 && !empty($validated["delivery_source"]))
            {
                #订单来源：1个人的，2被分享的(别人分享给自己的)，3分享给别人的, 采购角色才有这个选项
                if($validated["delivery_source"] ==1){
                    if(!empty($purchaser_id) && $purchaser_id!=$userID){return $this->renderJson($data);} #查个人的，且选的采购不是自己
                    $validated["purchaser_id"] = [$userID];
                }
                if($validated["delivery_source"] ==2){
                    $validated["purchaser_id"]=[];
                    if(!empty($purchaser_id) && $purchaser_id==$userID){return $this->renderJson($data);} #查被分享的，且选的采购是自己
                    else if($purchaser_id)
                    {  #查某个采购分享的给自己的
                        [$purchaseShare,$expressIds] = \App\Services\PermissionShare::getInstance()->expressShare($purchaser_id,$userID);

                        #判断采购ID有全局权限
                        if($purchaseShare){
                            $validated["purchaser_id"] = [$purchaser_id];
                        }
                        else if(count($expressIds)){
                            //$validated["permission_purchaser_id"] = [$purchaser_id];
                            $validated["permission_express_id"] = $expressIds;
                            $validated["permission_and"] = "and";
                        }
                        else {
                            return $this->renderJson($data);
                        }
                    }
                    else
                    { #查所有分享给自己
                        #权限分享
                        [$purcharseIds,$expressIds] = \App\Services\PermissionShare::getInstance()->expressPermission($userID);
                        if(empty($purchaseIds) && empty($expressIds)){return $this->renderJson($data);}
                        if(count($purcharseIds)){
                            $validated["user_id"] = array_merge($validated["user_id"],$purcharseIds);
                        }
                        if(count($expressIds)){
                            $validated["permission_express_id"] = array_merge($validated["express_id"],$expressIds);
                            $validated["permission_and"] = "or";
                        }
                    }
                }

                if($validated["delivery_source"] ==3) { # 3分享给别人的
                    if(!empty($purchaser_id) && $purchaser_id==$userID){return $this->renderJson($data);} #查分享给别人的，且选的采购是自己
                    else if($purchaser_id)
                    { #查分享给某个的
                        #查单个快递分享，当前登录的采购，给搜索的单个采购快递权限
                        [$purchaseShare,$expressIds] = \App\Services\PermissionShare::getInstance()->expressShare($userID,$purchaser_id);
                        if($purchaseShare){
                            $validated["purchaser_id"] = [$userID];
                        }else if(count($expressIds)){
                            $validated["express_id"] = $expressIds;
                            unset($validated["purchaser_id"]);
                        }else{
                            return $this->renderJson($data);
                        }
                    }
                    else
                    { #查所有分享给别人的
                        [$purchaseShare,$expressIds] = \App\Services\PermissionShare::getInstance()->myShareExpress($userID);
                        if($purchaseShare){
                            $validated["purchaser_id"] = [$userID];
                        }else if(count($expressIds)){
                            $validated["express_id"] = $expressIds;
                        }else{
                            return $this->renderJson($data);
                        }
                    }
                }
            }
        }



        # Po查询
        if(!empty($validated["keyword"]) && $validated["keyword_type"]=="po"){
            $poIds = PurchaseOrder::getInstance()->getPurchaseorderIdByName($validated["keyword"]);
            if(empty($poIds)){ return $this->renderJson($data); }
            $tmpIds = ExpressOrders::getInstance()->returnExpressId(["purchaseorder_ids"=>$poIds]);
            $validated["express_id"] = array_merge($validated["express_id"],$tmpIds);
            unset($validated["keyword"]);
        }

        # pi查询
        if(!empty($validated["keyword"]) && $validated["keyword_type"]=="pi"){
            $orderIds = Orders::getInstance()->returnOrderIds(["keyword"=>$validated["keyword"]]);
            if(empty($orderIds)){ return $this->renderJson($data); }

            $tmpIds = ExpressOrders::getInstance()->returnExpressId(["order_ids"=>$orderIds]);
            $validated["express_id"] = array_merge($validated["express_id"],$tmpIds);
            unset($validated["keyword"]);
        }

        [$data->list, $data->total] = ExpressDelivery::getInstance()->abnormalExpressList($validated);
        foreach ($data->list as $val) {
            $val->orderId = [];
            $val->channel = ExpressCompany::getInstance()->getChannelInfo($val->channel_id);
            if(!empty($val->purchaser_id)){
                $val->purchaser_id = MissuUser::getInstance()->getUserByIds(explode(",", $val->purchaser_id));
            }else{
                $val->purchaser_id =[];
            }
            if(!empty($val->actual_purchaser_id)){
                $val->actual_purchaser_id = MissuUser::getInstance()->getUserByIds(explode(",", $val->actual_purchaser_id));
            }else{
                $val->actual_purchaser_id = [];
            }

            $val->exQuantity = 0;
            $val->item = Products::getInstance()->getProducts($val->id);
            foreach ($val->item as $product){
                $val->exQuantity += $product->actual_quantity;
                # 产品关联的订单: 有可能PI一样，POd不一样
                $product->pis = ExpressOrders::getInstance()->getExpressOrderByProductId($product->id);
                MissuUser::getInstance()->fillUsers($product->pis, 'Sales_User_ID', 'sales');

                foreach ($product->pis as $pi) {
                    $val->orderId[] = $pi->order_id;
                    #获取PI对应的Pod
                    [$pi->expressPod, $pi->totalQuantity]= ExpressOrders::getInstance()->getExpressPodByPi($pi->order_item_id,$pi->product_id);
                    MissuUser::getInstance()->fillUsers($pi->expressPod, 'purchaser_id', 'purchaser');
                }
            }
        }
        return $this->renderJson($data);
    }

    //异常快递详情
    public function getAbnormalExpressInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer|required',
            ]);
        }
        catch (\Illuminate\Validation\ValidationException|\App\Exceptions\OkException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ExpressDelivery::getInstance()->getAbnormalInfo($validated["express_id"]);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        $data->purchaseorder = ExpressOrders::getInstance()->getPurchaseOrderByExpressId($validated["express_id"]);
        if(!empty($data->actual_purchaser_id)){
            $data->actual_purchaser_id = MissuUser::getInstance()->getUserByIds(explode(",", $data->actual_purchaser_id));
        }
        $data->channel = ExpressCompany::getInstance()->getChannelInfo($data->channel_id);
        $data->product = Products::getInstance()->getProducts($validated["express_id"]);
        foreach ($data->product as $obj){
            $obj->isExpressOrder = ExpressOrders::getInstance()->countPodIDByProductId($obj->id);
            $obj->attachment = Attachments::getInstance()->getAttachments( $obj->id,2 );
        }
        //快递的图片
        $data->attachments = Attachments::getInstance()->getAttachments($data->id,1);
        return $this->renderJson($data);
    }

    //添加异常快递-根据渠道/快递单号获取数据
    public function getExpressByExpressName(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'channel_id' => 'integer|required',
                'tracking_number' => 'max:30|required',
            ]);
        }
        catch (\Illuminate\Validation\ValidationException|\App\Exceptions\OkException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new stdClass();
        $data->express = ExpressDelivery::getInstance()->getExistedExpressDelivery($validated["channel_id"],$validated['tracking_number']);
        #判断是不是异常快递，如果是则提示
        if($data->express && $data->express->abnormal_status ){  // && empty($validated["express_id"])
            return $this->renderErrorJson(21, "异常快递单号已存在");
        }
        if($data->express){
            $data->express->purchaseorder = ExpressOrders::getInstance()->getPurchaseOrderByExpressId($data->express->id);
            if(empty($data->express->actual_purchaser_id)) $data->express->actual_purchaser_id = $data->express->purchaser_id;
            if(!empty($data->express->actual_purchaser_id)){
                $data->express->actual_purchaser_id = MissuUser::getInstance()->getUserByIds(explode(",", $data->express->actual_purchaser_id));
            }

            #获取快递附件
            $data->express->attachment = Attachments::getInstance()->getAttachments($data->express->id,1);
            #获取快递产品
            $data->product = Products::getInstance()->getProducts($data->express->id);
            foreach ($data->product as $obj){
                $obj->isExpressOrder = ExpressOrders::getInstance()->countPodIDByProductId($obj->id);
                #获取快递产品附件
                $obj->attachment = Attachments::getInstance()->getAttachments($obj->id,2 );
            }
        }
        return $this->renderJson($data);
    }

    //添加/编辑异常快递
    public function saveAbnormalExpress(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer',  //没有传0
                'channel_id' => 'integer|required',
                'tracking_number' => 'max:30|required',
                'actual_purchaser_id' => 'string|min:1|required',
                'model_list' => 'string', // [{"id":"","model":"H7EC-N","quantity":"5","fileIds":"[{"id",:"","flag":""}]"}]
                'abnormal_submit' => 'integer|required',  #0草稿; 1已提交
                'express_fileids' => 'string',  // [{"id",:"","flag":""}]
                'note' => 'string|max:256',
            ]);
            $validated["express_id"] = ($validated["express_id"] == -1) ?0 : $validated["express_id"];
        }
        catch (\Illuminate\Validation\ValidationException|\App\Exceptions\OkException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        #根据快递渠道/快递单号排查是否已存在
        $express = ExpressDelivery::getInstance()->getExistedExpressDelivery($validated["channel_id"],$validated['tracking_number']);
        if($express && $express->abnormal_status && empty($validated["express_id"])){
            return $this->renderErrorJson(21, "异常快递单号已存在");
        }
        if($express && empty($validated["express_id"])){
            return $this->renderErrorJson(21, "关联快递单号已存在，请先搜索");
        }
        if(empty($express) && !empty($validated["express_id"])){
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }

        #组装参数
        $productIds = [];
        if(empty($validated["express_id"])){
            $validated['abnormal_status'] = 1;  #新增时，异常状态
        }else{
            #关联快递新增型号变更快递单为异常
            //$express = ExpressDelivery::getInstance()->getExpressDelivery($validated["express_id"]);
            if($express->abnormal_status ==0) $validated['abnormal_status'] = 1;

            #获取express里的所有产品ID
            $productIds = Products::getInstance()->returnProductIds($validated["express_id"]);
        }
        $validated['abnormal_source'] = 1;  #异常来源
        $validated['user_id'] = UserId::$user_id;

        $data = new stdClass();
        $data->affected = ExpressDelivery::getInstance()->saveAbnormalExpress($validated,$productIds);
        if($data->affected && $validated["abnormal_submit"]==1){
            #异常快递提交时，给关联的所有采购发送消息
            \App\Services\MessageService::getInstance()->addAbnormalExpress($validated['user_id'],[
                "actual_purchaser_id"=>$validated['actual_purchaser_id'],
                "tracking_id" =>$data->affected,
            ]);
        }

        return $this->renderJson($data);
    }

    //批量编辑异常快递产品
    public function batchAbnormalProduct(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer|required',
                'channel_id' => 'integer|required',
                'tracking_number' => 'max:30|required',
                'actual_purchaser_id' => 'string|min:1|required',
                'model_list' => 'string|required', // [{"id":"","model":"H7EC-N","quantity":"5"}]
                'note' => 'string|max:256',
            ]);
        }
        catch (\Illuminate\Validation\ValidationException|\App\Exceptions\OkException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ExpressDelivery::getInstance()->getAbnormalInfo($validated["express_id"]);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        $result = new stdClass();
        $result->affected = ExpressDelivery::getInstance()->saveAbnormalProduct($validated);

        #修改了采购后，给新的采购发送消息
        $tmp1 = explode(",", $validated["actual_purchaser_id"]);
        $tmp2 = explode(",", $data->actual_purchaser_id);

        // 找出 array1 中有而 array2 中没有的元素
        $diff = array_diff($tmp1, $tmp2);
        if(!empty($diff)){
            \App\Services\MessageService::getInstance()->addAbnormalExpress(UserId::$user_id,$diff);
        }
        return $this->renderJson($result);
    }

    //异常快递列表：状态处理
    public function editAbnormalStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        //获取快递信息
        $data = ExpressDelivery::getInstance()->getExpressDelivery($validated['express_id']);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        if(in_array($data->abnormal_status, [0,3,4]) ){
            return $this->renderJson(new \stdClass(),0,"无需处理/已处理");
        }

        $userID = \App\Http\Middleware\UserId::$user_id;
        $result = new stdClass();
        $result->orderItem = [];
        #异常型号
        $result->abnormal_product = [];

        #新增的异常
        if($data->abnormal_source){
            $product = Products::getInstance()->getProducts($validated['express_id']);
            foreach ($product as $obj){
                #判断型号有无图片异常
                $flag = Attachments::getInstance()->getExceptional($obj->id);

                #判断存在型号数量是否一致，是否存在异常图片
                if($obj->model != $obj->actual_model || $obj->quantity != $obj->actual_quantity){
                    $result->abnormal_product[] = ["id"=>$obj->id,"model"=>$obj->model];
                    continue;
                }
                #必须是有关联PI
                $exporessOrder = ExpressOrders::getInstance()->getByPorductId($obj->id);
                if(empty($exporessOrder)){
                    $result->abnormal_product[] = ["id"=>$obj->id,"model"=>$obj->model];
                    continue;
                }
                [$result->orderItem,$result->affect] = \App\Services\ExpressAbnormalService::getInstance()->productNumToPI($obj);
            }
        }
        #编辑产生的异常
        else{
            #给快递里的产品判断，没有提交序列号的取出来，
            $expressProduct = \App\Services\Products::getInstance()->getProductNotConfirm($validated["express_id"]);
            if(empty($expressProduct)){
                $result->affect = ExpressDelivery::getInstance()->updateExpressDelivery($validated["express_id"], ["abnormal_status" =>3]);
                return $this->renderJson($result);
            }

            foreach ($expressProduct as $obj){
                if($obj->model != $obj->actual_model || $obj->quantity != $obj->actual_quantity){
                    $result->abnormal_product[] = ["id"=>$obj->id,"model"=>$obj->model];
                    continue;
                }
                $obj->user_id = UserId::$user_id;
                [$result->orderItem,$result->affect] = \App\Services\ExpressAbnormalService::getInstance()->productNumToPI($obj);
            }
        }
        #统计得到快递总的异常处理状态
        \App\Services\ExpressAbnormalService::getInstance()->expressAbnormalStatus($validated["express_id"]);
        return $this->renderJson($result);
    }

    //异常快递同步pI失败，转为异常记录状态
    public function turnAbnormalRecord(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'integer|required',
                'product_id' => 'string|required',  //多个逗号隔开
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        //获取快递信息
        $data = ExpressDelivery::getInstance()->getExpressDelivery($validated['express_id']);
        if (!$data) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        $result = new stdClass();
        $result->affect = Products::getInstance()->turnRecodeStatus($validated['express_id'],explode(",", $validated["product_id"]));
        return $this->renderJson($result);
    }

    //异常快递单号搜索: 用于权限分享时初始化数据，只能分享自己的单，不能是别人分享给自己的快递再次分享给他人
    public function abnormalExpressSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'keyword' => 'min:1|max:512',
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
            ]);
            if (empty($validated['page'])) $validated['page'] = 1;
            if (empty($validated['size'])) $validated['size'] = 20;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        #判断只能采购
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        $validated["purchaser_id"] = $userID;
        $validated["abnormal_status"] = [1,2];  //异常状态: 0无异常,1异常, 2 异常处理中,3异常已处理,4异常记录状态
        $validated["keyword_type"] = "express";

        //获取销售订单信息
        $data = new stdClass();
        [$data->list,$data->total] =  ExpressDelivery::getInstance()->getExpressList($validated);
        return $this->renderJson($data);
    }


//----

    //添加：快递关联采购订单
    public function expressOnPurchaseorder(Request $request): \Illuminate\Http\JsonResponse
    {
        Log::channel('sync')->info('内 expressOnPurchaseorder---- '.json_encode($request->post()));
        try {
            $validated = $request->validate([
                'user_id'=> 'integer|min:1',
                'channel_name' => 'string|required',
                'tracking_number' => 'required|max:30',
//                'purchaser_id' => 'integer|gte:0',
                'submit_status' => [new Enum(SignStatus::class), 'required', 'filled'],  //提交状态
                'check_status' => [new Enum(CheckStatus::class), 'required', 'filled',], //货物状态：0 缺货 1 货齐
                'receiver_phone'=>'string', // 收/寄件人手机号后四位，顺丰快递必须填写本字段
                'sender_phone'=>'string',
                'model_list'=>'string', //快递产品型号  [{"model":"CP5512","quantity":"11","pod_id":"","purchaser_id":""}] 数组序列化
            ]);
            //$validated["submit_status"] = 1;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        # 查询快递渠道(channel_name)，拿到channel_id
        $channel = ExpressCompany::getInstance()->getChannelByName($validated["channel_name"]);
        $validated["channel_id"] = $channel->express_id;

        $channel_name = $validated["channel_name"];
        unset($validated["channel_name"]);

        $data = new stdClass();
        [$data->id,$pIds,$eoIds] = ExpressDelivery::getInstance()->expressOrderService($validated);
        if($data->id){
            ExpressDelivery::getInstance()->fillPurchaseId($data->id,$pIds); #填充采购ID, 产品数量

            # 写日志
            $submit_status = $validated['submit_status'] == SignStatus::SIGNED->value ? "已签收":"未签收";
            $check_status = $validated['check_status'] == CheckStatus::COMPLETE->value ? "货齐":"货缺";
            Oplog::getInstance()->addExpLog($validated["user_id"], $data->id, "新建快递，采购订单关联快递","签收状态为：{$submit_status}；货物状态为：{$check_status}");

            #同步数据到外部crm系统
            $validated["id"] = $data->id;
            $validated["product_ids"] = $pIds;
            $validated["express_order_ids"] = $eoIds;
            $validated["channel_name"] = $channel_name;
            Log::channel('sync')->info('--syncAddExternalCrm--begin--');
            \App\Jobs\ExpressPurchaseSync::dispatchSync("syncAddExternalCrm",$validated);

            #处理异常快递状态
            //\App\Jobs\AbnormalExpressUpdate::dispatchSync($data->id);
        }
        return $this->renderJson($data);
    }


    //获取采购订单关联的所有快递
    public function getExpressPurchaseorder(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'pod_id' => 'integer|gte:0',
                'purchaser_id' => 'integer|gte:0',
                'user_id'=>'integer|gte:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        if(empty($validated["pod_id"]) && empty($validated["purchaser_id"]) && empty($validated["user_id"])){
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        # 判断快递采购订单 是否存在
        $data = ExpressOrders::getInstance()->getExpressOrder($validated);

        return $this->renderJson($data);
    }

    //移除快递采购订单
    public function removeExpressPurchaseorder(Request $request): \Illuminate\Http\JsonResponse
    {
        Log::channel('sync')->info('内 removeExpressPurchaseorder---- '.json_encode($request->post()));
        try {
            $validated = $request->validate([
                'pod_id' => 'required|integer|gte:0',
                'model' => 'required|string',
                'qty' => 'integer|gte:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $res = ExpressOrders::getInstance()->getExpressOrderByModel($validated["pod_id"],$validated["model"]);
        if(!$res){
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }
        $data = (object)$validated;

        $validated["qty"] = $validated["qty"] ?? $res->quantity;
        if($validated["qty"] == $res->quantity){
            $data->affected = ExpressOrders::getInstance()->removeExpressOrder($res->id);
        }else{
            $data->affected = ExpressOrders::getInstance()->updateExpressOrder($res->id, ["quantity"=>$res->quantity - $validated["qty"]]);
        }

        if($data->affected){
            #异常快递业务，删除的型号还是否存在关联，不存在则删除型号
            $express = ExpressDelivery::getInstance()->getExpressDelivery($res->express_id);
            $productNum = ExpressOrders::getInstance()->getSumQuantity($res->product_id);
            if($express->abnormal_source ==1 && $productNum==0){
                Products::getInstance()->deleteModel($res->product_id);
            }

            ExpressDelivery::getInstance()->fillPurchaseId($res->express_id,[$res->product_id]); #填充采购ID，统计产品数量
            Oplog::getInstance()->addExpLog($res->user_id, $res->id, "移除快递采购订单","",$validated);  # 写日志

            \App\Services\Sync\ExpressPurchaseSync::getInstance()->purchaseExpressQty([$validated["pod_id"]]);
            \App\Jobs\ExpressPurchaseSync::dispatchSync("syncRemoveExternalCrm",["id"=>$res->id]);  #同步数据到外部crm系统

            //\App\Jobs\AbnormalExpressUpdate::dispatchSync($res->express_id);  #处理异常快递状态
        }
        return $this->renderJson($data);
    }


    //同步外部的快递关联采购订单
    public function syncAddExpress(Request $request)
    {
        $validate = $request->post();
        Log::channel('sync')->info('syncAddExpress---- '.json_encode($validate));
        //var_dump($validate);

        //\App\Jobs\ExpressPurchaseSync::dispatchSync("add",$validate);
        \App\Jobs\ExpressPurchaseSync::dispatchSync("add",$validate);

        $data = new \stdClass();
        return $this->renderJson($data);
    }


    //同步移除: 外部的快递关联采购订单
    public function syncRemoveExpress(Request $request)
    {
        $validate = $request->post();
        Log::channel('sync')->info('syncRemoveExpress---- '.json_encode($validate));

        \App\Jobs\ExpressPurchaseSync::dispatchSync("remove",$validate);

        $data = new \stdClass();
        return $this->renderJson($data);
    }

    //同步ID填充
    public function syncFillIdExpress(Request $request)
    {
        $validate = $request->post();
        Log::channel('sync')->info('syncFillIdExpress---param: '.json_encode($validate));

        $data = new \stdClass();

        //填充快递/快递产品/快递采购单，相关的crm_**_id
        $express = $validate["express_delivery"];
        $product = $validate["experss_products"];
        $purchase = $validate["experss_orders"];
        if(!empty($express)){
            $data->affect = DB::table("gd_express_delivery")->where('id',$express["crm_id"])->update(["crm_id"=>$express["id"]]);
        }
        if(!empty($product)){
            foreach ($product as $val){
                $data->affect = DB::table("gd_products")->where('id',$val["crm_id"])->update(["crm_id"=>$val["id"],"crm_express_id"=>$val["express_id"]]);
            }
        }
        if(!empty($purchase)){
            foreach ($purchase as $val){
                $data->affect = DB::table("gd_express_order")->where('id',$val["crm_id"])->update(["crm_id"=>$val["id"],"crm_express_id"=>$val["express_id"],"crm_product_id"=>$val["product_id"]]);
            }
        }

        Log::channel('sync')->info('syncFillIdExpress---result: '.json_encode($data));
        return $this->renderJson($data);
    }


}
?>
