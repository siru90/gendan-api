<?php
namespace App\Services\M;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class ShipTask extends \App\Services\BaseService
{
    protected ?string $connection = 'mysql_master';

    protected string $table = 'shiptask';

    public function getByOrderId(int $order_id, $state=null): array
    {
        $tb = $this->tb->select([
            "Shiptask_id",
            "Shiptask_name",
            "order_id",
            "Sales_User_ID",
            "State",
            "create_time",
        ])
            ->where('order_id', "like", "%{$order_id}%")
            ->where("Enable", 0)
            ->orderByDesc("create_time");

        if($state){
            $tb = $tb->where("State",1);
        }
        return $tb->get()->toArray();
    }

    //获取PI已经发过几次货
    public function getShipTaskCount(int $order_id):int
    {
        $totalResult = $this->tb->selectRaw('count(Shiptask_id) count')->where('order_id', "like", "%{$order_id}%")->where("Enable", 0)->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //获取待处理数量，待发货的状态
    public function getUnConfirmedCount($user_id): int
    {
        $tb = $this->tb->from("shiptask as t")
            ->selectRaw('COUNT(DISTINCT t.Shiptask_id) count')
            ->where("t.Enable",0)->where("t.State",1)->orderByDesc('t.Shiptask_id');

        if(!empty($user_id)){
            $tb->where(function ($query) use ($user_id,$tb){
                $tb = $query->whereIn('t.Sales_User_ID', $user_id)
                    ->orWhereIn("i.Purchaser_id", $user_id);
            });
            $tb = $tb->join("shiotask_item as i", "i.Shiptask_id", "t.Shiptask_id");
        }

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //获取SO基本信息
    public function getByIdF1(int $id): ?object
    {
        return $this->tb
            ->select([
                'Shiptask_id',
                'Shiptask_name',
                'order_id',
                'shipping_way',
                'Shiptask_delivery_Singlenumber',
                'Shitask_turn_delivery_Singlenumber',
                'State',
                'remarks',
                'crm_shiptask_id',
                'Purchaseorder_id',
                'check_weight',
                'pack_weight',
                'Sales_User_ID',
                'pack_user_id',
            ])
            ->where('Shiptask_id', $id)->where("Enable", 0)->first();
    }

    //更新
    public function updateShipTask(int $so_id,$value)
    {
        return $this->tb->where('Shiptask_id', $so_id)->update($value);
    }

    //更新
    public function updateByCrmShiptaskId(int $crm_shiptask_id,$value)
    {
        if(empty($value["Shiptask_id"])){unset($value["Shiptask_id"]);}
        return $this->tb->where('crm_shiptask_id', $crm_shiptask_id)->update($value);
    }

    //更新状态，同步子表状态
    public function updateState(int $so_id, array $arr): int|bool
    {
        if (!empty($arr["so_id"])) unset($arr["so_id"]);

        $this->db->beginTransaction();
        try {
            $affect = $this->tb->where('Shiptask_id', $so_id)->update($arr);
            $tmp = [];
            $tmp["State"] = $arr["State"];
            if(isset($arr["crm_shiptask_id"])) $tmp["crm_shiptask_id"] = $arr["crm_shiptask_id"];
            DB::table("shiotask_item")->where("Shiptask_id", $so_id)->update($tmp);
            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //数据同步 更新状态，同步子表状态
    public function updateStateByCrmSOID(int $crm_shiptask_id, array $arr): int|bool
    {
        $this->db->beginTransaction();
        try {
            $affect = $this->tb->where('crm_shiptask_id', $crm_shiptask_id)->update($arr);

            $tmp = ["State"=>$arr["State"]];
            DB::table("shiotask_item")->where("crm_shiptask_id", $crm_shiptask_id)->update($tmp);

            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //更新crm_shiptask_id, crm_shiptask_item_id
    public function updateCrmIds($soParam,$itemParam)
    {
        //$itemParam = [["ShioTask_item_id"=>","crm_shiptask_id" =>"","crm_shioTask_item_id"=>""],...]
        $this->db->beginTransaction();
        try {
            #更新主表
            $affect = $this->updateShipTask($soParam["Shiptask_id"], ["crm_shiptask_id"=>$soParam["crm_shiptask_id"]]);

            #批量更新
            if(!empty($itemParam)){
                $ids = [];
                $sql = "update shiotask_item set crm_shiptask_id = CASE ShioTask_item_id \n";
                $sql2 = " crm_shiptask_item_id = CASE ShioTask_item_id \n";
                foreach ($itemParam as $obj){
                    $ids[] = $obj['ShioTask_item_id'];
                    $sql .= sprintf("when %d then %d \n", $obj['ShioTask_item_id'],$obj['crm_shiptask_id']);
                    $sql2 .= sprintf("when %d then %d \n", $obj['ShioTask_item_id'],$obj['crm_shioTask_item_id']);
                }
                $sql .= " END,\n {$sql2} END \n";
                $sql .= "where ShioTask_item_id in(". implode(",", $ids).")";
                $affect2 = DB::statement($sql);
            }
            if($affect || $affect2){
                $affect = true;
            }

            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // soList
    public function soList(array $args):array
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);

        $tb = $this->tb->from("shiptask as t")
            ->where("t.Enable",0)
            ->orderByDesc('t.Update_tiem');

        $tb = $tb->where(function ($query) use ($args,$tb){
            if (!empty($args["sales_id"])) {
                $tb = $query->where('t.Sales_User_ID', $args["sales_id"]);
            }
            if (!empty($args["purchaser_id"])) {
                $tb = $query->where('i.Purchaser_id', $args["purchaser_id"]);
            }
            if (isset($args["order_item_id"]) && count($args["order_item_id"])) {
                $tb = $query->whereIn('i.order_info_id', $args["order_item_id"]);
            }
            if(!empty($args["keyword"])){
                if($args["keyword_type"] == "model"){
                    $tb = $tb->where("i.Model","like","%{$args["keyword"]}%");
                }
                else if($args["keyword_type"] == "so"){
                    $tb = $query->where("t.Shiptask_name", "like", "%{$args["keyword"]}%");
                }
            }
            if(!empty($args["user_id"])){
                $tb->where(function ($query) use ($args,$tb){
                    $tb = $query->whereIn('t.Sales_User_ID', $args["user_id"]);
                        //->orWhereIn("i.Purchaser_id", $args["user_id"]);
                });
            }
            if(!empty($args["shiptask_id"])){
                $tb = $tb->whereIn('t.Shiptask_id', $args["shiptask_id"]);
            }
            if(!empty($args["state"])){
                $tb = $tb->where('t.State', $args["state"]);
            }
            if (!empty($args["start_time"]) && !empty($args["end_time"])) {
                $tb = $query->whereBetween('t.create_time', [$args["start_time"],$args["end_time"]]);
            }
            if(!empty($args["so_status"])){
                if($args["so_status"] ==1) $tb = $tb->where('ss.zw_gd_status', 0);
                else if($args["so_status"] ==2) $tb = $tb->where("ss.zw_gd_status",1)->where('ss.zw_kd_status', 0);
                else if($args["so_status"] ==3) $tb = $tb->where('ss.zw_kd_status', 1)->whereIn('ss.db_status', [0,1,2]);
                else $tb = $tb->where('ss.db_status', 3);
                $tb = $tb->where("t.State",1)->join("gd_so_status as ss", "ss.so_id", "t.Shiptask_id");
            }
        });

        $condition = (!empty($args["keyword_type"] ) && $args["keyword_type"] == "model");
        if($condition || !empty($args["purchaser_id"]) || !empty($args["order_item_id"])){
            $tb = $tb->join("shiotask_item as i", "i.Shiptask_id", "t.Shiptask_id");
        }



        $totalTb = clone $tb;
        $tb = $tb->select([
            't.Shiptask_id',
            't.Shiptask_name',
            't.order_id',
            't.Purchaseorder_id',
            't.shipping_way',
            't.Sales_User_ID',
            't.Country_id',
            't.State',
            't.create_user',
            't.Shiptask_delivery_Singlenumber',
            't.Shitask_turn_delivery_Singlenumber',
            't.create_time',
            't.Update_tiem',
        ])
            ->distinct()
            ->offset($offset)->limit($args["size"]);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);
        die;*/

        $list = $tb->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT t.Shiptask_id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    //根据外部系统发货ID查找
    public function getIdByCrmShipTaskId(int $crm_shiptask_id):object|null
    {
        return $this->tb->select("Shiptask_id")->where("crm_shiptask_id", $crm_shiptask_id)->first();
    }

    //获取外部系统发货ID中，order_id为0的数据
    public function getOderIdIsZero($args)
    {
        $tb = $this->tb->select("Shiptask_id","crm_shiptask_id","crm_order_id","order_id")->where("Enable",0)->where("crm_shiptask_id","!=",0);
        if(isset($args["order_id"])){
            $tb = $tb->where("order_id",$args["order_id"]);
        }
        return $tb->get()->toArray();
    }

    //同步外部系统SO到内部系统
    public function syncShipTask(array $shiptask,$shiptaskItem):bool|array
    {
        $itemID = [];
        $this->db->beginTransaction();
        try {
            $res = ShipTask::getInstance()->getIdByCrmShipTaskId($shiptask["crm_shiptask_id"]);
            if($res){
                $id = $res->Shiptask_id;
                ShipTask::getInstance()->updateShipTask($id, $shiptask);
            }else{
                $id = $this->tb->insertGetId($shiptask);  //id不是19位
            }
            foreach ($shiptaskItem as $obj){
                $obj["Shiptask_id"] = $id;
                $itemId = DB::table("shiotask_item")->insertGetId($obj);
                $itemID[] = [
                    "Shiptask_id"=>$id,
                    "ShioTask_item_id"=>$itemId,
                    "crm_shiptask_item_id"=>$obj["crm_shiptask_item_id"],
                    "crm_shiptask_id"=>$obj["crm_shiptask_id"],
                ];
            }
            $this->db->commit();
            return [$id,$itemID];
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            Log::channel('sync')->info('[createShipTask] Throwable $e: '.$e->getMessage().'file:'.$e->getFile().$e->getLine());
            return false;
        }
    }


    //PI发货:新建发货单
    public function createShipTask(array $shiptask,$shiptaskItem):bool|int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->tb->insertGetId($shiptask);  //id不是19位
            foreach ($shiptaskItem as $obj){
                $orderItem = OrdersItemInfo::getInstance()->getByIdAS($obj["order_info_id"]);
                $ShipQty = $obj["Qtynumber"] + $orderItem->ShipQty;
                $ShipQty = ( $ShipQty > $orderItem->quantity) ? $orderItem->quantity: $ShipQty;
                $state = ($ShipQty == $orderItem->quantity) ? 6:5;   #5部分分配发货，6已分配发货

                $obj["Shiptask_id"] = $id;
                DB::table("shiotask_item")->insertGetId($obj);

                #更新orders_item_info的发货数量
                $sql = "UPDATE orders_item_info SET ShipQty=?,State=? WHERE order_info_id=?";
                //DB::statement($sql);
                DB::update($sql,[$ShipQty,$state,$obj["order_info_id"]]);
            }
            $this->db->commit();
            return $id;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            Log::channel('sync')->info('[createShipTask] Throwable $e: '.$e->getMessage().'file:'.$e->getFile().$e->getLine());
            return false;
        }
    }

    //PI发货，合并发货单,$shiptaskItem新增的合并型号，$shiptaskItemUpdae需要更新的型号
    public function mergeShipTask(array $shiptask,$shiptaskItem,$shiptaskItemUpdae):bool|int
    {
        if(empty($shiptaskItem) && empty($shiptaskItemUpdae)) return false;
        try {
            $affect = $this->tb->where('Shiptask_id', $shiptask["Shiptask_id"])->update($shiptask);  //没去掉主键看有没有问题？
            if(!empty($shiptaskItem)){  //新增
                foreach ($shiptaskItem as $obj){
                    $orderItem = OrdersItemInfo::getInstance()->getByIdAS($obj["order_info_id"]);
                    $ShipQty = $obj["Qtynumber"] + $orderItem->ShipQty;
                    $ShipQty = ( $ShipQty > $orderItem->quantity) ? $orderItem->quantity: $ShipQty;
                    $state = ($ShipQty == $orderItem->quantity) ? 6:5;   #5部分分配发货，6已分配发货

                    $obj["Shiptask_id"] = $shiptask["Shiptask_id"];
                    DB::table("shiotask_item")->insertGetId($obj);

                    #更新orders_item_info的发货数量
                    $sql = "UPDATE orders_item_info SET ShipQty=?,State=? WHERE order_info_id=?";
                    DB::update($sql,[$ShipQty,$state,$obj["order_info_id"]]);
                }
            }
            if(!empty($shiptaskItemUpdae)){  //更新
                foreach ($shiptaskItemUpdae as $obj){
                    $orderItem = OrdersItemInfo::getInstance()->getByIdAS($obj["order_info_id"]);
                    $ShipQty = $obj["Qtynumber"] + $orderItem->ShipQty;
                    $ShipQty = ( $ShipQty > $orderItem->quantity) ? $orderItem->quantity: $ShipQty;
                    $state = ($ShipQty == $orderItem->quantity) ? 6:5;   #5部分分配发货，6已分配发货

                    #更新orders_item_info的发货数量
                    $sql = "UPDATE orders_item_info SET ShipQty=?,State=? WHERE order_info_id=?";
                    DB::update($sql,[$ShipQty,$state,$obj["order_info_id"]]);
                    unset($obj["newQtynumber"]);

                    DB::table("shiotask_item")->where('ShioTask_item_id', $obj["ShioTask_item_id"])->update($obj); //没去掉主键看有没有问题？
                }
            }
            $this->db->commit();
            return $affect;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }


    //PI发货，获取需同步的数据
    public function getSynchronization($id):object
    {
        return $this->tb->where('Shiptask_id', $id)->where("Enable", 0)->first();
    }

    //导出
    public function exportList($ids):array
    {
        return $this->tb->select(["Shiptask_id","Shiptask_name","pack_user_id","order_id","Country_id","shipping_way","Shiptask_delivery_Singlenumber"])
            ->whereIn('Shiptask_id', $ids)->where("Enable", 0)->get()->toArray();
    }

}
