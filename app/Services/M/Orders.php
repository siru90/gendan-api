<?php

namespace App\Services\M;

use Illuminate\Support\Facades\DB;

class Orders extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'orders';

    //
    public function updateOrder(int $id, array $values):int
    {
        if(isset($values['order_id']))  unset($values['order_id']);
        return $this->tb->where('order_id', $id)->update($values);
    }

    //获取待处理数量，即除了全部已分配发货状态的PI均为待处理，如已分配发货32/32为全部分配发货
    public function getUnConfirmedCount($user_id): int
    {
        $tb = $this->tb->from("orders as o")
                ->selectRaw('COUNT(DISTINCT i.order_id) count')
                ->join("orders_item_info as i", "i.order_id","o.order_id")
                ->where("o.enable",1)->where("i.ShipQty","!=", "i.quantity");

        if(!empty($user_id)){
            $tb = $tb->whereIn('u.id', $user_id);
            $tb = $tb->join("user as u", "u.id","o.Sales_User_ID");
        }


        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    public function getByIdAS($id): object|null
    {
        return $this->mtb->select([
            'order_id',
            'PI_name',
            'Sales_User_ID',
            'CreateTime',
            "order_remark",
        ])->where('order_id', $id)->where("enable",1)->first();
    }


    public function getByIdF1($id): object|null
    {
        return $this->mtb
            ->select('PI_name')
            ->where('order_id', $id)
            ->where("enable",1)
            ->first();
    }

    public function getByIdsF1(array $ids): array
    {
        if (empty($ids)) return [];
        return $this->mtb
            ->select([
                'order_id',
                'PI_name',
                'Sales_User_ID',
                'address_customer_info_id',
                "Customer_Seller_info_id",
            ])
            ->whereIn('order_id', $ids)->where("enable",1)
            ->orderByDesc("order_id")->get()->toArray();
    }

    public function returnOrderIds(array $args):array
    {
        $tb = $this->tb;
        if(!empty($args["sales_user_id"])){
            $tb = $tb->where("Sales_User_ID",$args["sales_user_id"]);
        }
        if(!empty($args["keyword"])){
            $tb = $tb->where("PI_name", "like", "%{$args["keyword"]}%");
        }
        $data = $tb->where("enable",1)->pluck("order_id")->toArray();
        return $data;
    }



    //分页查询:销售订单
    public function getOrderList(array $args)
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);

        $tb = $this->tb->from("orders as o");
        $tb = $tb->where("o.enable",1);

        $tb = $tb->where(function ($query) use ($args,$tb){
            if(isset($args["Customer_Seller_info_id"])){
                $tb = $tb->whereIn('Customer_Seller_info_id', $args["Customer_Seller_info_id"]);
            }
            if(!empty($args['sales_id'])){
                $tb = $tb->where('Sales_User_ID', $args["sales_id"]);
            }
            if(!empty($args['Purchaser_id'])){
                $tb = $tb->where('orders_item_info.Purchaser_id', $args["Purchaser_id"]);
            }
            if (!empty($args["start_time"]) && !empty($args["end_time"])) {
                $tb = $tb->whereBetween('o.CreateTime', [strtotime($args["start_time"]),strtotime($args["end_time"])]);
            }
            if (!empty($args['state'])) {
                $tb = $tb->whereIn('orders_item_info.State', $args["state"]);
            }
            if(!empty($args["keyword"])){
                if($args["keyword_type"] == "model"){
                    $tb = $tb->where(function ($query) use ($args,$tb){
                        $tb = $query->where("orders_item_info.product_name_pi", "like", "%{$args["keyword"]}%");
                    });
                }else{
                    $tb = $tb->where(function ($query) use ($args,$tb){
                        $tb = $query->where("PI_name", "like", "%{$args["keyword"]}%");
                    });
                }
            }

            //PI的部分SO已发货,全部发货
            if(!empty($args["sosate"])){
                if($args["sosate"] ==1){
                    $tb = $tb->whereBetween('orders_item_info.SendQty',[1,DB::raw("orders_item_info.quantity")]);
                }
                else{
                    $tb = $tb->where('orders_item_info.SendQty',"=",DB::raw("orders_item_info.quantity"))->where("orders_item_info.quantity","!=",0);
                }
            }
        });
        $condition = (!empty($args["keyword_type"]) && $args["keyword_type"] == "model" );
        if(!empty($args['state']) || !empty($args['Purchaser_id']) || !empty($args["sosate"]) || $condition){
            $tb = $tb->join("orders_item_info", "o.order_id", "orders_item_info.order_id")->where("orders_item_info.enable",1);
        }
        if(!empty($args["user_id"])){
            $tb = $tb->whereIn('o.Sales_User_ID', $args["user_id"]);
            //$tb = $tb->whereIn('u.id', $args["user_id"]);
            //$tb = $tb->join("user as u", "u.id","o.Sales_User_ID");
        }

        $totalTb = clone $tb;

        $tb = $tb->select([
            "o.order_id",
            "o.PI_name",
            "o.Sales_User_ID",
            "o.Customer_Seller_info_id",
            "o.CreateTime",
        ])->distinct()->orderByDesc('order_id')->offset($offset)->limit($args["size"]);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        $list = $tb->get()->toArray();

        //var_dump($list);

        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT o.order_id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }


    public function searchOrderId(string $keyword):array|null
    {
        $tb = $this->tb->select("orders.order_id","order_info_id")
            ->join("orders_item_info", "orders_item_info.order_id","orders.order_id")
            ->where("orders_item_info.enable",1)
            ->where("orders.enable",1);

        if(!empty($keyword)){
            $tb = $tb->where("PI_name", "like", "%{$keyword}%");
        }
        return $tb->orderByDesc('orders.order_id')->get()->toArray();
    }

    public function getOrderIdByCrmOrderId(string $crm_order_id):object|null
    {
        if (empty($crm_order_id)) return null;
        return $this->tb->select("order_id")->where("crm_order_id",$crm_order_id)->first();
    }


    //----

    //pi核对销售列表
    public function getCheckSaleList(array $args)
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);

        $tb = $this->tb->from("orders as o");

        if(!empty($args["order_id"])){
            $tb = $tb->whereIn('o.order_id', $args["order_id"]);
        }
        if(!empty($args['user_id'])){
            $tb = $tb->where('o.Sales_User_ID', $args["user_id"]);
        }
        if(!empty($args['sales_id'])){
            $tb = $tb->whereIn('o.Sales_User_ID', $args["sales_id"]);
        }

        if(!empty($args["permission_and"]) && $args["permission_and"] == "or"){
            $tb = $tb->where(function ($query) use ($args,$tb){
                if(!empty($args['purchaser_id'])){
                    $query = $query->whereIn('p.Purchaser_id', $args["purchaser_id"]);
                }
                if(!empty($args["permission_order_id"])){
                    $query = $query->orWhereIn("i.order_id", $args["permission_order_id"]);
                }
            });
        }
        else if(!empty($args["permission_and"]) && $args["permission_and"] == "and"){
            $tb = $tb->where(function ($query) use ($args,$tb){
                $query = $query->whereIn('p.Purchaser_id', $args["purchaser_id"]);
                $query = $query->whereIn("i.order_id", $args["permission_order_id"]);
            });
        }
        else if(empty($args["permission_and"]) && !empty($args['purchaser_id'])){
            if(!empty($args["permission_order_id"])){
                $tb = $tb->where(function ($query) use ($args,$tb){
                    $tb = $query->whereIn('p.Purchaser_id', $args["purchaser_id"]);
                    $query = $query->orWhereIn("i.order_id", $args["permission_order_id"]);
                });
            }else{
                $tb = $tb->whereIn('p.Purchaser_id', $args["purchaser_id"]);
            }
        }
        if (!empty($args["start_time"]) && !empty($args["end_time"])) {
            $tb = $tb->whereBetween('o.CreateTime', [strtotime($args["start_time"]),strtotime($args["end_time"])]);
        }
        if(!empty($args["keyword"])){
            if($args["keyword_type"] == "model"){
                $tb = $tb->where(function ($query) use ($args,$tb){
                    $tb = $query->where("i.product_name_pi", "like", "%{$args["keyword"]}%");
                });
            }else{
                $tb = $tb->where("PI_name", "like", "%{$args["keyword"]}%");
            }
        }
        if(!empty($args["list_type"]) && $args["list_type"]==1){
            $tb = $tb->where(function ($query) use ($args,$tb){
                $tb = $query->where("o.status",3);
            });
        }else{
            $tb = $tb->where(function ($query) use ($args,$tb){
                $tb = $query->whereNull("o.status")->orWhereIn("o.status", [0,1,2]);
            });
        }

        //过滤没有po的pi
        //$tb = $tb->join("purchaseorder as p", "o.order_id", "p.order_id");
        $condtion = (!empty($args["keyword"]) && $args["keyword_type"] == "model");
        if( $condtion || !empty($args['permission_and'])) {
            $tb = $tb->join("orders_item_info as i", "o.order_id", "i.order_id");
        }
        if( !empty($args['purchaser_id'])) {
            $tb = $tb->join("purchaseorder_detailed as p", "o.order_id", "p.order_id");
        }

        $tb = $tb->where("o.enable",1);
        $totalTb = clone $tb;

        $tb = $tb->select([
            "o.order_id",
            "o.PI_name",
            "o.Sales_User_ID",
            "o.Customer_Seller_info_id",
            "o.CreateTime",
            "o.status"
        ])->distinct()->orderByDesc('o.order_id')->offset($offset)->limit($args["size"]);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        $list = $tb->get()->toArray();
        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT o.order_id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

}
