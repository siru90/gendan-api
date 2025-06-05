<?php

namespace App\Services\M;

use Illuminate\Support\Facades\DB;

class OrdersItemInfo extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'orders_item_info';

    public function updateOrdersItem($id,$values):int
    {
        return $this->tb->where("enable",1)->where('order_info_id', $id)->update($values);
    }

    public function getOrderItems($orderId): array
    {
        return $this->tb->where('order_id', $orderId)->where("enable",1)->get()->toArray();
    }

    public function getOrderItemsF1($orderId): array
    {
        # orders_item_info表State字段：1未分配订货，2预定货，3待收货，4待分配发货，5部分分配发货，6已分配发货，7已签收，8部分订货，9部分到货、10全部到货
        return $this->tb
            ->select([
                "order_id",
                'order_info_id',
                'quantity',
                'product_name_pi',
                'product_id',
                'State',
                'ShipQty',
                'Brand_id',
                'Brand_name',
                'OrdertaskQty',
                'SendQty',
            ])
            ->where('order_id', $orderId)->where("enable",1)->get()->toArray();
    }

    public function getByIdAS($id): object|null
    {
        return $this->tb->select([
            "order_info_id",
            "order_id",
            "product_name_pi",
            "product_id",
            "quantity",
            "leadingtime",
            "Brand_id",
            "Brand_name",
            "weight",
            "Purchaser_id",
            "weight_unit",
            "ShipQty"
        ])->where('order_info_id', $id)->where("enable",1)->first();
    }

    public function getByOrderId($orderId, $productId): object|null
    {
        $res = \Illuminate\Support\Facades\Redis::command("get", ["gd_orderItembyProductId_".$orderId."_".$productId]);
        $res = json_decode($res);
        if(empty($res)){
            $res = $this->tb->from("orders_item_info as i")
                ->select([
                    "order_info_id","i.order_id","PI_name","Sales_User_ID","i.quantity"
                ])
                ->join("orders", "orders.order_id","i.order_id")
                ->where('i.order_id', $orderId)
                ->where("i.product_id", $productId)
                ->where("i.enable",1)
                ->first();
            \Illuminate\Support\Facades\Redis::command("set", ["gd_orderItembyProductId_".$orderId."_".$productId, json_encode($res), ['EX' => 3600 * 24]]);
        }

        /*return $this->tb->from("orders_item_info as i")
            ->select([
                "order_info_id","i.order_id","PI_name","Sales_User_ID","i.quantity"
            ])
            ->join("orders", "orders.order_id","i.order_id")
            ->where('i.order_id', $orderId)
            ->where("i.product_id", $productId)
            ->where("i.enable",1)
            ->first();*/
        return $res;
    }

    public function getByIdF1($id)
    {
        return $this->mtb->where('order_info_id', $id)->where("enable",1)->value('order_id');
    }

    public function getByInfoIds(array $ids):array
    {
        return $this->tb->select([
            "order_id","order_info_id","product_id","product_name_pi"
        ])->whereIn('order_info_id', $ids)->where("enable",1)->get()->toArray();
    }

    public function getInfoByCrmOrderInfoId($crm_order_info_id):object|null
    {
        if (empty($crm_order_info_id)) return null;
        return $this->tb->select("order_id","order_info_id")->where("crm_order_info_id",$crm_order_info_id)->where("enable",1)->first();
    }

    //根据采购单ID，返回所有明细ID
    public function returnOrderInfoIds($id):array
    {
        return $this->tb->select('order_info_id')->where('order_id', $id)->pluck("order_info_id")->toArray();
    }


    //根据用户查询能看到的pi型号
    public function getItemByUserId(int $order_id, array $user_id=null):array
    {
        $infoIds = [];
        if($user_id){
            #从销售订单里查找
            $allIds = $this->returnOrderInfoIds($order_id);

            /*$infoIds = DB::table("orders_item_info")
                ->select("order_info_id")
                ->whereIn("order_info_id",$allIds)->where("Purchaser_id",$user_id)->pluck("order_info_id")->toArray();
            */

            #得到infoIds
            $tb2 = DB::table("purchaseordertesk as t")
                ->select("d.order_info_id")
                ->join("purchaseordertesk_detailed as d", "d.Purchaseordertesk_id","t.Purchaseordertesk_id")
                ->where("t.order_id",$order_id);
            //if(is_array($user_id)){
                $tb2 = $tb2->whereIn("d.Purchaser_id",$user_id);
            //}

            /*$sql = str_replace('?','%s', $tb2->toSql());
            $sql = sprintf($sql, ...$tb2->getBindings());
            var_dump($sql);*/

            $infoIds = $tb2->pluck("d.order_info_id")->toArray();
            unset($tb2);
        }

        $tb = $this->tb->select([
            'order_id', "order_info_id", "product_id","product_name_pi","quantity","Purchaser_id","leadingtime"
        ])
            ->where('order_id',$order_id)->where("enable",1);
        #没有采购任务情况下，显示全部的明细
        if($infoIds){
            $tb = $tb->whereIn("order_info_id",$infoIds);
        }else if($user_id){
            $tb = $tb->whereIn("Purchaser_id",$user_id);
        }

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        return $tb->get()->toArray();
    }

}
