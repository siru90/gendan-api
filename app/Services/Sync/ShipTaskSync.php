<?php

namespace App\Services\Sync;

use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\CustomerInfo;
use \App\Services\M\ShipTask;
use \App\Services\Oplog;
use Illuminate\Support\Facades\Log;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;



class ShipTaskSync extends \App\Services\BaseService
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

    //同步数据,保存到数据库
    public function saveShipTask(array $args):bool|int
    {
        //$msg ='{"body":"{\"shiptask\":{\"order_id\":\"25791\",\"Shiptask_name\":\"SCN231123351201\",\"Sales_User_ID\":99,\"Customer_Seller_info_id\":40,\"address_customer_info_id\":56,\"Country_id\":0,\"State\":1,\"Sort\":77761,\"Enable\":0,\"Update_tiem\":\"2023-11-23 10:11:36\",\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"Weight\":31.700000000000003,\"weight_unit\":\"kg\",\"Shiptask_id\":8050},\"shiotask_item\":[{\"ShioTask_item_id\":19215,\"Shiptask_id\":8050,\"products_id\":66,\"products_Name\":\"CP5611-A2\",\"Leading_name\":\"2-3 weeks\",\"Model\":\"CP5611-A2\",\"Qtynumber\":10,\"Brand\":6,\"Brand_name\":\"WEINVIEW\",\"Weight\":0.35,\"weight_unit\":\"kg\",\"Purchaser_id\":401468,\"State\":1,\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"order_info_id\":64190},{\"ShioTask_item_id\":19216,\"Shiptask_id\":8050,\"products_id\":7,\"products_Name\":\"6AV2124-1DC01-0AX0\",\"Leading_name\":\"2-3 weeks\",\"Model\":\"6AV2124-1DC01-0AX0\",\"Qtynumber\":1,\"Brand\":1173,\"Brand_name\":\"ACS\",\"Weight\":31,\"weight_unit\":\"kg\",\"Purchaser_id\":401467,\"State\":1,\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"order_info_id\":64191},{\"ShioTask_item_id\":19217,\"Shiptask_id\":8050,\"products_id\":66,\"products_Name\":\"CP5611-A2\",\"Leading_name\":\"3-5 days\",\"Model\":\"CP5611-A2\",\"Qtynumber\":100,\"Brand\":6,\"Brand_name\":\"WEINVIEW\",\"Weight\":0.35,\"weight_unit\":\"kg\",\"Purchaser_id\":401468,\"State\":1,\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"order_info_id\":64192}]}","body_size":1305,"is_truncated":false,"content_encoding":null,"delivery_info":{"channel":{"callbacks":{"amq.ctag-QW0hz7acwvYk7v3iB_56bQ":[{},"process_message"]}},"delivery_tag":1,"redelivered":false,"exchange":"outside_shiptask","routing_key":"outside_shiptask","consumer_tag":"amq.ctag-QW0hz7acwvYk7v3iB_56bQ"}} ';

        Log::channel('sync')->info('111');
        if(!isset($args["shiptask"]) || !isset($args["shiotask_item"]))
            return true;
        $shiptask = $args["shiptask"];
        $shiptaskItem = $args["shiotask_item"];

        # 判断用户是否存在,是内部用户才需要同步
        $user = MissuUser::getInstance()->getUserInfo($shiptask["Sales_User_ID"]);
        if (!$user) return true;

        #新增时：判断数据库是否已存在,避免重复消费
        $res = ShipTask::getInstance()->getIdByCrmShipTaskId($shiptask["Shiptask_id"]);
        //Log::channel('sync')->info('Rabbit [getIdByCrmShipTaskId] $res: '.json_encode($res));
        if($res && empty($shiptask["merge_so"])) return true;

        #转换字段
        $shipParam = $this->returnShipTask($shiptask);
        $order_id = !empty($shiptask["crm_order_id"]) ? $shiptask["crm_order_id"]:0;
        $customer_info_id = !empty($shiptask["crm_customer_seller_info_id"]) ? $shiptask["crm_customer_seller_info_id"]:0;

        #查找内部数据中对应的id
        if(empty($order_id)){
            $order = Orders::getInstance()->getOrderIdByCrmOrderId($shiptask["order_id"]);
            $order_id = $order->order_id ?? 0;
        }
        $shipParam["order_id"] = $order_id;
        if(empty($customer_info_id)){
            $customer = CustomerInfo::getInstance()->getInfoByCrmCustomerInfoId($shiptask["Customer_Seller_info_id"]);
            $customer_info_id = $customer->customer_info_id ?? 0;
        }
        $shipParam["Customer_Seller_info_id"] = $customer_info_id;


        $paramItem = [];
        foreach ($shiptaskItem as $key => $obj){
            if(!empty($shiptask["merge_so"])) {  #表示合并SO, 过滤已存在的SO明细,其他新增
                $item = \App\Services\M\ShipTaskItem::getInstance()->getByCrmItemId($obj["ShioTask_item_id"]);
                if ($item) continue;
            }
            $order_info_id = !empty($obj["crm_order_info_id"]) ? $obj["crm_order_info_id"]:0;
            if(empty($order_info_id)){
                $orderItemInfo = OrdersItemInfo::getInstance()->getInfoByCrmOrderInfoId($obj["order_info_id"]);
                $order_info_id = $orderItemInfo->order_info_id ?? 0;
            }
            $tmp = $this->returnShipItem($obj);
            $tmp["order_info_id"] = $order_info_id;
            $paramItem[] = $tmp;
            if(empty($shipParam["order_id"]) && !empty($orderItemInfo->order_id)){
                $shipParam["order_id"] = $orderItemInfo->order_id;
            }
        }
        //unset($shiptask["Shiptask_id"],$shiptask["merge_so"]);

        Log::channel('sync')->info('Rabbit [createShipTask] params $shiptask: '.json_encode($shiptask));
        Log::channel('sync')->info('Rabbit [createShipTask] params $shiptaskItem: '.json_encode($paramItem));

        # 保存到数据库
        $res = new \stdClass();
        [$res->id,$res->itemID] = ShipTask::getInstance()->syncShipTask($shipParam,$paramItem);
        if($res->id){
            Oplog::getInstance()->addSoLog($shiptask["Sales_User_ID"], $res->id, "MQ同步SO数据","");
        }
        #同步映射ID给外部系统
        $messageBody = array(
            "method"=>"syncKey",
            "params"=>$res->itemID,
        );
        \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);

        return $res->id;
    }


    private function returnShipTask($obj)
    {
        $tmp =  [
            "order_id" =>$obj["crm_order_id"]??0,
            "crm_shiptask_id" =>$obj["Shiptask_id"],
            "crm_order_id" =>$obj["order_id"],
            "crm_customer_seller_info_id" => $obj["Customer_Seller_info_id"],
            "Customer_Seller_info_id"=>0,
            # 内部系统没有customer_info_address表，所以只保存外部数据，把内部对应的值的设置为0
            "crm_address_customer_info_id" => $obj["address_customer_info_id"],
            "address_customer_info_id" => 0,
            # Country_id 不用映射，内外部系统一致
            "Country_id" => $obj["Country_id"],

            "Shiptask_name" => $obj["Shiptask_name"],
            "Sales_User_ID" => $obj["Sales_User_ID"],
            "State" => $obj["State"],
            "Sort" => $obj["Sort"],
            "Enable" => $obj["Enable"],
            "Update_tiem" => $obj["Update_tiem"],
            "create_time" => $obj["create_time"],
            "create_user" => $obj["create_user"],
            "Weight" => $obj["Weight"],
            "weight_unit" => $obj["weight_unit"],
            "org_id" => $obj["org_id"],
            "COMPANY_ID" => $obj["COMPANY_ID"],
            "remarks" => $obj["remarks"]??"",
        ];

        if(!empty($obj["currency"])){
            $tmp["currency"] = $obj["currency"];
        }
        if(!empty($obj["Shipping_cost"]) || $obj["Shipping_cost"] ==0){
            $tmp["Shipping_cost"] = $obj["Shipping_cost"];
        }
        if(!empty($obj["current_rate"])){
            $tmp["current_rate"] = $obj["current_rate"];
        }
        return $tmp;
    }

    private function returnShipItem($obj)
    {
        $tmp = [
            "crm_shiptask_id" =>$obj["Shiptask_id"],
            "crm_shiptask_item_id" =>$obj["ShioTask_item_id"],
            "crm_order_info_id" => $obj["order_info_id"],
            "products_id" => $obj["products_id"],
            "Purchaseorder_detailed_id" => $obj["Purchaseorder_detailed_id"],
            "products_Name" => $obj["products_Name"],
            "Leading_name" => $obj["Leading_name"],
            "Model" => $obj["Model"],
            "Qtynumber" => $obj["Qtynumber"],
            "Brand" => $obj["Brand"],
            "Brand_name" => $obj["Brand_name"],
            "State" => $obj["State"],
            "Weight" => $obj["Weight"],
            "Purchaser_id" => !empty($obj["Purchaser_id"])?$obj["Purchaser_id"]:0,
            "Picture_url" => $obj["Picture_url"],
            "Sort" => $obj["Sort"],
            "Comments" => $obj["Comments"],
            "create_time" => $obj["create_time"],
            "create_user" => $obj["create_user"],
            "order_info_id" => 0,
            "weight_unit" => $obj["weight_unit"],
        ];
        return $tmp;
    }



    //同步数据,so编辑发货方式
    public function editShiptask(array $args):bool|int
    {
        //{"method":"editShiptask","params":{"Country_id":"505","Shipdatetime":"2024-07-01 17:56:17","Shipping_cost":"","currency":"","crm_shiptask_id":"1719827748521386535"}}
        $params = $args["params"];
        if(empty($params["Shipping_cost"])) unset($params["Shipping_cost"]);
        if(empty($params["currency"])) unset($params["currency"]);
        if(empty($params["crm_shiptask_id"])) return true;

        #判断数据库是否已存在,存在才编辑
        $res = ShipTask::getInstance()->getIdByCrmShipTaskId($params["crm_shiptask_id"]);
        Log::channel('sync')->info('Rabbit [editShiptask] $res: '.json_encode($res));
        if($res){
            $crm_shiptask_id = $params["crm_shiptask_id"];
            unset($params["crm_shiptask_id"]);
            $res = ShipTask::getInstance()->updateByCrmShiptaskId($crm_shiptask_id,$params);
        }else if(!empty($params["Shiptask_id"])){
            $res = ShipTask::getInstance()->updateShipTask($params["Shiptask_id"],$params);
        }
        return $res;
    }

    //同步数据，SO的Id, ItemId，对应外部系统的
    public function editShiptaskIds(array $args)
    {
       //{"method": "syncKey","params": [{"crm_shiptask_id": 1719971044444700179,"Shiptask_id": 35578,"ShioTask_item_id":1719971044453774139,"crm_shioTask_item_id":0}]}
        $params = $args["params"];
        if(empty($params)) return true;
        $soParam = [
            "Shiptask_id"=>$params[0]["Shiptask_id"],
            "crm_shiptask_id"=>$params[0]["crm_shiptask_id"],
            "Update_tiem"=>date("Y-m-d H:i:s"),
        ];
        $soItemParm = [];
        foreach ($params as $obj){
            $soItemParm[] = [
                "ShioTask_item_id"=>$obj["ShioTask_item_id"],
                "crm_shiptask_id" =>$obj["crm_shiptask_id"],
                "crm_shioTask_item_id"=>$obj["crm_shioTask_item_id"],
            ];
        }

        //Log::channel('sync')->info('Rabbit [editShiptaskIds] $soParam: '.json_encode($soParam));
        //Log::channel('sync')->info('Rabbit [editShiptaskIds] $soItemParm: '.json_encode($soItemParm));

        $res = \App\Services\M\ShipTask::getInstance()->updateCrmIds($soParam,$soItemParm);
        Log::channel('sync')->info('Rabbit [editShiptaskIds] $res: '.json_encode($res));
        unset($params,$soParam,$soItemParm);
        return $res;
    }


    //同步数据，CRM运输单发货
    public function sendShiptask(array $args)
    {
        //{"method": "sendShiptask","params": ["crm_shiptask_id": 1719971044444700179,"Shiptask_id": 35578,"State":1]}
        // 外部系统Sate：1 待发货  2 已发货  5 暂无记录 6 在途中 7 派送中 8 已签收 (完结状态) 9 用户拒签 10 疑难件 11 无效单 (完结状态) 12 超时单 13 签收失败 14 退回 15关闭
        $params = $args["params"];
        if(empty($params)) return true;
        $soParam = [
            //"Shiptask_id"=>$params["Shiptask_id"],
            "State"=>$params["State"],
            "Shipdatetime"=>isset($params["Shipdatetime"]) ?$params["Shipdatetime"]: date("Y-m-d H:i:s") ,
        ];
        $ship = \App\Services\M\ShipTask::getInstance()->getIdByCrmShipTaskId($params["crm_shiptask_id"]);
        $res = null;
        if($ship){
            $res = \App\Services\M\ShipTask::getInstance()->updateStateByCrmSOID($params["crm_shiptask_id"],$soParam);
        }else if(!empty($params["Shiptask_id"])){
            $soParam["crm_shiptask_id"] = $params["crm_shiptask_id"];
            $res = \App\Services\M\ShipTask::getInstance()->updateState($params["Shiptask_id"],$soParam);
        }

        #更新PI单里的发货数量
        if($res && $params["State"]==2){
            if($params["Shiptask_id"]){
                \App\Jobs\OrdersStatusUpdate::dispatchSync($params["Shiptask_id"]);
            }
            else{
                \App\Jobs\OrdersStatusUpdate::dispatchSync($params["crm_shiptask_id"],1);
            }
        }
        Log::channel('sync')->info('Rabbit [sendShiptask] $res: '.json_encode($res));
        unset($params,$soParam);
        return $res;
    }
}

