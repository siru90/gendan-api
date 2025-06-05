<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use \Illuminate\Support\Facades\Redis;
use App\Ok\SysError;
use Illuminate\Http\Request;
use stdClass;

use \App\Http\Middleware\UserId;
use Illuminate\Validation\Rules\Enum;
use \App\Ok\Enum\SerialType;
use App\Ok\Enum\CheckInspectStatus;
use App\Ok\Enum\CheckAuditStatus;
use App\Ok\Enum\AuditSatus;
use App\Ok\Enum\CheckConfirmed;
use App\Ok\Enum\OrderSourceStatus;
use \App\Services\ExpressOrders;
use \App\Services\M\PurchaseOrder;
use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\ShipTask;
use \App\Services\M\IhuProductSpecs;
use \App\Services\M\PurchaseOrderTaskDetailed;
use \App\Services\SerialNumbers;
use \App\Services\Oplog;
use \App\Services\OplogApi;
use \App\Services\Message;
use \App\Services\Attachments;
use \App\Services\ExpressDelivery;
use \App\Services\M\ExpressCompany;
use \App\Services\CheckDetail;
use Illuminate\Support\Facades\Log;

use App\Services\M\User as MissuUser;

//核对PI单
class CheckPiController extends Controller
{

    //核对列表
    public function checkList(Request $request): \Illuminate\Http\JsonResponse
    {
        Log::channel('sync')->info('消息处理 Rabbit MESSAGE: '.json_encode($request->get("inspect_status")));
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'sales_id' => 'integer|gt:0',
                'purchaser_id' => 'integer|gt:0',  //采购id
                //'state' => [new Enum(PiState::class),],
                'inspect_status' => [new Enum(CheckInspectStatus::class),],
                'is_audit' => [new Enum(CheckAuditStatus::class),],
                'start_time' => 'string',
                'end_time' => 'string',
                'keyword' => 'string',
                'keyword_type' => 'string', //默认显示型号，型号、PI、PO、快递单号
                'order_source' => [new Enum(OrderSourceStatus::class),], //订单来源：1个人的，2被分享的，3分享给别人的
                'list_type' => 'integer',//  0待处理，1已完成
                'refresh_cache' => 'integer', //0使用缓存，1强制刷新缓存
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            //if (!isset($validated['size'])) $validated['size'] = 8;
            $validated['size'] = 8;
            $validated["keyword_type"] = !empty($validated["keyword_type"])?$validated["keyword_type"]:"model";
            $validated["refresh_cache"] = !empty($validated["refresh_cache"])?$validated["refresh_cache"]:0;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new \stdClass();
        $data->list = [];
        $userID = \App\Http\Middleware\UserId::$user_id;
        $sales_id = !empty($validated["sales_id"])? $validated["sales_id"] : 0;
        $purchaser_id = !empty($validated["purchaser_id"])? $validated["purchaser_id"] : 0;

        $validated["permission_order_id"] = $validated["order_id"] = $validated["purchaser_id"] = $validated["sales_id"] = [];
        if($sales_id){
            $validated["sales_id"][] = $sales_id;
        }
        if($purchaser_id){
            $validated["purchaser_id"][] = $purchaser_id;
        }
        $purcharseIds = $piIds = [];

        //判断登录用户是否是管理员
        $info = MissuUser::getInstance()->getUserInfo($userID);
        $userIds = MissuUser::getInstance()->isNotAdmin($userID);
        if($userIds){
            if($info->user_group_id ==1){  #判断是不是销售,只看自己的
                $validated["sales_id"]= [$userID];
            }
            else if($info->user_group_id ==103){ #判断是不是销售跟单，能看所跟的销售的
                $validated["sales_id"] = array_merge($validated["sales_id"],$userIds);
            }
            else if($info->user_group_id==3 && empty($validated["order_source"])){ #采购查全部
                $validated["purchaser_id"] = [$userID];
                //-----权限
                #先判断搜索的有没有采购人员
                [$purcharseIds,$piIds] = \App\Services\PermissionShare::getInstance()->piPermission($userID);
                if(!empty($purchaser_id)) {
                    #查单个po分享，当前登录的采购，给搜索的单个采购po权限
                    $piIds = \App\Services\PermissionSingle::getInstance()->getPiByPurcharseId($userID,$purchaser_id,"order_id");

                    #判断采购ID有全局权限
                    if(count($purcharseIds) && in_array($purchaser_id, $purcharseIds)){
                        $validated["purchaser_id"] = [$purchaser_id];
                    }
                    else if(count($piIds)){
                        $validated["purchaser_id"] = [$purchaser_id];
                        $validated["permission_order_id"] = $piIds;
                        $validated["permission_and"] = "and";
                    }
                    else {
                        return $this->renderJson($data);
                    }
                }else{
                    if(count($purcharseIds)){
                        $validated["purchaser_id"] = array_merge($validated["purchaser_id"],$purcharseIds);
                    }
                    if(count($piIds)){
                        $validated["permission_order_id"] = $piIds;
                        $validated["permission_and"] = "or";
                    }
                }
            }
            else if($info->user_group_id==3 && !empty($validated["order_source"])){ #订单来源：1个人的，2被分享的，3分享给别人的

                if($validated["order_source"] ==1){
                    if(!empty($purchaser_id) && $purchaser_id!=$userID){return $this->renderJson($data);} #查个人的，且选的采购不是自己
                    $validated["purchaser_id"] = [$userID];
                }
                if($validated["order_source"] ==2){
                    $validated["purchaser_id"]=[];
                    if(!empty($purchaser_id) && $purchaser_id==$userID){return $this->renderJson($data);} #查被分享的，且选的采购是自己
                    else if($purchaser_id)
                    { #查某个被分享的
                        [$purchaseShare,$piIds] = \App\Services\PermissionShare::getInstance()->purchaseShare($purchaser_id,$userID);
                        if($purchaseShare){
                            $validated["purchaser_id"] = [$purchaser_id];
                        }
                        else if(count($piIds)){
                            $validated["purchaser_id"] = [$purchaser_id];
                            $validated["permission_order_id"] = $piIds;
                            $validated["permission_and"] = "and";  //1
                        }else{
                            return $this->renderJson($data);
                        }
                    }
                    else
                    { #查所有被分享的数据
                        [$purchaseIds,$piIds] = \App\Services\PermissionShare::getInstance()->piPermission($userID);
                        if(empty($purchaseIds) && empty($piIds)){return $this->renderJson($data);}
                        if(count($purchaseIds)){
                            $validated["purchaser_id"] = array_merge($validated["purchaser_id"],$purchaseIds);
                        }
                        if(count($piIds)){
                            $validated["permission_order_id"] = $piIds;
                            $validated["permission_and"] = "or";
                        }
                    }
                }

                if($validated["order_source"] ==3){ # 3分享给别人的
                    if(!empty($purchaser_id) && $purchaser_id==$userID){return $this->renderJson($data);} #查分享给别人的，且选的采购是自己
                    else if($purchaser_id)
                    {   #查分享给某个的
                        [$purchaseShare,$piIds] = \App\Services\PermissionShare::getInstance()->purchaseShare($userID,$purchaser_id);
                        if($purchaseShare){
                            $validated["purchaser_id"] = [$userID];
                        }else if(count($piIds)){
                            $validated["order_id"] = $piIds;
                            unset($validated["purchaser_id"]);
                        }else{
                            return $this->renderJson($data);
                        }
                    }
                    else
                    { #查所有分享给别人的
                        [$purchaseShare,$piIds] = \App\Services\PermissionShare::getInstance()->myShare($userID);
                        if($purchaseShare){
                            $validated["purchaser_id"] = [$userID];
                        }else if(count($piIds)){
                            $validated["order_id"] = $piIds;
                        }else{
                            return $this->renderJson($data);
                        }
                    }
                }

            }
            /*else{
                $validated["user_id"] = $userIds;
            }*/
        }

        #如果是po，快递单个查询，先查出对应的order_id
        if(!empty($validated["keyword"])){
            if($validated["keyword_type"] == "po"){
                $po = PurchaseOrder::getInstance()->getByName($validated["keyword"]);
                if(empty($po)){ return $this->renderJson($data); }
                foreach ($po as $val){
                    $validated["order_id"] = array_merge($validated["order_id"],explode(",", $val->order_id));
                }
                unset($validated["keyword"]);
            }
            else if($validated["keyword_type"] == "express"){
                // --权限
                //$userId = ($info->user_group_id==3) ? \App\Http\Middleware\UserId::$user_id : null;
                $userId = null;
                if($info->user_group_id==3){
                    $userId = [UserId::$user_id];
                    if(count($purcharseIds)){
                        $userId = array_merge($userId,$purcharseIds);
                    }
                    if(count($piIds)){
                        $validated["order_id"] = $piIds;
                    }
                }
                $express = ExpressOrders::getInstance()->getByExpressNumber($validated["keyword"],["order_id"=>$validated["order_id"]]);
                //$express = ExpressOrders::getInstance()->getByExpressNumber($validated["keyword"],["user_id"=>$userId,"order_id"=>$validated["order_id"]]);
                if(empty($express)){ return $this->renderJson($data); }
                foreach ($express as $val){
                    $validated["order_id"] = [$val->order_id];
                }
                unset($validated["keyword"]);
            }
            $validated["order_id"] = array_unique($validated["order_id"]);
        }

        if(isset($validated["inspect_status"]) || isset($validated["is_audit"])){
            // 获取半年前的时间，即6个月之前
            $halfYearAgo = strtotime("-6 months", time());

            // 格式化半年前的时间为年-月格式
            $formattedHalfYearAgo = date("Y-m-d H:i:s", $halfYearAgo);

            $tmp = ["return_id"=>"order_id", "start_time"=>$formattedHalfYearAgo,"end_time"=>date("Y-m-d H:i:s")];
            if(isset($validated["inspect_status"])) $tmp["inspect_status"]=$validated["inspect_status"];
            if(isset($validated["is_audit"])){
                $tmp["is_audit"]=$validated["is_audit"];
                if($validated["is_audit"] ==0) $tmp["attach_exceptional"]=1;
            }
            $checkIds = CheckDetail::getInstance()->returnIds($tmp);
            if(empty($checkIds)) {return $this->renderJson($data);}

            #过滤状态,根据登录人员能看到的pi明细进行过滤
            /*if(isset($validated["inspect_status"])){
                foreach ($checkIds as $obj){
                    $val->item = OrdersItemInfo::getInstance()->getItemByUserId($val->order_id,$userId); #如果是采购，只能看自己的数据
                }
            }*/

            $validated["order_id"] = array_merge($validated["order_id"],$checkIds);
            unset($validated["inspect_status"],$validated["audit_status"]);
        }


        $data = $this->salesList($validated,$info->user_group_id);

        return $this->renderJson($data);
    }

    //销售看到的列表
    private function salesList(array $validated,int $userGroupId)
    {
        $data = new \stdClass();
        # 查询采购订单表数据
        [$data->list,$data->total] = Orders::getInstance()->getCheckSaleList($validated);
        # 补齐(Sales_User_ID) （内部用户user表，外部用户missu_users表）中对应的用户id,name 放到$data->list["sales"]字段里
        MissuUser::getInstance()->fillUsers($data->list, 'Sales_User_ID', 'sales');

        foreach ($data->list as $val) {
            $val->orderNum =0;     #应到总件数
            $val->receivedNum = 0; #实到数量
            $val->CreateTime = date("Y-m-d H:i:s", $val->CreateTime);
            $val->attachExceptional=0;

            //-----权限
            $userId = $this->returnPurchaseIdPermission($userGroupId,UserId::$user_id,$val->order_id);
            #获取销售单明细
            $val->item = OrdersItemInfo::getInstance()->getItemByUserId($val->order_id,$userId); #如果是采购，只能看自己的数据
            $is_audit = $inspect_status = [];
            foreach ($val->item as $orderItem){
                //-----权限
                [,$orderItem->purchaseorder] = $this->returnPurchaseorder($val->order_id,$orderItem,$validated["refresh_cache"]);

                $val->orderNum += $orderItem->quantity;  #统计应到，实到，状态
                $check = CheckDetail::getInstance()->getCheckPi($orderItem->order_info_id); # 核对
                if(!empty($check)){
                    $val->receivedNum += $check->serial_quantity;
                    $inspect_status[] = $check->inspect_status;
                    $is_audit[] = $check->is_audit;
                    if($check->attach_exceptional) $val->attachExceptional=1;
                }else{
                    $inspect_status[] = 0;
                }

                #型号搜索，缺货标记
                if(!empty($validated["keyword"]) && $validated["keyword_type"] == "model"){
                    $tmpModel = str_replace(array(' ', '-','/'), '', $orderItem->product_name_pi);   #去掉字符串中空格，横杠，存在查询字符串；
                    $condition = str_contains($orderItem->product_name_pi, $validated["keyword"]) || str_contains($tmpModel, $validated["keyword"]);
                    if(!empty($check->serial_quantity)){
                        if ( ($condition) && $check->serial_quantity < $orderItem->quantity) {
                            $val->missing_quantity =1;  //是否缺货：1缺货
                        }
                    }else{
                        $val->missing_quantity =1;  //是否缺货：1缺货
                    }
                }
            }

            #得到总的验货状态、确认状态
            $is_audit = array_unique($is_audit);
            $inspect_status = array_unique($inspect_status);
            if(count($inspect_status) == 1 && $inspect_status[0]==0){
                $val->inspect_status = 0;
            }
            else if(count($inspect_status) == 1 && $inspect_status[0]==2){
                $val->inspect_status = 2;
            }
            else{
                $val->inspect_status = 1;
            }
            $val->is_audit = (count($is_audit) == 1 && $is_audit[0]==1)? 1: 0;
        }
        return $data;
    }

    //核对详情
    public function checkInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);

        //-----权限
        //$userId = ($info->user_group_id==3) ? $userID : null;
        $userId = $this->returnPurchaseIdPermission($info->user_group_id,$userID,$validated["order_id"]);

        $data->attachExceptional = 0; //附件异常:0没异常，1有异常
        $data->item = OrdersItemInfo::getInstance()->getItemByUserId($validated["order_id"],$userId); #如果是采购，只能看自己的数据
        foreach ($data->item as $val){
            //-----权限？
            [$val->specs,$val->purchaseorder] = $this->returnPurchaseorder($validated["order_id"],$val);

            # 核对明细数据
            $val->check = CheckDetail::getInstance()->getCheckPi($val->order_info_id);
            if(!empty($val->check)){
                #得到采购的序列号数量、已验货数量
                if($info->user_group_id==3){
                    //-----权限
                    [$quantity,$inspectQuantity] = SerialNumbers::getInstance()->getAllQuantity(["order_info_id"=>$val->order_info_id,"purchaser_id"=>$userId]);
                    $val->check->serial_quantity = $quantity;
                    $val->check->inspect_quantity = $inspectQuantity;
                }
                if($val->check->attach_exceptional) $data->attachExceptional = 1;  #图片异常
            }else{
                $val->check = CheckDetail::getInstance()->returnDefault($val->order_info_id);
            }
        }

        return $this->renderJson($data);
    }

    //核对附件
    public function checkAttach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id'=> 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        # 产品关联图片或视频
        $data = new \stdClass();
        $data->attachments = Attachments::getInstance()->getAttachments($validated["order_id"],7);
        $ids = OrdersItemInfo::getInstance()->returnOrderInfoIds($validated["order_id"]);
        $attach = Attachments::getInstance()->getAttachByInfoId($ids);
        $data->orderInfoAttach = [];
        foreach ($attach as $k =>$obj){
            if(empty($data->orderInfoAttach[$obj->correlate_id])) $data->orderInfoAttach[$obj->correlate_id]=[];
            $data->orderInfoAttach[$obj->correlate_id][] = $obj;
        }
        unset($attach);

        return $this->renderJson($data);
    }

    //产品详情
    public function productInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id'=> 'required|integer|gt:0',
                'order_info_id' => 'integer|required',
                'refresh_cache' => 'integer',
            ]);
            $validated["refresh_cache"] = !empty($validated["refresh_cache"])?$validated["refresh_cache"]:0;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $data = new stdClass();
        # 获取产品详情
        $data->product = OrdersItemInfo::getInstance()->getByIdAS($validated["order_info_id"]);

        $data->check = CheckDetail::getInstance()->getCheckPi($validated["order_info_id"]);
        if(empty($data->check)){
            $data->check = CheckDetail::getInstance()->returnDefault($validated["order_info_id"]);
        }
        [$data->specs,$data->purchaseorder] = $this->returnPurchaseorder($validated["order_id"],$data->product,$validated["refresh_cache"]);

        foreach ($data->purchaseorder as $val){
            unset($val->express); #快递的数据不返回回去
            #序列号??
            [$val->serialNumbers,] = SerialNumbers::getInstance()->serialNumberList(["order_info_id"=>$validated["order_info_id"],"purchaser_id"=>$val->Purchaser_id,]);
        }

        return $this->renderJson($data);
    }

    //产品型号模糊搜索
    public function modelSerach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = \App\Http\Middleware\UserId::$user_id;

        $data = (object)$validated;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        //-----权限
        //$userId = ($info->user_group_id==3) ? $userID: null;
        $userId = $this->returnPurchaseIdPermission($info->user_group_id,$userID,$validated["order_id"]);

        $data->list = OrdersItemInfo::getInstance()->getItemByUserId($validated["order_id"],$userId); #如果是采购，只能看自己的数据
        foreach ($data->list as $val){
            $val->check = CheckDetail::getInstance()->getCheckPi($val->order_info_id);
            if(empty($data->check)){
                $val->check = CheckDetail::getInstance()->returnDefault($val->order_info_id);
            }
        }

        return $this->renderJson($data);
    }

    //保存核对产品，
    public function saveModel(Request $request): \Illuminate\Http\JsonResponse
    {
        //$recipient = [1000000549];
        //$order_id = 73190;
        //$shareUserIds = \App\Services\PermissionShare::getInstance()->orderIdShareUser($recipient,$order_id);
        //$recipient = array_unique(array_merge($recipient,$shareUserIds));


        /*$args=[
            "purchaser_id"=>1000000549,
            "PI_name"=>"SA2411256428",
            "order_id"=>73190,
        ];
        */
        //\App\Services\MessageService::getInstance()->checkSubmit(1000000579,$args);

        //var_dump($recipient);
        //die;

        try {
            $validated = $request->validate([
                'order_info_id' => 'integer|required',
                'note' => 'string|max:256',
                'shelf_position'=>'string|max:64',
                'is_confirmed' => [new Enum(CheckConfirmed::class), 'filled'],  //0未确认，1部分确认，2已确认
                'serial_type' => [new Enum(SerialType::class),'required', 'filled'],  //序列号类型
                'serial_list' => 'string',  // [{"id":"","serial_number":"H7EC-N","quantity":"5"}]  ，有id时传id
                'purchaser_id' => 'integer|required',
                //'specs_id'=>'integer|'  # 默认为0，即单规格
            ]);
            $validated['note'] = !empty($validated['note']) ? htmlspecialchars($validated['note']) : "";
            $validated['shelf_position'] = !empty($validated['shelf_position']) ? htmlspecialchars($validated['shelf_position']) : "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $validated['user_id'] = UserId::$user_id;
        $serial_list = !empty($validated["serial_list"]) ? json_decode($validated["serial_list"]) :[];

        # 获取产品详情
        $product = OrdersItemInfo::getInstance()->getByIdAS($validated["order_info_id"]);
        //Log::channel('sync')->info(json_encode($validated));

        # 统计数量和组装数据
        $tmpQuantity=0;
        $repeat=[];
        foreach ($serial_list as $obj){
            $tmpQuantity += $obj->quantity;
            $repeat[$obj->serial_number] = ($repeat[$obj->serial_number] ?? 0) + 1;
        }
        # 判断序列号数量大于缺货数
        if($tmpQuantity > $product->quantity){
            return $this->renderErrorJson(SysError::SERIAL_NUMBER_ERROR);
        }

        # 判断序列号不能重复
        $tmp = [];
        foreach ($repeat as $key=>$obj){
            if($obj>1)  $tmp[] = $key;
        }
        if(count($tmp)){
            return $this->renderErrorJson(21, "存在如下重复的序列号：".implode(",", $tmp) );
        }

        $serialIds = SerialNumbers::getInstance()->returnIds(["order_info_id"=>$validated["order_info_id"],"purchaser_id"=>$validated["purchaser_id"]]);
        $serialIds = array_flip($serialIds);
        $data = new stdClass();

        #组装参数
        $param = [];  $updatParam=[];
        foreach ($serial_list as $obj) {
            $obj->quantity = ($validated["serial_type"] == 1) ? 1 : $obj->quantity;  #单一序列号数量固定为1
            $obj->serial_number = ($validated["serial_type"] == 3) ? "" : $obj->serial_number;

            $tmp = [
                "user_id" => $validated['user_id'],
                "order_id" => $product->order_id,
                "order_info_id" => $product->order_info_id,
                "purchaser_id" => $validated['purchaser_id'],
                "serial_number" => $obj->serial_number,
                "quantity" => $obj->quantity,
                "status" => 1,
                "type" => $validated["serial_type"],
            ];

            if(!empty($obj->id)){
                $serial = SerialNumbers::getInstance()->getSerialNumberById($obj->id);
                if($obj->quantity != $serial->quantity){ # 数量不等时，才需要更新；已验货的数量不能再少了，在前端验证??
                    $tmp["id"] = $obj->id;
                    $updatParam[] = $tmp;
                }
                unset($serialIds[$obj->id]);
            }
            else{
                $tmp["id"] = SerialNumbers::getInstance()->get_unique_id();
                $param[] = $tmp;
            }
        }

        //var_dump(array_flip($serialIds));
        #批量保存，或者更新,删除
        $data->affected = SerialNumbers::getInstance()->updateSerialNumber($param,$updatParam,array_flip($serialIds));
        $actualNum = SerialNumbers::getInstance()->getSumQuantity(["order_info_id"=>$validated["order_info_id"]]); #统计实到件数
        $param = [
            "user_id"=>UserId::$user_id,
            "serial_quantity"=>$actualNum,
            "note"=>$validated['note'],
            "shelf_position"=>$validated['shelf_position'],
            "confirm_status"=>$validated['is_confirmed'],
            "order_info_id"=>$validated["order_info_id"],
            "order_id"=>$product->order_id,
        ];
        CheckDetail::getInstance()->setCheckByInfoId($validated["order_info_id"],$param);
        if($data->affected){
            $order = Orders::getInstance()->getByIdAS($product->order_id);
            \App\Services\MessageService::getInstance()->checkSubmit($validated['user_id'],[
                "PI_name"=>$order->PI_name,
                "order_id"=>$order->order_id,
                "purchaser_id"=>$validated["purchaser_id"]
            ]);
            Oplog::getInstance()->addCheckLog($validated['user_id'], $product->order_id, "修改核对产品","{$product->product_name_pi}×{$tmpQuantity}");
        }

        # 队列处理: 立即同步调度任务
        \App\Jobs\ProductUpdate::dispatchSync(["order_id"=>$product->order_id],null);

        return $this->renderJson($data);
    }

    //核对备注
    public function checkComment(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',
                'order_remark' => 'string',
            ]);

            $validated["comments"] = !empty($validated["comments"]) ? htmlspecialchars($validated["comments"]) : "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        # 判断是否存在
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }

        $res = new stdClass();
        $res->affect = 0;
        if(!empty($validated["order_remark"])){
            $res->affect = Orders::getInstance()->updateOrder($validated["order_id"],$validated);
        }
        return $this->renderJson($res);
    }

    //提交验货
    //-----权限:帮多个采购验货情况
    public function checkInspect(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',
                'is_exceptional' => 'integer|required', //0没异常，1有异常(包括图片异常)
                'inspect_list' => 'required|string',  // [{"order_info_id":"","num":"5"}]  销售订单详细ID，当次提交验货数量
            ]);
            $validated["inspect_list"] = json_decode($validated["inspect_list"],true);
            $validated["is_exceptional"] = 0;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $user_id = UserId::$user_id;
        # 判断是否存在
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        #判断只采购有权限
        $info = MissuUser::getInstance()->getUserInfo($user_id);
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }

        $res = new \stdClass();
        $modelList = [];

        #处理验货数据
        $model = $orderInfoId = [];
        foreach ($validated["inspect_list"] as $key=>$obj){
            #过滤无效数据
            if($obj["num"] ==0){
                unset($validated["addList"][$key]);
                continue;
            }
            # 获取产品详情
            $product = OrdersItemInfo::getInstance()->getByIdAS($obj["order_info_id"]);
            $check = CheckDetail::getInstance()->getCheckPi($obj["order_info_id"]);

            $inspect_quantity = $obj["num"] + $check->inspect_quantity; #总的验货数量：当次提交的验货数量+已验货的数
            $tmp = [
                "inspect_status" => 1,  #验货状态：0未验货，1部分验货，2已验货
                "inspect_quantity" => $inspect_quantity,
                "order_id"=>$validated["order_id"],
                "order_info_id"=>$obj["order_info_id"],
                "audit_status"=>0,  #每次提交验货，初始化审核状态, 驳回状态
                "is_audit"=>0,
                "is_reject" =>0,
                "audit_note" =>"",
            ];
            if($inspect_quantity >= $product->quantity){ #如果验货数量>=采购数量，则变更为已验货
                $tmp["inspect_status"] = 2;
                $tmp["inspect_quantity"]  = $product->quantity;
            }
            if(!$check->attach_exceptional){
                $tmp["audit_status"] = 1;
                $tmp["is_audit"] = 1;
            }else{
                $validated["is_exceptional"] = 1;
            }
            $model[] = $product->product_name_pi;
            $modelList[] = $tmp;
            $orderInfoId[] = $obj["order_info_id"];  //不同的采购只看到自己的型号
        }


        //-----权限
        $userId = $this->returnPurchaseIdPermission($info->user_group_id,$user_id,$validated["order_id"]);
        $res->affected = CheckDetail::getInstance()->updateAuditStatus($orderInfoId,$modelList,$userId);

        #发送消息
        if($validated["is_exceptional"]){
            \App\Services\MessageService::getInstance()->checkInspectMessage($user_id,[
                "Sales_User_ID"=>$data->Sales_User_ID,
                "PI_name"=>$data->PI_name,
                "order_id"=>$validated["order_id"],
            ]);
        }else{
            \App\Services\MessageService::getInstance()->checkInspectMessageTwo($user_id,[
                "Sales_User_ID"=>$data->Sales_User_ID,
                "PI_name"=>$data->PI_name,
                "order_id"=>$validated["order_id"],
                "model"=>$model,
            ]);
        }

        #写日志
        Oplog::getInstance()->addCheckLog($user_id, $validated["order_id"],"采购验货","",  ["f9"=>json_encode($modelList)]);

        /*if($validated["is_exceptional"] == 0){ #没有异常
            # 队列处理: 立即同步调度任务
            foreach ($modelList as $obj){
                \App\Jobs\ProductUpdate::dispatchSync($obj["purchaseorder_detailed_id"],null);
            }
        }*/
        return $this->renderJson($res);
    }

    //初始化审核:销售角色
    public function auditInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        # 判断是否存在
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $data->receivedNum = 0;  #实到总数
        $data->orderNum =0;     #订单总件数
        $ids = [];
        $data->item=[];

        #获取异常
        $item = OrdersItemInfo::getInstance()->getOrderItemsF1($validated["order_id"]);
        foreach ($item as $val){
            $data->orderNum += $val->quantity;
            $check = CheckDetail::getInstance()->getCheckPi($val->order_info_id);
            if($check){
                unset($check->note);
                $data->audit_note = $check->audit_note;
                $data->receivedNum += $check->serial_quantity;

                if($check->attach_exceptional){  //图片异常
                    $data->attachExceptional = 1;
                    #序列号
                    [$val->serialNumbers,] = SerialNumbers::getInstance()->serialNumberList(["order_info_id"=>$val->order_info_id]);

                    # 获取对应的sku，规格
                    $val->specs = IhuProductSpecs::getInstance()->getByProductId($val->product_id);
                    $val->check = $check;
                    $data->item[] = $val;

                    #获取异常型号的ID，用户获取异常图片
                    $ids[] = $val->order_info_id;
                }
            }
        }
        # 获取异常的图片,销售单详单
        $data->abnormalAttach = Attachments::getInstance()->getAttachByInfoId( $ids,0 );
        #获取正常的图片,销售单详单
        $data->attachments = Attachments::getInstance()->getAttachByInfoId( $ids,1 );

        return $this->renderJson($data);
    }

    //审核
    public function auditSubmit(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|gt:0',
                'operate' => 'required|string',   //提交submit, 保存save
                'audit_note' => 'string|max:256',
                'audit_satus' => [new Enum(AuditSatus::class), 'required', 'filled'],
                'rejectModel' => 'string',  // 驳回型号 [{"order_info_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
                'file_ids'=>'string', //异常附件id，多个逗号隔开， gd_attachments表的id
                'order_info_ids' => 'required|string',  //异常的详单ID，多个逗号隔开
            ]);
            $validated["audit_note"] = !empty($validated["audit_note"]) ? htmlspecialchars($validated["audit_note"]) : "";
            $validated["rejectModel"] = !empty($validated["rejectModel"]) ? json_decode($validated["rejectModel"],true): [];
            $validated["file_ids"] = !empty($validated["file_ids"]) ? explode(",", $validated["file_ids"]): [];
            $validated["order_info_ids"] = !empty($validated["order_info_ids"]) ? explode(",", $validated["order_info_ids"]): [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if($validated["audit_satus"] > AuditSatus::AGREE->value && empty($validated["audit_note"])){
            return $this->renderErrorJson(21, "备注为必填");
        }
        # 判断是否存在
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $userId = UserId::$user_id;
        $result = new \stdClass();

        #审核同意/整单驳回，没有驳回型号
        if(in_array($validated["audit_satus"], [AuditSatus::AGREE->value,AuditSatus::REJECTION->value])){
            $validated["rejectModel"] = [];
        }

        #处理驳回型号
        $result->affected = CheckDetail::getInstance()->auditSubmit($validated);

        $str="";
        if($validated["audit_satus"] == AuditSatus::AGREE->value) $str = "审核同意";
        else if($validated["audit_satus"] == AuditSatus::REJECTION->value) $str = "全部驳回";
        else if($validated["audit_satus"] == AuditSatus::WAITEING->value) $str = "部分驳回-等待货齐";
        else if($validated["audit_satus"] == AuditSatus::PARTSEND->value) $str = "部分驳回-部分先发货";

        #发送消息
        if($validated["operate"] == "submit" && $result->affected){
            \App\Services\MessageService::getInstance()->checkAudit($userId,[
                    "Sales_User_ID"=>$data->Sales_User_ID,
                    "PI_name"=>$data->PI_name,
                    "order_id"=>$validated["order_id"],
                ],$validated["file_ids"],$str);
        }

        #日志记录：f10记录驳回日志，f9记录验货日志
        if($validated["operate"] == "submit"){
            $f10 = !empty($validated["rejectModel"]) ? json_encode($validated["rejectModel"]): null;
            Oplog::getInstance()->addCheckLog($userId, $validated["order_id"],"审核类型：{$str}", $validated['audit_note'],["f10"=>$f10]);
        }

        # 队列处理: 立即同步调度任务
        \App\Jobs\ProductUpdate::dispatchSync(["order_id"=>$validated["order_id"]],null);
        return $this->renderJson($result);
    }


    //获取Pi-的快递列表
    public function expressList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;

        $data->list = ExpressOrders::getInstance()->getExpress(["order_id"=>$validated["order_id"]]);
        foreach ($data->list as $item){
            if($item->submit_status == 1){
                $param = [
                    'com'=>'auto', //com: 快递公司字母简称,可以从接口"快递公司查询" 中查到该信息 可以使用"auto"代替表示自动识别,不推荐大面积使用auto，建议尽量传入准确的公司编码。
                    'nu'=> $item->tracking_number, //快递单号
                    'receiver_phone'=> $item->receiver_phone??"", // 收/寄件人手机号后四位，顺丰快递必须填写本字段
                    'sender_phone'=>$item->sender_phone??"",
                ];
                $aliDeliver = \App\Services\AliDeliver::getInstance()->aliDeliverShowapi($param);
                $item->aliStatus = isset($aliDeliver["showapi_res_body"]) ? $aliDeliver["showapi_res_body"]["status"] : 0;  #0表示无记录
            }
        }
        return $this->renderJson($data);
    }


    //初始化快递信息
    public function initExpressOrderInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $data->sales = MissuUser::getInstance()->getUserInfo($data->Sales_User_ID);

        #获取对应的采购单
        $data->purchaseOrder = PurchaseOrder::getInstance()->getByOrderId($validated["order_id"]);
        foreach ($data->purchaseOrder as $val){
            $val->express = ExpressOrders::getInstance()->getExpress(["purchaseorder_id"=>$val->purchaseorder_id,"order_by"=>"abnormal_status"]);
            # 补齐(Sales_User_ID) （内部用户user表，外部用户missu_users表）中对应的用户id,name 放到$data->list["sales"]字段里
            MissuUser::getInstance()->fillUsers($val->express, 'purchaser_id', 'purchaser');
            $user = MissuUser::getInstance()->getUserInfo($val->create_user);
            $val->user = ["id"=>$user->id,"name"=>$user->name];
        }
        return $this->renderJson($data);
    }

    //初始化快递产品信息
    public function initExpressProduct(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|gt:0',
                "express_id" => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = Orders::getInstance()->getByIdAS($validated["order_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $expressProduct = ExpressOrders::getInstance()->getProductByExpressOrder(["express_id"=>$validated["express_id"],"order_id"=>$validated["order_id"]]);

        $data->expressProduct = $tmpProduct = [];
        foreach ($expressProduct as $obj){
            if(!isset($tmpProduct[$obj->product_id])){
                $obj->purchase_note = !empty($obj->purchase_note)?json_decode($obj->purchase_note):[];
                foreach ($obj->purchase_note as $note){
                    $info = MissuUser::getInstance()->getUserInfo($note->purchase_id);
                    $note->purchase_name = $info->name;
                }
                $obj->quantity = ExpressOrders::getInstance()->getSumQuantity($obj->product_id);

                if($obj->abnormal_status ==0){ #无异常时，实到数量=订货数量
                    $obj->actual_quantity = $obj->quantity;
                }
                if(empty($obj->actual_model)){ #无异常时，实到型号=订货型号
                    $obj->actual_model = $obj->model;
                }

                $tmpProduct[$obj->product_id] = $obj;
                $data->expressProduct[] = $obj;
            }
        }
        return $this->renderJson($data);
    }

    //异常快递，提交采购：发送消息
    public function submitAbnormal(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'integer|required',
                'express_id' => 'string|required',  //单个
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 10;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $result = new stdClass();

        #给快递单号关联的采购发送消息
        $data = ExpressDelivery::getInstance()->getAbnormalExpress([$validated["express_id"]]);
        $purchase = [];
        foreach ($data as $obj){
            $obj->purchaser_id = !empty($obj->purchaser_id)? explode(",", $obj->purchaser_id) : [];
            foreach ($obj->purchaser_id as $v){
                if(isset($purchase[$v])) $purchase[$v][] = $obj->tracking_number;
                else {
                    $purchase[$v] = [$obj->tracking_number];
                }
            }
        }
        foreach ($purchase as $key=>$val){
            $val = array_unique($val);
            \App\Services\MessageService::getInstance()->submitAbnormalExpress(UserId::$user_id,[
                "recipient"=>$key,
                "express_number"=>implode(",", $val),
                "order_id" =>$validated["order_id"],
                "express_id"=>$validated["express_id"],
            ]);
        }

        #给快递里的产品判断， 没有异常的，没有提交序列号的取出来，
        $expressProduct = \App\Services\Products::getInstance()->getProductNotConfirm($validated["express_id"]);
        if(empty($expressProduct)){
            #更改按快递提交
            $result->affect = ExpressDelivery::getInstance()->updateExpressDelivery($validated["express_id"], ["submit_purcharse" =>1]);
            return $this->renderJson($result);
        }
        foreach ($expressProduct as $product){
            if($product->abnormal_status ==0){  #没有异常的
                $product->user_id = UserId::$user_id;
                [$result->orderItem,$result->affect] = \App\Services\ExpressAbnormalService::getInstance()->productNumToPI($product);
            }
        }
        return $this->renderJson($result);
    }


    //扫快递
    public function scanExpress(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'tracking_number' => 'required|min:1|max:512',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        //获取快递信息
        $data = ExpressDelivery::getInstance()->getByTrackingNumber($validated['tracking_number']);
        if (!$data) {
            return $this->renderJson($data);
        }
        $ids = [];
        foreach ($data as $obj){
            $ids[] = $obj->id;
        }
        $data = ExpressOrders::getInstance()->getByExpressId(array_unique($ids));
        return $this->renderJson($data);
    }


    # 采购单缓存
    private function returnPurchaseorder(int $order_id,object $val,int $refresh_cache=0):array
    {
        $user_id = UserId::$user_id; #登录用户ID
        $info = MissuUser::getInstance()->getUserInfo($user_id);
        //-----权限？
        //$userId = ($info->user_group_id==3) ? \App\Http\Middleware\UserId::$user_id : null;
        $userId = $this->returnPurchaseIdPermission($info->user_group_id, $user_id, $order_id);

        if($refresh_cache){
            $purchaseorder = [];
        }else{
            $purchaseorder = Redis::command("get", ["gd_checklist_{$val->order_info_id}_{$val->product_id}_{$user_id}"]);
            $purchaseorder = json_decode($purchaseorder);
        }

        if(empty($purchaseorder)){
            #采购单号,根据当前登录用户
            $purchaseorder = PurchaseOrderDetailed::getInstance()->getPurchaseOrderByOrderId($val->order_info_id,$val->product_id,$userId);
            # 补齐create_user对应的信息，放入$data->list['purchaser']字段里
            MissuUser::getInstance()->fillUsers($purchaseorder, 'create_user', 'purchaser');


            foreach ($purchaseorder as $po){
                #加上权限分享标识,0未分享，1分享给我的,2我分享的
                $po->shareFlag = 0;
                if($info->user_group_id==3){
                    if($po->Purchaser_id != $user_id){$po->shareFlag=1;}
                    else{
                        $r = \App\Services\PermissionShare::getInstance()->mySharePod($user_id,$po->Purchaseorder_detailed_id);
                        $po->shareFlag = ($r==1)? 2:0;
                    }
                }

                #查快递
                $po->express =ExpressOrders::getInstance()->getByPoPi($val->order_id,["purchaseorder_detailed_id"=>$po->Purchaseorder_detailed_id,"user_id"=>$userId]);
                foreach ($po->express as $ex){
                    $company = ExpressCompany::getInstance()->getChannelByName($ex->channel_id);
                    $ex->channel_name = $company->expName??"";
                    $ex->sumQuantity = ExpressOrders::getInstance()->getSumSumbmitQuantity([
                        "purchaseorder_detailed_id"=>$po->Purchaseorder_detailed_id,
                        "express_id"=>$ex->express_id,
                        "order_item_id"=>$val->order_info_id,
                    ]);
                }
            }

            Redis::command("set", ["gd_checklist_{$val->order_info_id}_{$val->product_id}_{$user_id}", json_encode($purchaseorder), ['EX' => 3600]]);
        }

        # 获取对应的sku，规格
        $specs = IhuProductSpecs::getInstance()->getByProductId($val->product_id);
        return [$specs,$purchaseorder];
    }


    //PI_name模糊搜索：用于权限分享时初始化数据，只能分享自己的单，不能是别人分享给自己的再次分享给他人
    public function piNameSearch(Request $request): \Illuminate\Http\JsonResponse
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
        $validated["keyword_type"] = "pi";
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        $validated["purchaser_id"] = [$userID];

        //获取销售订单信息
        $data = new stdClass();
        [$data->list,$data->total] =  orders::getInstance()->getCheckSaleList($validated);
        return $this->renderJson($data);
    }


    //返回PI的明细权限，根据是否采购人员,得到权限分享数据？
    private function returnPurchaseIdPermission(int $userGroupId,int $userId, int $orderId):array|null
    {
        $userIds = null;
        if($userGroupId==3){ #采购
            $userIds = [$userId];
            $purchaseIds = \App\Services\PermissionShare::getInstance()->piPurchareseId($userId,$orderId);
            if(count($purchaseIds)){
                $userIds = array_unique(array_merge($userIds,$purchaseIds));
            }
        }
        return $userIds;
    }


}
