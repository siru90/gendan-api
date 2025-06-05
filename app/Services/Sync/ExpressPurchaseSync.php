<?php

namespace App\Services\Sync;

use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\PurchaseOrder;
use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\Oplog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use \App\Services\M\ExpressCompany;
use \App\Services\ExpressDelivery;
use \App\Services\ExpressOrders;
use \App\Services\Products;


//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;



class ExpressPurchaseSync extends \App\Services\BaseService
{
    //同步数据,保存到数据库
    public function saveExpressPurchase(array $args):bool|int
    {
        Log::channel('sync')->info('[saveExpressPurchase] params $args: '.json_encode($args));

        $exDelivery = $args["GdExpressDelivery"];
        $exOrders = $args["GdExpressOrders"];
        $exProducts = $args["GdProducts"];

        if(empty($exDelivery) || empty($exOrders) || empty($exProducts)) return true;

        # 判断用户是否存在,是内部用户才需要同步
        $user = MissuUser::getInstance()->getUserInfo($exDelivery["user_id"]);
        if (!$user) return true;

        #组装快递
        $expressParam=[
            "user_id" =>$exDelivery["user_id"],
            "tracking_number"=>$exDelivery["tracking_number"],
            "status"=>$exDelivery["status"],
            "purchaser_id"=>$exDelivery["purchaser_id"],
            "submit_status"=>$exDelivery["submit_status"],
            "check_status"=>$exDelivery["check_status"],
            "sender_phone"=>$exDelivery["sender_phone"],
            "receiver_phone"=>$exDelivery["receiver_phone"],
            "created_at"=>date("Y-m-d H:i:s", strtotime($exDelivery["created_at"])),
            "updated_at"=>date("Y-m-d H:i:s", strtotime($exDelivery["updated_at"])),
            "crm_id"=>$exDelivery["id"],
            //"id"=> $this->get_unique_id(),
        ];
        $channel = ExpressCompany::getInstance()->getByExpName($exDelivery["channel_name"]);
        $expressParam["channel_id"] = $channel->express_id;

        #组装快递产品
        $expressProduct= [];
        foreach ($exProducts as $val){
            $expressProduct[] = $this->returnProduct($val);
        }
        //var_dump($expressProduct);

        #组装快递采购单
        $expressPurchase=[];
        foreach ($exOrders as $val){
            $expressPurchase[] = $this->returnPurcharse($val);
        }

        #新增快递后得到内外部映射值
        $result = $this->addExpressPurchase($expressParam,$expressProduct,$expressPurchase);

        if($result){
            # 调用curl，发送消息到
            $url = env("EXTERNAL_CRM_HTTP_URL")."/crm/PurchaseOrder/ReExpressOrderAddSyncToOldCrmCallBack";
            $output = \App\Ok\Curl::getInstance()->curlPost($url, $result);
        }else{
            \App\Services\SyncCrm::getInstance()->addSyncContent(["sync_content"=>json_encode($args),"sync_res"=>false]);
        }

        Log::channel('sync')->info('[saveExpressPurchase] $result: '.json_encode($result));
        //var_dump($result);

        return true;
    }

    //返回产品数据
    private function returnProduct($param):array
    {
        $product = [
            //"id"=>"",
            //"express_id" => "",
            "user_id" => $param["user_id"],
            "model" =>$param["model"],
            "note" => htmlspecialchars($param["note"]),
            "quantity" => $param["quantity"],
            "submit_status"=>!empty($param["submit_status"])?$param["submit_status"] :1,
            "status" => $param["status"],
            "created_at" => date("Y-m-d H:i:s", strtotime($param["created_at"])),
            "updated_at" => date("Y-m-d H:i:s", strtotime($param["updated_at"])),
            "ihu_product_id" => $param["ihu_product_id"],
            "is_confirmed" => 0,
            "crm_id" => $param["id"],
            "crm_express_id" => $param["express_id"],
            "actual_model" =>$param["model"],
            "actual_quantity" =>$param["quantity"]
        ];

        return $product;
    }

    //返回快递采购单数据
    private function returnPurcharse($param):array
    {
        $purchase = [
            "id"=>"",
            "express_id" => "",
            "product_id" => "",
            "user_id" => $param["user_id"],
            "quantity" => $param["quantity"],
            "status" => $param["status"],
            "created_at" => date("Y-m-d H:i:s", strtotime($param["created_at"])),
            "updated_at" => date("Y-m-d H:i:s", strtotime($param["updated_at"])),
            "purchaser_id" => $param["purchaser_id"],
            "order_id" => $param["crm_order_id"],
            "order_item_id" => $param["crm_order_item_id"],
            "purchaseorder_id" => $param["crm_purchaseorder_id"],
            "purchaseorder_detailed_id" => $param["crm_purchaseorder_detailed_id"],
            "crm_id" => $param["id"],
            "crm_express_id" => $param["express_id"],
            "crm_product_id" => $param["product_id"],
            "crm_order_id" => !empty($param["order_id"]) ? $param["order_id"] : 0,
            "crm_order_item_id" => $param["order_item_id"],
            "crm_purchaseorder_id" => $param["purchaseorder_id"],
            "crm_purchaseorder_detailed_id" => $param["purchaseorder_detailed_id"],
        ];

        if(empty($purchase["order_id"])){
            $order = Orders::getInstance()->getOrderIdByCrmOrderId($purchase["crm_order_id"]);
            $purchase["order_id"] = $order->order_id ?? 0;
        }
        if(empty($purchase["order_item_id"])){
            $orderItemInfo = OrdersItemInfo::getInstance()->getInfoByCrmOrderInfoId($purchase["crm_order_item_id"]);
            $purchase["order_item_id"] = $orderItemInfo->order_info_id ?? 0;
        }
        if(empty($purchase["purchaseorder_id"])){
            $po = PurchaseOrder::getInstance()->getByCrmPurchaseorderId($purchase["crm_purchaseorder_id"]);
            $purchase["purchaseorder_id"] = $po->purchaseorder_id ?? 0;
        }
        if(empty($purchase["purchaseorder_detailed_id"])){
            $pod = PurchaseOrderDetailed::getInstance()->getByCrmPodId($purchase["crm_purchaseorder_detailed_id"]);
            $purchase["purchaseorder_detailed_id"] = $pod->Purchaseorder_detailed_id ?? 0;
        }
        return $purchase;
    }

    //添加快递次采购到数据库
    private function addExpressPurchase($expressParam,$productParam,$purchaseParam)
    {
        Log::channel('sync')->info('[ExpressPurchaseSync-addExpressPurchase] params $expressParam: '.json_encode($expressParam));
        Log::channel('sync')->info('[ExpressPurchaseSync-addExpressPurchase] params $productParam: '.json_encode($productParam));
        Log::channel('sync')->info('[ExpressPurchaseSync-addExpressPurchase] params $purchaseParam: '.json_encode($purchaseParam));

        $data = new \stdClass();
        $data->express_delivery = $data->experss_products = $data->experss_orders =[];
        $tmpEx = $tmpPd = [];
        $res = null;
        DB::beginTransaction();
        try {
            #保存快递
            $express = DB::table("gd_express_delivery")->where("crm_id",$expressParam["crm_id"])->where("status",1)->first();
            if($express){
                $val["id"] = $express->id;
                DB::table("gd_express_delivery")->where("id",$express->id)->update($expressParam);
            }else{
                $expressParam["id"] = $this->get_unique_id();
                $tmpEx[$expressParam["crm_id"]] = $expressParam["id"];

                DB::table("gd_express_delivery")->insert($expressParam);
            }
            $tmpEx[$expressParam["crm_id"]] = isset($express->id) ? $express->id : $expressParam["id"];
            $data->express_delivery = ["id"=>isset($express->id) ? $express->id : $expressParam["id"], "crm_id"=>$expressParam["crm_id"]];

            #保存快递产品
            foreach ($productParam as $val){
                $prdouct = DB::table("gd_products")->where("status",1)->where('crm_id',$val["crm_id"])->first();
                if($prdouct){
                    $val["id"] = $prdouct->id;
                    DB::table("gd_products")->where('crm_id',$val["crm_id"])->update($val);
                }else{
                    $val["id"] = $this->get_unique_id();
                    $val["express_id"] = $tmpEx[$val["crm_express_id"]] ?? 0;
                    DB::table("gd_products")->insert($val);
                }
                $tmpPd[$val["crm_id"]] = isset($prdouct->id) ? $prdouct->id : $val["id"];
                $data->experss_products[] = [
                    "crm_id"=>$val["crm_id"],
                    "id"=>isset($prdouct->id) ? $prdouct->id : $val["id"],
                    "express_id"=>isset($prdouct->express_id)? $prdouct->express_id: $val["express_id"]
                ];
            }

            #保存快递采购单
            $pod_ids = [];
            foreach ($purchaseParam as $val){
                $purchase = DB::table("gd_express_order")->where("status",1)->where('crm_id',$val["crm_id"])->first();
                if($purchase){
                    $val["id"] = $purchase->id;
                    $val["express_id"] = $tmpEx[$val["crm_express_id"]] ?? 0;
                    $val["product_id"] = $tmpPd[$val["crm_product_id"]] ?? 0;
                    $val["status"] = 1;
                    $res=DB::table("gd_express_order")->where('crm_id',$val["crm_id"])->update($val);
                }else{
                    $val["id"] = $this->get_unique_id();
                    $val["express_id"] = $tmpEx[$val["crm_express_id"]] ?? 0;
                    $val["product_id"] = $tmpPd[$val["crm_product_id"]] ?? 0;
                    $res=DB::table("gd_express_order")->insert($val);
                }
                $data->experss_orders[] = [
                    "crm_id"=>$val["crm_id"],
                    "id"=>isset($purchase->id)? $purchase->id : $val["id"],
                    "express_id"=>isset($purchase->express_id)? $purchase->express_id:$val["express_id"],
                ];

                if(!empty($val["purchaseorder_detailed_id"])){
                    $pod_ids[] = $val["purchaseorder_detailed_id"];
                }
            }
            DB::commit();
            #统计采购明细单的数据
            $this->purchaseExpressQty($pod_ids);

            ExpressDelivery::getInstance()->fillPurchaseId($tmpEx[$expressParam["crm_id"]],$tmpPd); #填充采购ID, 产品数量

            Log::channel('sync')->info('[ExpressPurchaseSync-addExpressPurchase] $res: '.json_encode($res));
            return $data;
        }
        catch (\Throwable $e) {
            DB::rollBack();
            return false;
        }

    }

    //同步删除： 外部系统删除同步到内部系统
    public function removeExpressPurchase(array $args)
    {
        Log::channel('sync')->info('[saveExpressPurchase] params $args: '.json_encode($args));

        $exOrders = $args["GdExpressOrders"];
        $pod_ids = [];
        foreach ($exOrders as $val){
            #内外部系统的主键ID转换
            $id = $val["crm_id"];
            $crm_id = $val["id"];
            $expressOrder = DB::table("gd_express_order")->where("status",1)->where('crm_id',$crm_id)->first();
            if($expressOrder){
                if($expressOrder->purchaseorder_detailed_id){
                    $pod_ids[] = $expressOrder->purchaseorder_detailed_id;
                }
                $tmp = ["quantity"=>$val["quantity"],"status"=>$val["status"],"updated_at"=>date("Y-m-d H:i:s", strtotime($val["updated_at"])),];
                $res=DB::table("gd_express_order")->where('crm_id',$crm_id)->update($tmp);
                Log::channel('sync')->info('[removeExpressPurchase] $res: '.$res);

                ExpressDelivery::getInstance()->fillPurchaseId($expressOrder->express_id,[$expressOrder->product_id]); #填充采购ID，统计产品数量

                #判断快递是不是异常快递，删除的型号还是否存在关联，不存在则删除型号
                $express = ExpressDelivery::getInstance()->getExpressDelivery($expressOrder->express_id);
                $productNum = ExpressOrders::getInstance()->getSumQuantity($expressOrder->product_id);
                if($express->abnormal_source ==1 && $productNum==0){
                    Products::getInstance()->deleteModel($expressOrder->product_id);
                }
            }
        }
        #统计采购明细单的数据
        $this->purchaseExpressQty($pod_ids);
    }


    //---
    //同步内部快递到外部Crm系统
    public function syncExpressToExternalCrm(array $args)
    {
        //var_dump($args);
        $express_id = $args["id"];
        $product_ids = $args["product_ids"];
        $express_order_ids = $args["express_order_ids"];
        $data = new \stdClass();
        #组装参数
        $data->GdExpressDelivery = DB::table("gd_express_delivery")->select([
                "created_at as created_at_str",
                "updated_at as updated_at_str",
                "id",
                "user_id",
                "channel_id",
                "tracking_number",
                "status",
                "purchaser_id",
                "actual_purchaser_id",
                "submit_status",
                "check_status",
                "sender_phone" ,
                "receiver_phone",
                "note",
                "is_confirmed",
                "express_status",
                "logistic_status",
                "crm_id",
                "abnormal_status",
                "abnormal_source",
                "abnormal_record",
                "abnormal_submit",
                "submit_purcharse",
            ])
            ->where("status",1)->where('id',$express_id)->first();
        $data->GdExpressDelivery->is_submit = 0;
        $data->GdExpressDelivery->channel_name = $args["channel_name"];
        if(empty($data->GdExpressDelivery)) return;

        $data->GdProducts = DB::table("gd_products")->select([
                "id",
                "user_id",
                "express_id",
                "model",
                "note",
                "quantity",
                "status",
                "created_at as created_at_str",
                "updated_at as updated_at_str",
                "ihu_product_id",
                "is_confirmed",
                "shelf_position",
                "submit_status",
                "crm_id",
                "crm_express_id"
            ])
            ->where("status",1)->whereIn('id',$product_ids)->get()->toArray();

        $data->GdExpressOrders = DB::table("gd_express_order")
            ->select([
                "id",
                "user_id",
                "express_id",
                "product_id",
                "quantity",
                "order_id",
                "order_item_id",
                "purchaseorder_id",
                "purchaseorder_detailed_id",
                "status",
                "purchaser_id",
                "submitted_quantity",
                "crm_id",
                "crm_express_id",
                "crm_product_id",
                "crm_order_id",
                "crm_order_item_id",
                "crm_purchaseorder_id",
                "crm_purchaseorder_detailed_id",
                "created_at as created_at_str",
                "updated_at as updated_at_str",

            ])
            ->where("status",1)->whereIn('id',$express_order_ids)->get()->toArray();

        /*
        #.net要转换时间参数
        $data->GdExpressDelivery->created_at_str = $data->GdExpressDelivery->created_at??date("Y-m-d H:i:s");
        $data->GdExpressDelivery->updated_at_str = $data->GdExpressDelivery->updated_at??date("Y-m-d H:i:s");
        unset($data->GdExpressDelivery->created_at,$data->GdExpressDelivery->updated_at);


        foreach ($GdProducts as $val){
            $val->created_at_str = $val->created_at??date("Y-m-d H:i:s");
            $val->updated_at_str = $val->updated_at??date("Y-m-d H:i:s");
            unset($val->created_at,$val->updated_at);
        }
        foreach ($GdExpressOrders as $val){
            $val->created_at_str = $val->created_at??date("Y-m-d H:i:s");
            $val->updated_at_str = $val->updated_at??date("Y-m-d H:i:s");
            unset($val->created_at,$val->updated_at);
        }
        $data->GdProducts = $GdProducts;
        $data->GdExpressOrders = $GdExpressOrders;
        */

        # 调用curl，发送消息到
        $url = env("EXTERNAL_CRM_HTTP_URL")."/crm/PurchaseOrder/ReExpressOrderAddSyncCrm";
        $output = \App\Ok\Curl::getInstance()->curlPost($url, $data);

    }

    #统计采购明细单的数据
    public function purchaseExpressQty(array $pod_ids)
    {
        if(empty($pod_ids)) return;
        foreach ($pod_ids as $obj){
            $eo = DB::table("gd_express_order")
                ->selectRaw('sum(quantity) quantity')->where("status",1)->where('purchaseorder_detailed_id',$obj)->first();
            $pod = DB::table("purchaseorder_detailed")->select("Purchaseorder_detailed_id","Qtynumber")->where('purchaseorder_detailed_id',$obj)->first();

            $quantity = 0;
            if($eo->quantity ==0){
                $state = 1;
            }else if($pod->Qtynumber == $eo->quantity){
                $state = 3;
                $quantity = $eo->quantity;
            }else{
                $state = 2;
                $quantity = $eo->quantity;
            }
            DB::table("purchaseorder_detailed")->where('purchaseorder_detailed_id',$obj)->update(["gd_express_Qty"=>$quantity,"exp_state"=>$state]);
        }
    }
}


