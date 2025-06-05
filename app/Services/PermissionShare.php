<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;

class PermissionShare extends BaseService
{
    protected string $table = 'gd_permission_share';


    public function save(array $addParam, array $delParam):int|bool
    {
        $affect1 = $affect2 = 0;
        $this->db->beginTransaction();
        try {
            #批量新增
            if(count($addParam)){
                $affect1 = $this->tb->insert($addParam);
            }
            #批量删除
            if(count($delParam)){
                foreach ($delParam as $obj){
                    $affect2 = $this->delByPurchaserAndUser($obj["purchaser_id"],$obj["shared_user_id"]);
                }
            }
            $this->db->commit();
            return $affect1 || $affect2;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function getShareUserId(int $purchaser_id):array|null
    {
        return $this->tb->select("shared_user_id")
            ->where("purchaser_id",$purchaser_id)->where("status",1)
            ->orderByDesc("updated_at")
            ->pluck("shared_user_id")
            ->toArray();
    }

    public function getShareUser(int $purchaser_id):array
    {
        return $this->tb->from("gd_permission_share as p")
            ->select("p.shared_user_id","user.name")
            ->join("user", "user.id","p.shared_user_id")
            ->where("p.purchaser_id",$purchaser_id)->where("p.status",1)->get()->toArray();
    }

    public function getPurchaserId(int $shared_user_id):array
    {
        return $this->tb->select("purchaser_id")->where("shared_user_id",$shared_user_id)->where("status",1)->pluck("purchaser_id")->toArray();
    }

    //查询，按采购ID，分享用户ID
    public function getByPurchaserAndUser($purchaser_id, $shared_user_id):object|null
    {
        return $this->tb->where("purchaser_id",$purchaser_id)->where("shared_user_id",$shared_user_id)->where("status",1)->first();
    }

    //删除：按采购ID，分享用户ID查找
    public function delByPurchaserAndUser($purchaser_id, $shared_user_id):int
    {
        $data = $this->getByPurchaserAndUser($purchaser_id, $shared_user_id);
        $affect = 1;
        if($data){
            $affect = $this->tb->where("permission_share_id",$data->permission_share_id)->update(["status"=>-1]);
        }
        return $affect;
    }

    //批量删除
    public function batchDel(array $args):int|bool
    {
        if(empty($args)) return true;
        $affect = 0;
        $this->db->beginTransaction();
        try {
            foreach ($args as $obj){
                $affect = $this->delByPurchaserAndUser($obj["purchaser_id"],$obj["shared_user_id"]);
            }
            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }

    }


    //-----------

    //权限公用函数:
    public function expressPermission($share_user_id)
    {
        #获取全局分享的采购Id
        $purcharseIds = $this->getPurchaserId($share_user_id);

        #获取局部分享快递: 过滤全局的采购ID
        $expressIds = PermissionSingle::getInstance()->getExpressByShareId($share_user_id,$purcharseIds);

        $expressId = PermissionSingle::getInstance()->getExpressByOrder($share_user_id);
        $expressIds = array_merge($expressIds,$expressId);
        return [$purcharseIds,$expressIds];
    }

    //某个采购分享给$shared_user_id的
    public function expressShare(int $purchaser_id, int $shared_user_id):array
    {
        $purchase = $this->getByPurchaserAndUser($purchaser_id, $shared_user_id);

        $expressIds = PermissionSingle::getInstance()->getExpressByPurcharseId($purchaser_id,$shared_user_id);
        return [$purchase,$expressIds];
    }

    //采购自己的所有分享
    public function myShareExpress(int $purchaser_id):array
    {
        $shareUserId = $this->getShareUserId($purchaser_id);
        $expressIds = PermissionSingle::getInstance()->getExpressShare($purchaser_id);

        return [$shareUserId,$expressIds];
    }



    //PI权限: 分享给当前采购的全局分享，所有PI数据
    public function piPermission($share_user_id)
    {
        #获取全局分享的采购Id
        $purcharseIds = $this->getPurchaserId($share_user_id);

        #获取局部分享pi: 过滤全局的采购ID
        $piIds = PermissionSingle::getInstance()->getPiByShareId($share_user_id,$purcharseIds,"order_id");

        return [$purcharseIds,$piIds];
    }

    //查询order_Id,有没有分享给当前采购
    public function piPurchareseId($share_user_id,$order_id)
    {
        #获取全局分享的采购Id
        $purcharseIds = $this->getPurchaserId($share_user_id);

        #获取局部分享采购ID
        $Ids = PermissionSingle::getInstance()->getPiPurchaserId($order_id);

        return array_merge($purcharseIds,$Ids);
    }

    //查询采购，对某个order_id分享的$share_user_id
    public function orderIdShareUser(array $purchaser_ids,int $order_id):array
    {
        #获取全局分享的采购Id
        $shareUserId =  $this->tb->select("shared_user_id")->whereIn("purchaser_id",$purchaser_ids)->where("status",1)->pluck("shared_user_id")->toArray();

        $userIds = PermissionSingle::getInstance()->getOrderIdShareUser($purchaser_ids,$order_id,"shared_user_id");
        return array_merge($shareUserId,$userIds);
    }

    public function expressIdShareUser(array $purchaser_ids,int $express_id):array
    {
        #获取全局分享的采购Id
        $shareUserId =  $this->tb->select("shared_user_id")->whereIn("purchaser_id",$purchaser_ids)->where("status",1)->pluck("shared_user_id")->toArray();

        $userIds = PermissionSingle::getInstance()->getExpressIdShareUser($purchaser_ids,$express_id,"shared_user_id");
        return array_merge($shareUserId,$userIds);
    }

    //采购自己的所有分享
    public function myShare(int $purchaser_id):array
    {
        $shareUserId = $this->getShareUserId($purchaser_id);
        $piIds = PermissionSingle::getInstance()->getPurchaseShare($purchaser_id,"order_id");
        return [$shareUserId,$piIds];
    }

    //采购自己有没有分享某个Pod
    public function mySharePod(int $purchaser_id,int $purchaseorder_detailed_id)
    {
        //$shareUserId = $this->getShareUserId($purchaser_id);

        $list = PermissionSingle::getInstance()->getPodShare($purchaser_id,$purchaseorder_detailed_id);
        if(count($list)){  // count($shareUserId) ||
            return 1;
        }
        return 0;
    }

    //某个采购分享给$shared_user_id的
    public function purchaseShare(int $purchaser_id, int $shared_user_id)
    {
        $purchase = $this->getByPurchaserAndUser($purchaser_id, $shared_user_id);

        $piIds = PermissionSingle::getInstance()->getPiByPurcharseId($purchaser_id,$shared_user_id,"order_id");
        return [$purchase,$piIds];
    }


    public function commonUser(int $purchaser_id)
    {
        $shareUserId = $this->tb->select("shared_user_id","updated_at")
            ->where("purchaser_id",$purchaser_id)
            ->orderByDesc("updated_at")
            //->groupBy("shared_user_id")
            ->distinct()
            ->limit(6)
            ->get()
            ->toArray();
        $singleId = DB::table("gd_permission_single")
            ->select("shared_user_id","updated_at")
            ->where("purchaser_id",$purchaser_id)
            ->orderByDesc("updated_at")
            ->distinct()
            ->limit(6)
            //->groupBy("shared_user_id")
            ->get()
            ->toArray();

        if(empty($shareUserId) && empty($singleId)){
            return [];
        }
        else if(!empty($shareUserId) && empty($singleId)){
            return $shareUserId;
        }
        else if(empty($shareUserId) && !empty($singleId)){
            return $singleId;
        }else{

            $tmpShare = $res = [];
            foreach ($shareUserId as $obj){
                $tmpShare[$obj->shared_user_id] = $obj->updated_at;
            }
            foreach ($singleId as $obj){
                if(empty($tmpShare[$obj->shared_user_id])){  #不存在加进去
                    $tmpShare[$obj->shared_user_id] = $obj->updated_at;
                }else{  #存在，则比较时间
                    $tmpShare[$obj->shared_user_id] = strtotime($tmpShare[$obj->shared_user_id]) > strtotime($obj->updated_at) ? $tmpShare[$obj->shared_user_id] : $obj->updated_at;
                }
            }

            foreach ($tmpShare as $id=>$time){
                $tmp = new \stdClass();
                $tmp->shared_user_id = $id;
                $tmp->updated_at = $time;
                $res[] = $tmp;
            }
            return $res;
        }

    }
}
