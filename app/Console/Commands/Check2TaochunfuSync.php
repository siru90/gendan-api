<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;
use \App\Services\M2\Check2Taochunfu;


class Check2TaochunfuSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check2_taochunfu';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步外部系统check2_taochunfu表到内部系统';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        #查询
        $param = ["size"=>500];
        $list = Check2Taochunfu::getInstance()->getShipTask($param);
        if(empty($list)) return;

        foreach ($list as $data){
            $shiptask = !empty($data->after)? json_decode($data->after,true):[];
            if(empty($shiptask)) continue;

            foreach ($shiptask as $obj){
                $shipParam = $paramItem = [];
                #判断SO是不是存在
                $so = \App\Services\M\ShipTask::getInstance()->getIdByCrmShipTaskId($obj["Shiptask_id"]);
                if($so){ continue; }
                #组装参数：so，soitem
                $shipParam = $this->returnShipTask($obj);
                #获取外部系统的so明细
                $soItem = \App\Services\M2\ShipTaskItem::getInstance()->getItems($obj["Shiptask_id"]);
                foreach ($soItem as $item){
                    $tmp = $this->returnShipItem((array)$item);
                    $paramItem[] = $tmp;
                }

                # 保存到数据库
                $res = new \stdClass();
                [$res->id,$res->itemID] = \App\Services\M\ShipTask::getInstance()->syncShipTask($shipParam,$paramItem);

                if(count($res->itemID)){
                    #同步映射ID给外部系统
                    $messageBody = array(
                        "method"=>"syncKey",
                        "params"=>$res->itemID,
                    );
                    [$queue,$exchange,$routeKey] = $this->mq();
                    \App\Ok\RabbitmqConnection::getInstance()->push($queue,$exchange,$routeKey,$messageBody);
                }
            }

            # 删除记录
            Check2Taochunfu::getInstance()->delete($data->check2_id);
        }
    }

    private function mq()
    {
        $so_queue = \Illuminate\Support\Facades\Config::get('app.so_rabbitmq');
        $queue = $so_queue["queue"];
        $exchange = $so_queue["exchange"];
        $routeKey = $so_queue["routeKey"];

        return [$queue,$exchange,$routeKey];
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

            "Shipdatetime" =>$obj["Shipdatetime"]?? null,
            "shipping_way" =>$obj["shipping_way"]??"",
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
            //"Purchaseorder_detailed_id" => $obj["Purchaseorder_detailed_id"],
            "products_Name" => $obj["products_Name"],
            "Leading_name" => $obj["Leading_name"],
            "Model" => $obj["Model"],
            "Qtynumber" => $obj["Qtynumber"],
            "Brand" => $obj["Brand"],
            "Brand_name" => $obj["Brand_name"],
            "State" => $obj["State"],
            "Weight" => $obj["Weight"],
            "Purchaser_id" => $obj["Purchaser_id"],
            "Picture_url" => $obj["Picture_url"],
            "Sort" => $obj["Sort"],
            "Comments" => $obj["Comments"],
            "create_time" => $obj["create_time"],
            "create_user" => $obj["create_user"],
            "order_info_id" => $obj["crm_order_info_id"],
            "weight_unit" => $obj["weight_unit"],
        ];
        #查询采购订单详细ID
        $pod = \App\Services\M\PurchaseOrderDetailed::getInstance()->getByCrmPodId($obj["Purchaseorder_detailed_id"]);
        if($pod){
            $tmp["Purchaseorder_detailed_id"] = $pod->Purchaseorder_detailed_id;
        }
        return $tmp;
    }





}
