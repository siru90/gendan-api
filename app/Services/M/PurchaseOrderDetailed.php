<?php

namespace App\Services\M;

use Illuminate\Support\Facades\DB;
use \Illuminate\Support\Facades\Redis;

class PurchaseOrderDetailed extends \App\Services\BaseService
{
    protected ?string $connection = 'mysql_master';
    protected string $table = 'purchaseorder_detailed';

    public function updateModel(int $id, array $values): int
    {
        if(isset($values["purchaseorder_detailed_id"])) unset($values["purchaseorder_detailed_id"]);
        return $this->tb->where('purchaseorder_detailed_id', $id)->update($values);
    }

    public function updateByIds(array $ids, array $values): int
    {
        return $this->tb->whereIn('purchaseorder_detailed_id', $ids)->update($values);
    }

    public function updateModelByPoId(int $poId, array $values): int
    {
        return $this->tb->where('Purchaseorder_id', $poId)->update($values);
    }

    public function updateByOrderInfoId(int $orderInfoId, array $values): int
    {
        return $this->tb->from("purchaseorder_detailed as pod")
            ->join("purchaseordertesk_detailed as ptd", "ptd.Purchaseordertesk_detailed_id","pod.Purchaseordertesk_detailed_id")
            ->where('ptd.order_info_id', $orderInfoId)->update($values);
    }

    //根据采购单ID，返回所有明细ID
    public function returnPodIds($id):array
    {
        return $this->tb->select('Purchaseorder_detailed_id')->where('Purchaseorder_id', $id)->pluck("Purchaseorder_detailed_id")->toArray();
    }

    public function getByIdAS($id): object|null
    {
        return $this->tb->where('Purchaseorder_detailed_id', $id)->first();
    }

    public function getByCrmPodId($id):?object
    {
        if(empty($id)) return null;
        return $this->tb->select("Purchaseorder_detailed_id")->where('crm_purchasedetailed_id', $id)->first();
    }

    public function getInfo($id): object|null
    {

/*        $list = Redis::command("get", ["gd_coutry_list"]);
        $result->list = json_decode($list);
        unset($list);
        if(empty($result->list)){
            $result->list = \App\Services\M\Country::getInstance()->getCountries();
            Redis::command("set", ["gd_coutry_list", json_encode($result->list), ['EX' => 3600 * 24 * 7]]);
        }*/

        return $this->tb->select([
            "Purchaseorder_detailed_id",
            "Purchaseorder_id",
            "Purchaseordertesk_detailed_id",
            "products_id",
            "products_Name",
            "Model",
            "Qtynumber",
            "Brand",
            "Brand_name",
            "Comments",
            "inspect_status",
            "inspect_quantity",
            "confirm_status",
            "order_id",
            "order_info_id",  //内部系统purchaseorder_detailed表，没有 order_info_id 字段
            "shelf_position",
            "State",
            "Leading_name",
            "audit_quantity",
        ])
            ->where('Purchaseorder_detailed_id', $id)->first();
    }

    public function getByTaskIdAS(array $ids): array
    {
        return $this->tb->select("Purchaseorder_detailed_id","Purchaseorder_id","State","Qtynumber","audit_quantity")
            ->whereIn('Purchaseordertesk_detailed_id', $ids)->get()->toArray();
    }

    public function getByOrderId($Purchaseorder_id,$orderId):array
    {
        return $this->tb->from("purchaseorder_detailed as pod")
            ->select([
                "pod.Purchaseorder_detailed_id",
                "pod.Purchaser_id",
                "pod.Model",
                "pod.Qtynumber",
                "pod.State",
                "pod.Purchaseordertesk_detailed_id",
                "pot.order_info_id"
            ])
            ->join("purchaseordertesk_detailed as pot", "pot.Purchaseordertesk_detailed_id","pod.Purchaseordertesk_detailed_id")
            ->where("pod.Purchaseorder_id",$Purchaseorder_id)->where('pod.order_id', $orderId)->get()->toArray();
    }

    public function getByPoIdF1(int $purchaseorder_id,array $values=[]): array
    {
        $tb = $this->tb
            ->select([
                'Purchaseorder_detailed_id',
                'Purchaseorder_id',
                'Purchaseordertesk_detailed_id',
                'Leading_name',
                'products_Name',
                'Model',
                'products_id',
                'Qtynumber',
                'Purchaser_id',
                'order_id',
                'State',
                'confirm_status',
                'inspect_status',
                'inspect_quantity',
                'is_reject',
                "audit_quantity",
                "is_audit",
                "audit_status",
            ])
            ->where('Purchaseorder_id', $purchaseorder_id);
        return $tb->get()->toArray();
    }

    public function getByTaskIdF1($id): array
    {
        return $this->tb->select('Purchaser_id')->where('Purchaseordertesk_detailed_id', $id)->get()->toArray();
    }

    public function getPods($purchaseOrderId): array
    {
        return $this->tb->where('Purchaseorder_id', $purchaseOrderId)->get()->toArray();
    }

    public function getProducts(int $purchaseOrderId,string $model=""): array
    {
        $page = 1;$size = 20;
        $offset = max(0, ($page - 1) * $size);

        $tb = $this->tb
            ->select([
                "Purchaseorder_detailed_id",
                "Purchaseorder_id",
                "products_id",
                "products_Name",
                "Model",
                "Qtynumber",
                "confirm_status",
            ])
            ->where('Purchaseorder_id', $purchaseOrderId);

        if(!empty($model)){
            $tb = $tb->where("Model","like","%{$model}%");
        }
        return $tb->offset($offset)->limit($size)->get()->toArray();
    }

    //获取某个订单id下的产品id 对应的采购订单详情数据
    public function getPurchaseOrderByOrderId(int $order_info_id, int $productId, array $userId=null):array
    {
        $tb =  $this->tb->from("purchaseorder_detailed as d")->select([
            'o.purchaseorder_id',
            'o.Purchaseordername',
            'd.Purchaseorder_detailed_id',
            'd.order_id',
            'd.Qtynumber',
            'd.products_id',
            'd.products_Name',
            'd.Purchaser_id',
            'd.create_user',
            'd.Leading_name',
        ])
            ->join("purchaseordertesk_detailed as pot", "pot.Purchaseordertesk_detailed_id","d.Purchaseordertesk_detailed_id")
            ->join("purchaseorder as o", "o.Purchaseorder_id","=",'d.purchaseorder_id')
            ->where("pot.order_info_id",$order_info_id)
            ->where('d.products_id',$productId);

        $tb = $tb->where('o.State', "!=",9);  #过滤草稿的数据;

        if(!empty($userId)){
           $tb = $tb->whereIn("d.Purchaser_id",$userId);
        }

        return $tb->get()->toArray();
    }

    //根据采购订单详情id，获取对应的订单
    public function getOrderByPodId(int $PodId):object|null
    {
        $tb = $this->tb->select([
            'Purchaseorder_detailed_id',
            'purchaseorder_detailed.products_id',
            'Purchaseorder_id',
            'purchaseorder_detailed.Purchaseordertesk_detailed_id',
            'pot.order_info_id',
            'oii.order_id',
        ])
            ->join("purchaseordertesk_detailed as pot", "pot.Purchaseordertesk_detailed_id","purchaseorder_detailed.Purchaseordertesk_detailed_id")
            ->join("orders_item_info as oii", "oii.order_info_id","pot.order_info_id")
            ->where('purchaseorder_detailed.Purchaseorder_detailed_id',$PodId);

        return $tb->first();
    }


    //通过order_info_id，查采购订单信息
    public function getPurchaseOrderByOrderInfoId(int $orderInfoId):array|null
    {
        # 内部系统purchaseorder_detailed表，没有 order_info_id 字段
        return $this->mtb->from($this->table . " as pod")
            ->select([
                "pod.Purchaseorder_detailed_id",
                "pod.Purchaseorder_id",
                "pod.Purchaseordertesk_detailed_id",
                "pod.State",
                "o.Purchaseordername",
                //"o.is_audit",
                //"o.audit_status",
                "pod.products_id",
                "pod.products_Name",
                "pod.Qtynumber",
                "pod.Purchaser_id",
            ])
            ->join("purchaseorder as o", "o.Purchaseorder_id","pod.Purchaseorder_id")
            ->join("purchaseordertesk_detailed as ptd", "ptd.Purchaseordertesk_detailed_id","pod.Purchaseordertesk_detailed_id")
            ->where('ptd.order_info_id', $orderInfoId)->orderByDesc("pod.Purchaseorder_id")->get()->toArray();
    }


    public function updateWareArrivalTime($purchaseorder_id)
    {
        return $this->mtb->where('purchaseorder_id', $purchaseorder_id)
            ->update([
                'wareArrival_time' => time(),
            ]);
    }


    //根据用户权限查审核明细
    public function getByUserPodId(int $purchaseorder_id,array $args): array
    {
        $tb = $this->tb->from("purchaseorder_detailed as pod")
            ->select([
                'pod.Purchaseorder_detailed_id',
                'pod.Purchaseorder_id',
                'pod.Purchaseordertesk_detailed_id',
                'pod.Leading_name',
                'pod.products_Name',
                'pod.Model',
                'pod.products_id',
                'pod.Qtynumber',
                'pod.Purchaser_id',
                'pod.order_id',
                'pod.State',
                'pod.confirm_status',
                'pod.inspect_status',
                'pod.inspect_quantity',
                'pod.is_reject',
                "pod.audit_quantity",
                "pod.audit_status",
                "pod.is_audit",
                "pod.attach_exceptional",
                "pod.serial_quantity",
            ])
            ->where('Purchaseorder_id', $purchaseorder_id);

        if (!empty($args["sales_id"])) {
            $tb = $tb->whereIn('o.Sales_User_ID', $args["sales_id"]);
            $tb = $tb->join("orders as o", "o.order_id", "pod.order_id");
        }

        if(!empty($args["is_audit"]))
        {
            $tb = $tb->where('pod.is_audit', $args["is_audit"]);
        }
        return $tb->get()->toArray();
    }

    //
    public function getByOrderIds(array $orderIds, $purchaserId):array
    {
        return $this->tb->select("order_id","Purchaseorder_id")
            ->whereIn("order_id",$orderIds)->where("Purchaser_id",$purchaserId)
            ->distinct()->get()->toArray();
    }

    //
    public function getByPurchaseOderIds(array $poIds):array
    {
        return $this->tb->from("purchaseorder_detailed as pod")
            ->select("pod.order_id","pod.Purchaseorder_id","pod.Purchaseorder_detailed_id","pot.order_info_id")
            ->join("purchaseordertesk_detailed as pot", "pot.Purchaseordertesk_detailed_id","pod.Purchaseordertesk_detailed_id")
            ->whereIn("pod.Purchaseorder_id",$poIds)
            ->get()->toArray();
    }

}
