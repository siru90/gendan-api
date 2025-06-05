<?php

namespace App\Http\Controllers;

use App\Ok\Enum\PiState;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\M\CustomerInfo;
use \App\Services\ShiptaskSubmit;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\ShipTaskItem;
use \App\Services\M\ShipTask;
use \App\Services\ExpressOrders;
use \App\Http\Middleware\UserId;
use \App\Services\Oplog;
use \App\Services\OplogApi;
use \App\Services\SerialNumbers;
use \App\Services\Message;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class PiController extends Controller
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

    //PI列表：销售列表
    public function piList(Request $request): \Illuminate\Http\JsonResponse
    {
        $limitSize = 100; //默认限制100条
        $data = new \stdClass();
        $data->list = [];
        $data->total = 0;

        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'sales_id' => 'integer|gt:0',
                'purchaser_id' => 'integer|gt:0',  //采购id
                'country_id' => 'integer|gt:0',
                'state' => [new Enum(PiState::class),],
                'start_time' => 'string',
                'end_time' => 'string',
                //'submit_status' => [new Enum(SubmitStatus::class),],
                'keyword' => 'string',   // ['pi_name', 'product_name_pi'];
                'keyword_type' => 'string', //默认显示PI，可切换PI、型号
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        # 客户的国家信息
        if(!empty($validated['country_id'])){
            $Customer = CustomerInfo::getInstance()->getCustomerInfoByCountryId($validated['country_id'],$limitSize);
            foreach ($Customer as $obj){
                $validated['Customer_Seller_info_id'][] = $obj->customer_info_id;
            }
        }

        #状态
        if(!empty($validated['state'])){
            $sate = []; $sosate=0;
            switch ($validated['state']){
                case 1:
                    $sate = [1];
                    break;
                case 2:
                    $sate = [2,3,4,5,6,9];
                    //PI的部分SO已发货
                    break;
                case 3:
                    $sate = [2,3];
                    break;
                case 4:
                    $sate = [9,10];
                    break;
                case 5:
                    $sate = [4,5];
                    break;
                case 6:
                    $sate = [6];
                    break;
                case 7:
                    $sosate = 1;
                    break;
                case 8:
                    $sosate = 2;
                    break;
            }
            $validated['state'] = $sate;
            $validated['sosate'] = $sosate;
        }

        # 判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin(UserId::$user_id);
        if($userInfo){
            $validated["user_id"] = $userInfo;
        }
        # 获取销售订单数据
        [$data->list,$data->total] =  Orders::getInstance()->getOrderList($validated);

        # 补齐(Sales_User_ID) （内部用户user表，外部用户missu_users表）中对应的用户id,name 放到$data->list["sales"]字段里
        MissuUser::getInstance()->fillUsers($data->list, 'Sales_User_ID', 'sales');

        # 补齐客户国家信息
        CustomerInfo::getInstance()->fillCountry($data->list,'Customer_Seller_info_id');
        foreach ($data->list as $item) {
            $item->CreateTime = date("Y-m-d H:i:s",$item->CreateTime);
            $item->items = OrdersItemInfo::getInstance()->getOrderItemsF1($item->order_id);

            $item->totalQuantity = $item->totalShipQty=0;
            $state = []; $sendQty=[];
            foreach ($item->items as $v){
                $state[] = $v->State;
                $item->totalQuantity += $v->quantity;
                $item->totalShipQty += $v->ShipQty;
                $sendQty[] = ($v->SendQty == $v->quantity) ? 2:1;
            }
            $state = array_unique($state);
            $sendQty = array_unique($sendQty);
            if(count($state) == 1 && $state[0] == 1){
                $item->progress = "待开始";
            }
            else if(count($sendQty) == 1 && $sendQty[0] == 2){
                $item->progress = "已完成";
            }
            else{
                $item->progress = "进行中";
            }

        }
        return $this->renderJson($data);
    }


    //PI详情：获取销售列表对应的采购，发货信息
    public function piInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' =>'required|integer|gt:0',
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
        $data->CreateTime = date("Y-m-d H:i:s", $data->CreateTime);
        $data->modelNum = 0;  #型号总数
        $data->podNum =0;     #采购总件数
        $data->receivedNum = 0; #实到数量

        $data->item = OrdersItemInfo::getInstance()->getOrderItemsF1($validated["order_id"]);
        foreach ($data->item as $val){
            $data->modelNum++;

            #产品序列号总的数量
            $val->serialQuantity = SerialNumbers::getInstance()->getSumQuantity(["order_info_id"=>$val->order_info_id]);
            $data->receivedNum += $val->serialQuantity;
            $data->podNum += $val->quantity;

            # 获取销售订单创建的发货任务
            $val->shipTaskItem = ShipTaskItem::getInstance()->getShipTaskInfoById($val->order_info_id);

            //$stats = [];
            foreach ($val->shipTaskItem as $so){
                $so->submitNum = ShiptaskSubmit::getInstance()->getItemNum($so->ShioTask_item_id);
                //$stats[] = $so->State;
                //if($so->State ==2) $val->State = 11; //已发货
            }
            if($val->SendQty){
                $val->State = 11; //已发货
            }
        }
        return $this->renderJson($data);
    }


    //获取待处理数量
    public function unConfirmed(Request $request): \Illuminate\Http\JsonResponse
    {
        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin(\App\Http\Middleware\UserId::$user_id);

        # 待处理总数
        $data = new \stdClass();
        $data->unConfirmed = Orders::getInstance()->getUnConfirmedCount($userInfo?:null);
        return $this->renderJson($data);
    }


    //发货页面初始化
    public function initDelivery(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_ids' => 'required|string',  #多个PI用逗号隔开
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $ids = explode(",", $validated["order_ids"]);
        //var_dump($ids);
        $data = new \stdClass();
        $data->list = Orders::getInstance()->getByIdsF1($ids);
        if (!$data->list || ( count($ids) != count($data->list) ) ) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }

        # 补齐(Sales_User_ID) （内部用户user表，外部用户missu_users表）中对应的用户id,name 放到$data->list["sales"]字段里
        MissuUser::getInstance()->fillUsers($data->list, 'Sales_User_ID', 'sales');

        # 补齐客户国家信息
        CustomerInfo::getInstance()->fillCountry($data->list,'Customer_Seller_info_id');

        foreach ($data->list as $item){
            $item->items = OrdersItemInfo::getInstance()->getOrderItemsF1($item->order_id);
            $item->soList= ShipTask::getInstance()->getByOrderId($item->order_id,1); //获取未发货的SO
        }
        return $this->renderJson($data);
    }

    //发货
    public function delivery(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_ids' => 'required|string',  #多个PI用逗号隔开
                'order_list' => 'required|string',    # [{"order_info_id":"","quantity":"5"}]
                'shiptask_id' => 'integer|gt:0', #合并的发货单ID
            ]);
            $order_list = !empty($validated["order_list"]) ? json_decode($validated["order_list"]) :[];
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if(empty($order_list)){
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        if(!empty($validated['shiptask_id'])){
            $shiptask = ShipTask::getInstance()->getByIdF1($validated['shiptask_id']);
            if (!$shiptask) {
                return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
            }
            if($shiptask->State>1){ #已发货则不能合并
                return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
            }
        }
        $user_id = UserId::$user_id;
        $ids = explode(",", $validated["order_ids"]);
        $orders = Orders::getInstance()->getByIdsF1($ids);

        #验证所有PI是不是同一个销售ID
        $sale_id=[];$order_ids=[];
        foreach ($orders as $v){
            $sale_id[] = $v->Sales_User_ID;
            $order_ids[] = $v->order_id;
        }
        if(count(array_unique($sale_id)) > 1){
            return $this->renderErrorJson(\App\Ok\SysError::MULTIPLE_SALES_EXIST);
        }
        #验证发货数是不是大于了订货数？？

        $Purchaseorder_ids = [];
        $data = new \stdClass();
        $piNames = [];
        #新建发货单
        if(empty($validated["shiptask_id"])){
            $soItemParam = [];
            #处理发货明细
            foreach ($order_list as $val){
                [$orderInfo,$purchase,$val->PI_name] = $this->returnDelivery($val->order_info_id);
                $val->order_id = $orderInfo->order_id;
                $piNames[] = $val->PI_name;
                foreach ($purchase as $v){
                    $Purchaseorder_ids[] = $v->Purchaseorder_id; #获取所有的采购订单
                }
                $temp = $this->returnShipTaskItem($user_id,$orderInfo,$val);
                $temp["Purchaseorder_detailed_id"] = !empty($purchase[0]->Purchaseorder_detailed_id)?:0;
                $soItemParam[] = $temp;
            }
            #处理发货主表
            $soParam = $this->returnShipTask($user_id,$orders[0],$Purchaseorder_ids);
            $soParam["order_id"] = implode(",", $order_ids);
            #保存数据
            $data->affect = ShipTask::getInstance()->createShipTask($soParam,$soItemParam);
            $data->Shiptask_id = $data->affect;
        }
        #合并发货单
        else{
            $soItem = $soItemUpdate =[];
            $Purchaseorder_ids = explode(",", $shiptask->Purchaseorder_id);
            #处理发货明细
            foreach ($order_list as $val)
            {
                [$orderInfo,$purchase,$val->PI_name] = $this->returnDelivery($val->order_info_id);
                $val->order_id = $orderInfo->order_id;
                $piNames[] = $val->PI_name;
                foreach ($purchase as $v){
                    $Purchaseorder_ids[] = $v->Purchaseorder_id; #获取所有的采购订单
                }
                $shiptaskItem = ShipTaskItem::getInstance()->getByOrderInfoId($validated['shiptask_id'],$val->order_info_id);   #根据order_info_id,shiptask_id
                if($shiptaskItem){
                    #已有的型号
                    $soItemUpdate[] = [
                        "newQtynumber" =>$val->quantity,  //用于orders_item_info更新发货数量
                        "Qtynumber" => $val->quantity + $shiptaskItem->Qtynumber,
                        "ShioTask_item_id" => $shiptaskItem->ShioTask_item_id,
                        "order_info_id" => $shiptaskItem->order_info_id,
                        "Purchaseorder_detailed_id" => !empty($purchase[0]->Purchaseorder_detailed_id)?:0,
                    ];
                }
                else{
                    #新增的型号
                    $temp = $this->returnShipTaskItem($user_id,$orderInfo,$val);
                    $temp["Shiptask_id"] = $validated['shiptask_id'];
                    $temp["Purchaseorder_detailed_id"] = !empty($purchase[0]->Purchaseorder_detailed_id)?:0;  #一般情况下不会没有采购订单
                    $soItem[] = $temp;
                }
            }

            #处理发货主表
            $temp = explode(",", $shiptask->order_id);
            foreach ($temp as $v){
                $order_ids[] = (int)$v;
            }
            $soParam = [
                "order_id" => implode(",", array_unique($order_ids)),  #原有的加新的，再去重
                "Purchaseorder_id" => implode(",", array_unique($Purchaseorder_ids)),   #原有的加新的，再去重
                "Update_tiem" => date("Y-m-d H:i:s"),
                "Shiptask_id" => $validated['shiptask_id'],
            ];
            # 合并到数据库
            $data->affect = ShipTask::getInstance()->mergeShipTask($soParam,$soItem,$soItemUpdate);
            $data->Shiptask_id = $validated['shiptask_id'];
        }

        #合并日志记录
        if($data->affect && !empty($validated["shiptask_id"])){
            $f9 = json_encode($order_list);  //？？？
            Oplog::getInstance()->addSoLog($user_id, $data->Shiptask_id, "SO合并","合并型号",["f9"=>$f9]);
            OplogApi::getInstance()->addLog($user_id, "SO合并", json_encode($validated));
        }else{
            OplogApi::getInstance()->addLog($user_id, "SO新建", json_encode($validated));
        }


        #发送消息
        if($data->Shiptask_id){
            $shiptask = ShipTask::getInstance()->getByIdF1($data->Shiptask_id);
            $tmp = [
                "user_id" => $user_id,
                "source" => "APP内推送",
                "jump_url" => "/soModule/pages/soDetails/soDetails?id=".$data->Shiptask_id."&invoice=".$shiptask->Shiptask_name."&processIdentification=documentation",
                "title" => "您有合并的SO待处理，请及时处理！",
                "content" => implode(",", $piNames)."销售订单已发货",
            ];
            if(empty($validated["shiptask_id"]))  $tmp["title"] = "您有新的SO待处理，请及时处理！";
            $userInfo = MissuUser::getInstance()->getUserByGroupId(198);
            $recipient=[];
            foreach ($userInfo  as $v){
                $recipient[] = $v->id;
            }
            $userInfo = MissuUser::getInstance()->getUserByGroupId(199);
            foreach ($userInfo  as $v){
                $recipient[] = $v->id;
            }
            Message::getInstance()->addMessage($tmp,$recipient);
        }


        # 同步到RabbitMq
        $messageBody = array(
            "method"=>"mergeShipTask",
            "params"=>[
                "shiptask"=>ShipTask::getInstance()->getSynchronization($data->Shiptask_id),
                "shipTaskItem"=>ShipTaskItem::getInstance()->items($data->Shiptask_id),  #型号
            ],
        );
        \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);
        return $this->renderJson($data);
    }

    //返回发货接口需要的数据
    private function returnDelivery($order_info_id)
    {
        $orderInfo = OrdersItemInfo::getInstance()->getByIdAS($order_info_id);
        $purchase = PurchaseOrderDetailed::getInstance()->getPurchaseOrderByOrderInfoId($order_info_id);
        $order = Orders::getInstance()->getByIdF1($orderInfo->order_id);
        $pi_name = $order->PI_name;
        return [$orderInfo,$purchase,$pi_name];
    }

    //组装发货单主表
    private function returnShipTask($user_id,$orders,$Purchaseorder_ids)
    {
        if(!empty($orders->address_customer_info_id)){
            $countryId = CustomerInfo::getInstance()->getCountryIdById($orders->address_customer_info_id);
        }else{
            $countryId = 0;
        }

        #查看$orders['0']['PI_name']已经发货几次
        $num = ShipTask::getInstance()->getShipTaskCount($orders->order_id);
        $num = $num+1;
        return [
            "order_id" => "",
            "Shiptask_name"=>'S'.$orders->PI_name.'0'.$num,   #'S'.$orders['0']['PI_name'].'01'
            "Purchaseorder_id" => implode(",", array_unique($Purchaseorder_ids)),   #多个
            "Sales_User_ID" => $orders->Sales_User_ID,
            "Customer_Seller_info_id" => $orders->Customer_Seller_info_id,
            "address_customer_info_id" => $orders->address_customer_info_id,
            "Country_id" => $countryId,   #customer_info表里拿
            "State" => 1,
            "Sort" => $orders->order_id,
            "Enable" => 0,
            "Update_tiem" => date("Y-m-d H:i:s"),
            "create_time" => date("Y-m-d H:i:s"),
            "create_user" => $user_id,
            "Shipdatetime"=>date("Y-m-d H:i:s"),
        ];
    }

    //组装发货单明细
    private function returnShipTaskItem($user_id,$orderInfo,$val)
    {
        return $temp = [
            "Qtynumber" => $val->quantity,
            "order_info_id" => $val->order_info_id,
            "Shiptask_id" => "",
            "products_id" => $orderInfo->product_id,
            "Purchaseorder_detailed_id" => "",
            "products_Name" => $orderInfo->product_name_pi,
            "Leading_name" => $orderInfo->leadingtime,
            "Model" => $orderInfo->product_name_pi,
            "Brand" => $orderInfo->Brand_id,
            "Brand_name" => $orderInfo->Brand_name,
            "Weight" => $orderInfo->weight,
            "Purchaser_id" => $orderInfo->Purchaser_id,
            "Sort" => $val->order_info_id,
            "State" => 1,
            "create_time" => date("Y-m-d H:i:s"),
            "create_user" => $user_id,
            "weight_unit" => $orderInfo->weight_unit,
        ];
    }



}
