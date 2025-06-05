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
use App\Ok\Enum\CheckConfirmed;
use App\Ok\Enum\PoState;
use App\Ok\Enum\PoConfirmStatus;
use App\Ok\Enum\PoSubmitStatus;
use App\Ok\Enum\PoInspectStatus;
use App\Ok\Enum\CheckAuditStatus;
use App\Ok\Enum\AuditSatus;
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
use \App\Services\M\ShipTaskItem;

use App\Services\M\User as MissuUser;

//核对采购单
class CheckPoController extends Controller
{

    //获取待处理数量包含:未确认、部分确认、未提交、未验货、部分验货、待审核
    public function unConfirmed(Request $request): \Illuminate\Http\JsonResponse
    {
        //$this->aa();

        $userId = \App\Http\Middleware\UserId::$user_id;
        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin($userId);

        # 待处理总数
        $data = new \stdClass();

        $unConfirmed = \Illuminate\Support\Facades\Redis::command("get", ["gd_checklist_unconfirmed_".$userId]);
        $data->unConfirmed = json_decode($unConfirmed);
        if(empty($data->unConfirmed)){
            $data->unConfirmed = PurchaseOrder::getInstance()->getUnConfirmedCount($userInfo?:null,["uncheck"=>1]);
            \Illuminate\Support\Facades\Redis::command("set", ["gd_checklist_unconfirmed_".$userId, $data->unConfirmed, ['EX' => 3600]]);
        }
        return $this->renderJson($data);
    }

    //核对列表
    public function checkList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'sales_id' => 'integer|gt:0',
                'purchaser_id' => 'integer|gt:0',  //采购id
                'state' => [new Enum(PoState::class),],
                'confirm_status' => [new Enum(PoConfirmStatus::class),],
                'submit_status' => [new Enum(PoSubmitStatus::class),],
                'inspect_status' => [new Enum(PoInspectStatus::class),],
                //'audit_status' => [new Enum(PoAuditStatus::class),],
                'is_audit' => [new Enum(CheckAuditStatus::class),],
                'start_time' => 'string',
                'end_time' => 'string',
                'keyword' => 'string',  //['Purchaseordername', 'pi_names'];
                'keyword_type' => 'string', //默认显示型号，可切换型号、PI、PO
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
            $validated["keyword_type"] = !empty($validated["keyword_type"])?$validated["keyword_type"]:"model";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new \stdClass();
        $userID = \App\Http\Middleware\UserId::$user_id;

        /*if(!empty($validated["audit_status"])){
            if($validated["audit_status"]==4){
                $validated["audit_status"]=[4,5];
            }else{
                $validated["audit_status"]=[$validated["audit_status"]]; //转换为数组
            }
        }*/

        $sales_id = !empty($validated["sales_id"])? $validated["sales_id"] : 0;
        $validated["sales_id"] = [];
        if($sales_id){
            $validated["sales_id"][] = $sales_id;
        }

        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin(\App\Http\Middleware\UserId::$user_id);
        if($userInfo){
            $info = MissuUser::getInstance()->getUserInfo($userID);
            if($info->user_group_id ==1){  #判断是不是销售
                $validated["sales_id"][]= $userID;
            }
            else if($info->user_group_id ==103){
                $validated["sales_id"] = array_merge($validated["sales_id"],$userInfo);
            }
            else{
                $validated["user_id"] = $userInfo;
            }
        }

       // if(!empty($validated["keyword"]) && ($validated["keyword_type"] == "model") ){
            //$aa = $this->gdSearch($validated["keyword"]);

        //}


        # 查询采购订单表数据
        [$data->list,$data->total] = PurchaseOrder::getInstance()->checkList($validated);
        # 补齐create_user对应的信息，放入$data->list['purchaser']字段里
        MissuUser::getInstance()->fillUsers($data->list, 'create_user', 'purchaser');
        foreach ($data->list as $po) {
            $po->pis = [];
            $po->sales = [];  #销售
            $po->modelNum = 0;  #型号总数
            $po->podNum =0;     #采购总件数
            $po->missing_quantity = 0; #缺失数量，0不缺，1缺（只有在匹配型号时才计算缺失）
            //$po->actualNum = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_id"=>$po->purchaseorder_id]); #统计实到件数
            $po->actualNum = $po->arrival_qty;

            # 查询采购订单对应的销售
            $po->pis = Redis::command("get", ["gd_checklist_pis_".$po->purchaseorder_id]);
            $po->sales = Redis::command("get", ["gd_checklist_sales_".$po->purchaseorder_id]);
            $po->pis = json_decode($po->pis);  $po->sales = json_decode($po->sales);
            if(empty($po->pis) || empty($po->sales)){
                $orders = Orders::getInstance()->getByIdsF1(explode(",", $po->order_id));
                # 补齐create_user对应的信息，放入$data->list['purchaser']字段里
                MissuUser::getInstance()->fillUsers($orders, 'Sales_User_ID', 'sales');

                $sales = [];
                foreach ($orders as $v){
                    $po->pis[] = $v->PI_name;
                    if(!isset($sales[$v->sales->id])){
                        $po->sales[] = $v->sales;
                        $sales[$v->sales->id] = $v->sales;
                    }
                }
                Redis::command("set", ["gd_checklist_pis_".$po->purchaseorder_id, json_encode($po->pis), ['EX' => 3600]]);
                Redis::command("set", ["gd_checklist_sales_".$po->purchaseorder_id, json_encode($po->sales), ['EX' => 3600]]);
            }

            $attach_exceptional = $is_audit = $confirm_status = $Inspect_status = [];
            # 查询采购订单详单,一个采购订单有多个详单
            //$items = PurchaseOrderDetailed::getInstance()->getByPoIdF1($po->purchaseorder_id);

            $items = PurchaseOrderDetailed::getInstance()->getByUserPodId($po->purchaseorder_id,$validated);
            foreach ($items as $val){
                $po->modelNum++;
                $po->podNum += $val->Qtynumber;

                if(!empty($validated["keyword"]) && $validated["keyword_type"] == "model"){
                    $tmpModel = str_replace(array(' ', '-','/'), '', $val->Model);   #去掉字符串中空格，横杠，存在查询字符串；
                    //$condition = strpos($val->Model, $validated["keyword"]) !== false  ||  strpos($tmpModel, $validated["keyword"]) !== false;
                    $condition = str_contains($val->Model, $validated["keyword"]) || str_contains($tmpModel, $validated["keyword"]);
                    if ( ($condition) && $val->serial_quantity < $val->Qtynumber) {
                        $po->missing_quantity =1;  //是否缺货：1缺货
                    }
                }

                #得到验货状态、确认状态、审核
                $confirm_status[] = $val->confirm_status;
                $Inspect_status[] = $val->inspect_status;
                $is_audit[] = $val->is_audit;
                $attach_exceptional[] = $val->attach_exceptional;
            }

            #得到总的验货状态、确认状态
            $is_audit = array_unique($is_audit);
            $confirm_status = array_unique($confirm_status);
            $Inspect_status = array_unique($Inspect_status);
            $attach_exceptional = array_unique($attach_exceptional);
            if(count($confirm_status) == 1 && $confirm_status[0]==0){
                $po->confirm_status = 0;
            }
            else if(count($confirm_status) == 1 && $confirm_status[0]==2){
                $po->confirm_status = 2;
            }else{
                $po->confirm_status = 1;
            }
            $Inspect_status = array_unique($Inspect_status);
            if(count($Inspect_status) == 1 && $Inspect_status[0]==0){
                $po->Inspect_status = 0;
            }
            else if(count($Inspect_status) == 1 && $Inspect_status[0]==2){
                $po->Inspect_status = 2;
            }else{
                $po->Inspect_status = 1;
            }
            $po->is_audit = (count($is_audit) == 1 && $is_audit[0]==1)? 1: 0;
            $po->attach_exceptional = (count($attach_exceptional) == 1 && $attach_exceptional[0]==0)? 0: 1;
        }

        return $this->renderJson($data);
    }

    //核对详情
    public function checkInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        //$data = PurchaseOrder::getInstance()->getByIdF3($validated["purchaseorder_id"]);
        $data = $this->returnPurchaseorder($validated["purchaseorder_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $userID = \App\Http\Middleware\UserId::$user_id;

        $data->attachExceptional = 0; //附件异常:0没异常，1有异常
        $data->numExceptional = 0; //数量异常：0没异常，1有异常

        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin($userID);
        if($userInfo){
            $info = MissuUser::getInstance()->getUserInfo($userID);
            if($info->user_group_id ==1){  #判断是不是销售
                $validated["sales_id"]= [$userID];
            }
            if($info->user_group_id ==103){
                $validated["sales_id"] = $userInfo;
            }
        }
        //var_dump($validated);die;

        $data->item = PurchaseOrderDetailed::getInstance()->getByUserPodId($validated["purchaseorder_id"],$validated);
        foreach ($data->item as $val){

            # 获取对应的sku，规格
            $val->specs = IhuProductSpecs::getInstance()->getByProductId($val->products_id);

            # 查询销售订单相关信息
            $val->order = OrdersItemInfo::getInstance()->getByOrderId($val->order_id,$val->products_id);
            $val->shiptask = [];
            if($val->order){
                $val->order->sales = MissuUser::getInstance()->getUserInfo($val->order->Sales_User_ID);
                $val->shiptask = ShipTaskItem::getInstance() ->getShipTaskInfoById($val->order->order_info_id); # PI相关发货任务
            }

            #产品序列号总的数量
            $val->serialQuantity = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_detailed_id"=>$val->Purchaseorder_detailed_id]);

            #产品新提交的数量，待验货数量，待审核数量
            [$val->newQuantity, $val->toBeinspectQuantity,$val->auditQuantity] = SerialNumbers::getInstance()->getAllQuantity($val->Purchaseorder_detailed_id);

            # 产品关联图片或视频？？
            $val->attachments = Attachments::getInstance()->getAttachments($val->Purchaseorder_detailed_id,3);

            //图片异常，数量异常，待审核数量=已验货数量
            $flag = Attachments::getInstance()->getExceptional($val->Purchaseorder_detailed_id);
            $val->attachExceptional=0;
            if($flag){
                $data->attachExceptional = 1;
                $val->attachExceptional = 1;
            }
            /*if($data->submit_status==1 && ($val->Qtynumber - $val->inspect_quantity>0)){  #已提交，且采购数量-已验货的数量>0
                $data->numExceptional = 1;
            }*/
        }

        return $this->renderJson($data);
    }

    //核对附件
    public function checkAttach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        # 产品关联图片或视频
        $data = new \stdClass();
        $data->attachments = Attachments::getInstance()->getAttachments($validated["purchaseorder_id"],6);
        $ids = PurchaseOrderDetailed::getInstance()->returnPodIds($validated["purchaseorder_id"]);
        $podAttach = Attachments::getInstance()->getAttachmentsByPodId($ids);

        $data->podAttach = [];
        foreach ($podAttach as $k =>$obj){
            if(empty($data->podAttach[$obj->correlate_id])) $data->podAttach[$obj->correlate_id]=[];
            $data->podAttach[$obj->correlate_id][] = $obj;
        }
        unset($podAttach);

        return $this->renderJson($data);
    }

    //产品详情
    public function productInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_detailed_id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = new stdClass();
        # 获取产品详情
        $data->product = PurchaseOrderDetailed::getInstance()->getInfo($validated["purchaseorder_detailed_id"]);

        //$data->purchaseorder = PurchaseOrder::getInstance()->getByIdF3($data->product->Purchaseorder_id);
        $data->purchaseorder = $this->returnPurchaseorder($data->product->Purchaseorder_id);

        # 获取对应的sku，规格
        $data->specs = IhuProductSpecs::getInstance()->getByProductId($data->product->products_id);

        #序列号
        [$data->serialNumbers,] = SerialNumbers::getInstance()->serialNumberList($validated);
        return $this->renderJson($data);
    }

    //产品型号模糊搜索
    public function modelSerach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'integer|required',
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
        $data->list = PurchaseOrderDetailed::getInstance()->getProducts($validated["purchaseorder_id"],$validated["model"]);
        return $this->renderJson($data);
    }

    //保存核对产品，
    public function saveModel(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_detailed_id' => 'integer|required',
                'note' => 'string|max:256',
                'shelf_position'=>'string|max:64',
                'is_confirmed' => [new Enum(CheckConfirmed::class), 'filled'],  //0未确认，1部分确认，2已确认
                'serial_type' => [new Enum(SerialType::class),'required', 'filled'],  //序列号类型
                'serial_list' => 'string',  // [{"id":"","serial_number":"H7EC-N","quantity":"5"}]  ，有id时传id
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
        $product = PurchaseOrderDetailed::getInstance()->getInfo($validated["purchaseorder_detailed_id"]);
        //$purchaseOrder = PurchaseOrder::getInstance()->getByIdF3($product->Purchaseorder_id);
        $purchaseOrder = $this->returnPurchaseorder($product->Purchaseorder_id);
        $poTd = PurchaseOrderTaskDetailed::getInstance()->getByIdAS($product->Purchaseordertesk_detailed_id);

        # 统计数量和组装数据
        $tmpQuantity=0;
        $repeat=[];
        foreach ($serial_list as $obj){
            $tmpQuantity += $obj->quantity;
            $repeat[$obj->serial_number] = ($repeat[$obj->serial_number] ?? 0) + 1;
        }
        # 判断序列号数量大于缺货数
        if($tmpQuantity > $product->Qtynumber){
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
        $data = new stdClass();

        #组装参数
        $param = [];  $updatParam=[];
        foreach ($serial_list as $obj) {
            $obj->quantity = ($validated["serial_type"] == 1) ? 1 : $obj->quantity;  #单一序列号数量固定为1
            $obj->serial_number = ($validated["serial_type"] == 3) ? "" : $obj->serial_number;

            $tmp = [
                "id"=>SerialNumbers::getInstance()->get_unique_id(),
                "user_id" => $validated['user_id'],
                "order_id" => $product->order_id,
                "order_info_id" => $poTd->order_info_id,
                "purchaseorder_id" => $product->Purchaseorder_id,
                "purchaseorder_detailed_id" => $validated['purchaseorder_detailed_id'],
                "serial_number" => $obj->serial_number,
                "quantity" => $obj->quantity,
                "new_quantity"=>0,
                "status" => 1,
                "type" => $validated["serial_type"],
            ];

            # submit_status '提交状态：0未提交，1已提交'
            if(!$purchaseOrder->submit_status) {
                $param[] = $tmp;
            }
            #如果状态为已提交，则之前添加的序列号不能删除，只能修改和增加
            else{
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
                    $tmp["new_quantity"] = $obj->quantity;
                    $param[] = $tmp;
                }
            }
        }

        #批量保存，或者更新
        if(!$purchaseOrder->submit_status) {
            $data->affected = SerialNumbers::getInstance()->saveAllSerialNumber($param,$validated['purchaseorder_detailed_id']);
        }else{
            $data->affected = SerialNumbers::getInstance()->updateSerialNumber($param,$updatParam);
        }
        if($data->affected){
            $actualNum = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_id"=>$product->Purchaseorder_id]); #统计实到件数
            PurchaseOrder::getInstance()->updatePurchaseOrder($product->Purchaseorder_id,["arrival_qty"=>$actualNum,"Update_tiem"=>date("Y-m-d H:i:s")]);

            $param = ["Comments"=>$validated['note'], "shelf_position"=>$validated['shelf_position'],"confirm_status"=>$validated['is_confirmed']];
            PurchaseOrderDetailed::getInstance()->updateModel($validated['purchaseorder_detailed_id'],$param);
            $quantity = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_detailed_id"=>$validated['purchaseorder_detailed_id']]);

            Oplog::getInstance()->addCheckLog($validated['user_id'], $product->Purchaseorder_id, "修改核对产品",
                "{$product->Model}×{$quantity}");
            OplogApi::getInstance()->addLog(UserId::$user_id, '修改核对产品',sprintf("%s, %s", +$data->affected, json_encode($validated)));
        }

        # 队列处理: 立即同步调度任务
        //\App\Jobs\ProductUpdate::dispatchSync($validated['purchaseorder_detailed_id'],null);

        return $this->renderJson($data);
    }

    //确认提交
    public function checkSubmit(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $user_id = UserId::$user_id;
        # 判断是否存在
        //$data = PurchaseOrder::getInstance()->getByIdF3($validated["purchaseorder_id"]);
        $data = $this->returnPurchaseorder($validated["purchaseorder_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }

        $res = new stdClass();
        PurchaseOrder::getInstance()->updatePurchaseOrder($validated['purchaseorder_id'],["submit_status"=>1,"Update_tiem"=>date("Y-m-d H:i:s")]); //提交状态：0未提交，1已提交(不能再修改数据)
        PurchaseOrderDetailed::getInstance()->updateWareArrivalTime($validated['purchaseorder_id']);
        $res->affected = SerialNumbers::getInstance()->setInspectQuantity($validated["purchaseorder_id"],$data->submit_status);

        # 写日志
        Oplog::getInstance()->addCheckLog($user_id, $validated['purchaseorder_id'],"确认提交", "");
        OplogApi::getInstance()->addLog($user_id, '确认提交',sprintf("%s", $validated['purchaseorder_id']));

        #发送消息提醒
        $tmp = [
            "user_id" => $user_id,
            "title" => "您有一个采购订单正在等待验货，请及时处理！",
            "content" =>  $data->Purchaseordername."采购订单提交了新的货物正在等待采购验货",
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$validated["purchaseorder_id"]."&poName=".$data->Purchaseordername,
            "recipient" => "",
        ];
        #该采购订单关联的采购
        $purchaserId = [];
        $item = PurchaseOrderDetailed::getInstance()->getByPoIdF1($validated["purchaseorder_id"]);
        foreach ($item as $val){
            $purchaserId[] = $val->Purchaser_id;
        }
        Message::getInstance()->addMessage($tmp,array_unique($purchaserId));

        # 队列处理: 立即同步调度任务；更新缓存
        foreach ($item as $obj){
            \App\Jobs\ProductUpdate::dispatchSync($obj->Purchaseorder_detailed_id,null);
        }

        return $this->renderJson($res);
    }

    //提交验货
    public function checkInspection(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'integer|required',
                'is_exceptional' => 'integer|required', //0没异常，1有异常(包括图片、数量异常)
                'inspect_list' => 'required|string',  // [{"pod_id":"","num":"5"}]  采购订单详细ID，当次提交验货数量
            ]);
            $validated["inspect_list"] = json_decode($validated["inspect_list"],true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        # 判断是否存在
        //$data = PurchaseOrder::getInstance()->getByIdF3($validated["purchaseorder_id"]);
        $data = $this->returnPurchaseorder($validated["purchaseorder_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $userId = UserId::$user_id;
        $res = new \stdClass();
        $modelList = [];

        #处理验货数据
        foreach ($validated["inspect_list"] as $key=>$obj){
            #过滤无效数据
            if($obj["num"] ==0){
                unset($validated["addList"][$key]);
                continue;
            }
            # 获取产品详情
            $product = PurchaseOrderDetailed::getInstance()->getInfo($obj["pod_id"]);
            $inspect_quantity = $obj["num"] + $product->inspect_quantity; #总的验货数量：当次提交的验货数量+已验货的数
            $tmp = [
                "inspect_status" => 1,  #验货状态：0未验货，1部分验货，2已验货
                "inspect_quantity" => $inspect_quantity,
                "purchaseorder_detailed_id"=>$obj["pod_id"],
                "audit_status"=>0,
                "is_audit"=>0,
            ];
            if($inspect_quantity >= $product->Qtynumber){ #如果验货数量>=采购数量，则变更为已验货
                $tmp["inspect_status"] = 2;
                $tmp["inspect_quantity"]  = $product->Qtynumber;
            }
            $flag = Attachments::getInstance()->getExceptional($obj["pod_id"]);  #无图片异常则无需审核
            if(!$flag){
                $tmp["audit_status"]  = 1;
                $tmp["is_audit"]  = 1;
            }
            $modelList[] = $tmp;
        }
        $res->affected = PurchaseOrder::getInstance()->updateAuditStatus($validated["is_exceptional"],$validated["purchaseorder_id"],$modelList);


        #发送消息
        if($validated["is_exceptional"]){
            #采购验货完成，如有异常情况，给相关销售发销售
            $recipient = []; $orderIds = [];
            $items = PurchaseOrderDetailed::getInstance()->getByPoIdF1($validated["purchaseorder_id"]);
            foreach ($items as $v){
                $flag = Attachments::getInstance()->getExceptional($v->Purchaseorder_detailed_id);
                if($flag){
                    $orderIds[] = $v->order_id;
                }
            }
            $orders = Orders::getInstance()->getByIdsF1($orderIds);
            foreach ($orders as $v){
                $recipient[] = $v->Sales_User_ID;
            }
            $tmp = [
                "user_id" => $userId,
                "source" => "APP内推送",
                "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$validated["purchaseorder_id"]."&poName=".$data->Purchaseordername,
                "title" => "您的采购订单有异常情况，请及时审核！",
                "content" => $data->Purchaseordername."采购订单有图片异常/数量异常",
            ];
            Message::getInstance()->addMessage($tmp,$recipient);
        }

        #写日志
        Oplog::getInstance()->addCheckLog($userId, $validated["purchaseorder_id"],"采购验货", "",["f9"=>json_encode($modelList)]);
        OplogApi::getInstance()->addLog($userId, '采购验货',sprintf("%s, %s", $res->affected, json_encode($validated)));

        /*if($validated["is_exceptional"] == 0){ #没有异常
            # 队列处理: 立即同步调度任务
            foreach ($modelList as $obj){
                \App\Jobs\ProductUpdate::dispatchSync($obj["purchaseorder_detailed_id"],null);
            }
        }*/
        return $this->renderJson($data);
    }

    //初始化审核
    public function auditInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = PurchaseOrder::getInstance()->getByIdF3($validated["purchaseorder_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $userID = \App\Http\Middleware\UserId::$user_id;

        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin($userID);
        if($userInfo){
            $info = MissuUser::getInstance()->getUserInfo($userID);
            if($info->user_group_id ==1){  #判断是不是销售
                $validated["sales_id"]= [$userID];
            }
            if($info->user_group_id ==103){
                $validated["sales_id"] = $userInfo;
            }
        }

        $item = PurchaseOrderDetailed::getInstance()->getByUserPodId($validated["purchaseorder_id"],$validated);
        $data->receivedNum = 0;  #实到总数
        $data->podNum =0;     #采购总件数
        $data->item=[];
        $podIds=[];

        $data->attachExceptional = 0; //附件异常:0没异常，1有异常
        $data->numExceptional = 0; //数量异常：0没异常，1有异常

        foreach ($item as $val){
            #产品序列号总的数量
            $val->serialQuantity = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_detailed_id"=>$val->Purchaseorder_detailed_id]);
            #序列号
            [$val->serialNumbers,] = SerialNumbers::getInstance()->serialNumberList(["purchaseorder_detailed_id"=>$val->Purchaseorder_detailed_id]);

            $data->podNum += $val->Qtynumber;
            $data->receivedNum += $val->serialQuantity;

            # 获取对应的sku，规格
            $val->specs = IhuProductSpecs::getInstance()->getByProductId($val->products_id);
            #图片异常，数量异常，待审核数量=已验货数量
            $flag = Attachments::getInstance()->getExceptional($val->Purchaseorder_detailed_id);

            $condition = $data->submit_status ==1 && ($val->Qtynumber != $val->serialQuantity); //数量异常
            if($flag){  //图片异常
                $data->attachExceptional = 1;
                //if($condition) $data->numExceptional = 1;
                $data->item[] = $val;
                $podIds[] = $val->Purchaseorder_detailed_id;
            }
        }
        $podIds = array_unique($podIds);

        #获取异常的图片,采购单详单
        $data->abnormalAttach = Attachments::getInstance()->getAttachmentsByPodId( $podIds,0 );
        #获取正常的图片,采购单详单
        $data->attachments = Attachments::getInstance()->getAttachmentsByPodId( $podIds,1 );

        return $this->renderJson($data);
    }

    //审核
    public function auditSubmit(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'required|integer|gt:0',
                'operate' => 'required|string',   //提交submit, 保存save
                'note' => 'string|max:256',
                'audit_satus' => [new Enum(AuditSatus::class), 'required', 'filled'],
                'rejectModel' => 'string',  // 驳回型号 [{"pod_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
                'file_ids'=>'string', //异常附件id，多个逗号隔开
                'pod_ids' => 'required|string',  //异常的采购详单ID，多个逗号隔开
            ]);
            $validated["note"] = !empty($validated["note"]) ? htmlspecialchars($validated["note"]) : "";
            $validated["rejectModel"] = !empty($validated["rejectModel"]) ? json_decode($validated["rejectModel"],true): [];
            $validated["file_ids"] = !empty($validated["file_ids"]) ? explode(",", $validated["file_ids"]): [];
            $validated["pod_ids"] = !empty($validated["pod_ids"]) ? explode(",", $validated["pod_ids"]): [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if($validated["audit_satus"] > AuditSatus::AGREE->value && empty($validated["note"])){
            return $this->renderErrorJson(21, "备注为必填");
        }
        //$data = PurchaseOrder::getInstance()->getByIdF3($validated["purchaseorder_id"]);
        $data = $this->returnPurchaseorder($validated["purchaseorder_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $podItem = PurchaseOrderDetailed::getInstance()->getByPoIdF1($validated["purchaseorder_id"]);

        #审核同意/整单驳回，没有驳回型号
        if(in_array($validated["audit_satus"], [AuditSatus::AGREE->value,AuditSatus::REJECTION->value])){
            $validated["rejectModel"] = [];
        }
        $result = new \stdClass();
        $userId = UserId::$user_id;

        #处理驳回型号
        $result->affected = PurchaseOrder::getInstance()->auditSubmit($validated);

        $str="";
        if($validated["audit_satus"] == AuditSatus::AGREE->value) $str = "审核同意";
        else if($validated["audit_satus"] == AuditSatus::REJECTION->value) $str = "全部驳回";
        else if($validated["audit_satus"] == AuditSatus::WAITEING->value) $str = "部分驳回-等待货齐";
        else if($validated["audit_satus"] == AuditSatus::PARTSEND->value) $str = "部分驳回-部分先发货";

        #发送消息
        if($validated["operate"] == "submit" && $result->affected){
            $recipient = [];
            #与采购订单相关的采购、同一个团队的跟单员
            $user = MissuUser::getInstance()->getUserByGroupId(98);  #98跟单员
            foreach ($user as $val){
                $recipient[] = $val->id;
            }
            foreach ($podItem as $val){
                $recipient[] = $val->Purchaser_id;
            }
            $tmp = [
                "user_id" => $userId,
                "source" => "APP内推送",
                "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$validated["purchaseorder_id"]."&poName=".$data->Purchaseordername,
                "title" => "您有销售已审核的采购订单，点击查看",
                "content" => $data->Purchaseordername."订单销售已".$str,
            ];
            Message::getInstance()->addMessage($tmp,$recipient);
        }

        #日志记录：f10记录驳回日志，f9记录验货日志
        if($validated["operate"] == "submit"){
            $f10 = !empty($validated["rejectModel"]) ? json_encode($validated["rejectModel"]): null;
            Oplog::getInstance()->addCheckLog($userId, $validated["purchaseorder_id"],"审核类型：{$str}", $validated['note'],["f10"=>$f10]);
            //OplogApi::getInstance()->addLog($userId, "销售审核类型：{$str}", json_encode($validated));
        }

        # 队列处理: 立即同步调度任务
        \App\Jobs\ProductUpdate::dispatchSync(["purchaseorder_detailed_id"=>$podItem[0]->Purchaseorder_detailed_id],null);

        return $this->renderJson($result);
    }


    //获取PO-的快递列表
    public function expressList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'required|integer|gt:0',  //采购
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;

        $data->list = ExpressOrders::getInstance()->getExpress(["purchaseorder_id"=>$validated["purchaseorder_id"]]);
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
        $data = new stdClass();

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
    private function returnPurchaseorder($purchaseorder_id)
    {
        $purchaseorder = Redis::command("get", ["gd_purchaseorder_".$purchaseorder_id]);
        $purchaseorder = json_decode($purchaseorder);
        if(empty($purchaseorder)){
            $purchaseorder = PurchaseOrder::getInstance()->getByIdF3($purchaseorder_id);
            Redis::command("set", ["gd_purchaseorder_".$purchaseorder_id, json_encode($purchaseorder), ['EX' => 3600 * 24]]);
        }
        return $purchaseorder;
    }


}
