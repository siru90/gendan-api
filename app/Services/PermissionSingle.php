<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;

class PermissionSingle extends BaseService
{
    protected string $table = 'gd_permission_single';

    //批量保存
    public function batchSave(array $addParam):int|bool
    {
        if(empty($addParam)) return true;
        $affect = 0;
        $this->db->beginTransaction();
        try {
            #批量新增
            if(count($addParam)){
                $affect = $this->tb->insert($addParam);
            }
            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //批量删除：按ID删除
    public function delByIDs(array $ids):int
    {
        return $this->tb->whereIn("permission_id",$ids)->update(["status"=>-1]);
    }

    //批量删除：按PI,PO组合删除
    public function delByPis(array $args):int
    {
        $affect = 0;
        $this->db->beginTransaction();
        try {

            foreach ($args as $obj){
                $res =  $this->tb->where("purchaser_id",$obj["purchaser_id"])
                    ->where("shared_user_id",$obj["shared_user_id"])
                    ->where("order_id",$obj["order_id"])
                    ->where("purchaseorder_id",$obj["purchaseorder_id"])
                    ->update(["status"=>-1]);

                $affect += $res;
            }
            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function getById(int $id):object|null
    {
        return $this->tb->where("permission_id",$id)->where("status",1)->first();
    }

    public function getExpressPermission(int $expressId,int $purchaserId,int $shareId):object|null
    {
        return $this->tb->select("shared_user_id")
            ->where("express_id",$expressId)
            ->where("purchaser_id",$purchaserId)
            ->where("shared_user_id",$shareId)
            ->where("type",1)
            ->where("status",1)
            ->first();
    }

    //登录的采购，给某个采购单独分享快递的权限
    public function getExpressByPurcharseId(int $purcharseId, int $shareId)
    {
        return $this->tb->select("express_id")->distinct()
            ->where("purchaser_id",$purcharseId)
            ->where("shared_user_id",$shareId)
            ->where("type",1)  # 1快递；2PI
            ->where("status",1)
            ->pluck("express_id")  //purchaser_id
            ->toArray();
    }

    //分享的用户，能看到哪些快递ID,
    public function getExpressByShareId(int $shareId, array $purcharseIds):array
    {
        return $this->tb->select("express_id")->distinct()
            ->where("shared_user_id",$shareId)
            ->whereNotIn("purchaser_id",$purcharseIds) #过滤了全局分享的采购ID
            ->where("type",1)
            ->where("status",1)
            ->pluck("express_id")  //purchaser_id
            ->toArray();
    }

    //某个采购的所有分享Pi
    public function getExpressShare(int $purchaser_id)
    {
        return $this->tb->select("express_id")->distinct()
            ->where("purchaser_id",$purchaser_id)
            ->where("type",1)
            ->where("status",1)
            ->pluck("express_id")
            ->toArray();
    }

    //获取异常pi单下的异常快递ID
    public function getExpressByOrder(int $shareId)
    {
        $podIds = $this->tb->select("purchaseorder_detailed_id")->distinct()
            ->where("shared_user_id",$shareId)
            ->where("type",2)
            ->where("status",1)
            ->pluck("purchaseorder_detailed_id")
            ->toArray();


        $expressIds = DB::table("gd_express_delivery as d")->select("d.id")
            ->join("gd_express_order as o", "o.express_id","d.id")
            ->distinct()
            ->where("o.purchaseorder_detailed_id",$podIds)
            ->whereIn("d.abnormal_status",[1,2,3,4])
            ->where("d.status",1)
            ->pluck("d.id")
            ->toArray();
        return $expressIds;
    }

    //查询当前采购给某个采购的分享记录
    public function getPiByPurcharseId(int $purcharseId, int $shareId, string $pluck):array
    {
        return $this->tb->select($pluck)->distinct()
            ->where("shared_user_id",$shareId)
            ->where("purchaser_id",$purcharseId)
            ->where("type",2)
            ->where("status",1)
            ->pluck($pluck)
            ->toArray();
    }

    //
    public function getPiByShareId(int $shareId, array $purcharseIds, string $pluck):array
    {
        return $this->tb->select($pluck)->distinct()
            ->where("shared_user_id",$shareId)
            ->whereNotIn("purchaser_id",$purcharseIds)
            ->where("type",2)
            ->where("status",1)
            ->pluck($pluck)
            ->toArray();
    }

    //
    public function getPiPurchaserId(int $orderId,string $pluck="purchaser_id"):array
    {
        return $this->tb->select($pluck)->distinct()
            ->where("order_id",$orderId)
            ->where("type",2)
            ->where("status",1)
            ->pluck($pluck)
            ->toArray();
    }

    public function getSiglePermiss($order_info_id,$podId,$shareId):object|null
    {
        return $this->tb->where("order_info_id",$order_info_id)->where("purchaseorder_detailed_id",$podId)->where("shared_user_id",$shareId)
            ->where("status",1)
            ->first();
    }

    //查看采购有没有分享某个pod
    public function getPodShare(int $purchaser_id, int $purchaseorder_detailed_id):array|null
    {
        return $this->tb->where("purchaser_id",$purchaser_id)->where("purchaseorder_detailed_id",$purchaseorder_detailed_id)
            ->where("type",2)->where("status",1)->get()->toArray();
    }

    //某个采购的所有分享Pi
    public function getPurchaseShare(int $purchaser_id,string $pluck)
    {
        return $this->tb->select($pluck)->distinct()
            ->where("purchaser_id",$purchaser_id)
            ->where("type",2)
            ->where("status",1)
            ->pluck($pluck)
            ->toArray();
    }

    //某些采购对order_id分享的用户ID
    public function getOrderIdShareUser(array $purchaser_ids,int $order_id,string $pluck)
    {
        return $this->tb->select($pluck)->distinct()
            ->whereIn("purchaser_id",$purchaser_ids)
            ->where("order_id",$order_id)
            ->where("type",2)
            ->where("status",1)
            ->pluck($pluck)
            ->toArray();

    }

    //某些采购对express_id分享的用户ID
    public function getExpressIdShareUser(array $purchaser_ids,int $express_id,string $pluck)
    {
        return $this->tb->select($pluck)->distinct()
            ->whereIn("purchaser_id",$purchaser_ids)
            ->where("express_id",$express_id)
            ->where("type",1)
            ->where("status",1)
            ->pluck($pluck)
            ->toArray();

    }


    //权限列表
    public function permissionList($args):array
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);
        $tb = $this->tb;
        if(empty($args["type"])){  #整体
            $tb = $tb->where("purchaser_id",$args["user_id"])->where("status",1);  //shared_user_id
        }
        if(!empty($args["type"]) && $args["type"]==2){  #查快递
            $tb = $tb->where("type",1)->where("purchaser_id",$args["user_id"])->where("status",1)->orderByDesc('permission_id');
        }
        $totalTb = clone $tb;
        $tb = $tb->offset($offset)->limit($args["size"]);
        $list = $tb->get()->toArray();
        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;


        if(!empty($args["type"]) && $args["type"]==1)  #查PI的分享，子查询
        {
/*            $args["offset"] = $offset;
            $tb = DB::table('gd_permission_single')
                ->select('sub.purchaser_id','sub.order_id', 'sub.purchaseorder_id')
                ->fromSub(function ($query,$args) {
                    $this->tb->select('purchaser_id', 'order_id', 'purchaseorder_id')
                        ->from('gd_permission_single')
                        ->where("purchaser_id",$args["user_id"])->where("status",1)->where("order_id","!=",0)
                        ->offset($args["offset"])->limit($args["size"]);
                },"sub")
                ->groupBy(['sub.purchaser_id','sub.order_id', 'sub.purchaseorder_id']);*/


            $sql = "SELECT sub.purchaser_id, sub.order_id, sub.purchaseorder_id,sub.shared_user_id
                    FROM (
                        SELECT purchaser_id, order_id, purchaseorder_id,shared_user_id
                        FROM gd_permission_single
                        WHERE purchaser_id = ? AND status = 1 AND type =2
                        GROUP BY purchaser_id, order_id, purchaseorder_id,shared_user_id
                    ) AS sub
                    LIMIT ? OFFSET ?";


            //GROUP BY sub.purchaser_id, sub.order_id, sub.purchaseorder_id

            $list = DB::select($sql, [$args["user_id"],$args["size"],$offset]);
            //var_dump($list);


            $sql = "SELECT COUNT(*) as count
                    FROM (
                        SELECT purchaser_id, order_id, purchaseorder_id
                        FROM gd_permission_single
                        WHERE purchaser_id = ? AND status = 1 AND order_id != 0
                    ) AS sub
                    GROUP BY sub.purchaser_id, sub.order_id, sub.purchaseorder_id";


            $totalResult = DB::select($sql, [$args["user_id"]]);
            $total = 0;
            if(!empty($totalResult)){
                $totalResult = $totalResult[0];
                $total = $totalResult->count ?? 0;
            }
        }

        //var_dump($list,$total);

        return [$list, $total];
    }


}
