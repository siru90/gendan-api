<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ExpressOrders extends BaseService
{

    protected string $table = 'gd_express_order';


    public function addExpressOrder(array $values): int
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $values['status'] = 1;
        $this->tb->insert($values);
        return $id;
    }

    public function removeExpressOrder(int $id): int
    {
        return $this->tb->where('id', $id)->where('status', 1)->update(['status' => -1]);
    }

    public function updateExpressOrder(int $id, array $values):int
    {
        if(isset($values["id"])) unset($values["id"]);
        return $this->tb->where('id', $id)->update($values);
    }

    public function returnExpressId(array $args):array
    {
        $tb = $this->tb;
        if(!empty($args["purchaseorder_ids"])){
            $tb = $tb->whereIn("purchaseorder_id",$args["purchaseorder_ids"]);
        }
        if(!empty($args["order_ids"])){
            $tb = $tb->whereIn("order_id",$args["order_ids"]);
        }
        $tb = $tb->where("status",1);
        return $tb->distinct()->pluck("express_id")->toArray();
    }

    public function getByExpressNumber(string $number, array $args):array
    {
        $tb = $this->tb->from("gd_express_order as eo")
            ->select(["eo.order_id"])
            ->join("gd_express_delivery as e", "e.id","eo.express_id");

        if(!empty($args["user_id"])){
            if(is_array($args["user_id"])){
                $tb = $tb->whereIn("eo.user_id",$args["user_id"]);
            }
            if(is_int($args["user_id"])){
                $tb = $tb->where("eo.user_id",$args["user_id"]);
            }
        }
        if(!empty($args["order_id"])){
            $tb = $tb->whereIn("eo.order_id",$args["order_id"]);
        }

        $tb = $tb->where("e.tracking_number","like","%$number%")->where("eo.status",1);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        return $tb->get()->toArray();
    }

    //根据采购单，获取快递
    public function getExpress($args):array
    {
        $tb = $this->tb->from("gd_express_order as eo")
            ->select([
                "eo.user_id",
                "eo.purchaseorder_id",
                "eo.express_id",
                "eo.purchaser_id",
                "d.tracking_number",
                "d.channel_id",
                "c.expName as name",
                "d.sender_phone",
                "d.receiver_phone",
                "d.submit_status",
                "d.created_at",
                "d.note",
                "d.abnormal_status",
                "d.submit_purcharse",
            ])->distinct()
            ->join("gd_express_delivery as d", "d.id","eo.express_id")
            ->leftJoin("express_company as c", "c.express_id","d.channel_id")
            ->where("eo.status",1)
            ->where("d.status",1);

        if(!empty($args["purchaseorder_id"])){
            $tb = $tb->where("eo.purchaseorder_id",$args["purchaseorder_id"]);
        }
        if(!empty($args["order_id"])){
            $tb = $tb->where("eo.order_id",$args["order_id"]);
        }
        if(!empty($args["order_by"])){
            if($args["order_by"] == "abnormal_status"){
                $tb = $tb->orderByDesc("d.abnormal_status");
            }
        }

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $data = $tb->get()->toArray();
        foreach ($data as $obj){
            if(empty($obj->name)){
                $map = \App\Services\M\ExpressCompany::getInstance()->channelMap($obj->channel_id);
                $obj->name = $map->expName;
            }
        }
        return $data;
    }

    public function getExpressOrder(array $args):array|null
    {
        $tb = $this->tb->from("gd_express_order as eo")
            ->select([
                "eo.id",
                "eo.user_id",
                "eo.express_id",
                "eo.product_id",
                "eo.quantity",
                "eo.order_id",
                "eo.order_item_id",
                "eo.purchaseorder_id",
                "eo.purchaseorder_detailed_id",
                "eo.purchaser_id",
                "p.model",
                "d.tracking_number",
                "pod.gd_express_Qty",
            ])
            ->join("gd_express_delivery as d", "d.id","eo.express_id")
            ->join("gd_products as p", "p.id","eo.product_id")
            ->join("purchaseorder_detailed as pod", "pod.Purchaseorder_detailed_id","eo.purchaseorder_detailed_id")
            ->where("eo.status",1);
        if(!empty($args["pod_id"])){
            $tb->where("eo.purchaseorder_detailed_id",$args["pod_id"]);
        }
        if(!empty($args["purchaser_id"])){
            $tb->where("eo.purchaser_id",$args["purchaser_id"]);
        }
        if(!empty($args["user_id"])){
            $tb->where("eo.user_id",$args["user_id"]);
        }
        if(!empty($args["express_id"])){
            $tb->where("eo.express_id",$args["express_id"]);
        }
        if(!empty($args["order_id"])){
            $tb->where("eo.order_id",$args["order_id"]);
        }


/*        $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        return $tb->get()->toArray();
    }

    public function getExpressOrderByModel(int $pod_id, string $model):object|null
    {
        return $this->tb->from("gd_express_order as eo")
            ->select([
                "eo.id",
                "eo.product_id",
                "eo.express_id",
                "eo.quantity",
                "p.model",
                "eo.user_id",
            ])
            ->join("gd_products as p", "p.id","eo.product_id")
            ->where("purchaseorder_detailed_id",$pod_id)->where("model",$model)->where("eo.status",1)
            ->first();
    }

    public function getProductByExpressOrder(array $args):array
    {
        $tb = $this->tb->from("gd_express_order as eo")
            ->select([
                "eo.id",
                "eo.user_id",
                "eo.express_id",
                "eo.product_id",
                "eo.order_id",
                "eo.purchaser_id",
                "p.model",
                "p.actual_model",
                "p.quantity",
                "p.actual_quantity",
                "p.abnormal_status",
                "p.note",
                "p.purchase_note",
                "p.is_confirmed",
            ])
            ->join("gd_products as p", "p.id","eo.product_id")
            ->where("eo.status",1);

        if(!empty($args["express_id"])){
            $tb->where("eo.express_id",$args["express_id"]);
        }
        if(!empty($args["order_id"])){
            $tb->where("eo.order_id",$args["order_id"]);
        }

        return $tb->get()->toArray();
    }

    public function getByPorductId(int $productID):array
    {
        return $this->tb->where("product_id",$productID)->where("status",1)->get()->toArray();
    }

    //查找快递型号对应的PI单
    public function getExpressOrderByProductId(int $productID):array
    {
        $tb = $this->tb->from("gd_express_order as eo")->distinct()
            ->select([
                "eo.product_id",
                "eo.express_id",
                "eo.order_id",
                "eo.order_item_id",
                "o.PI_name",
                "o.Sales_User_ID",
            ])
            ->join("orders as o", "o.order_id","eo.order_id")
            ->where("eo.product_id",$productID)->where("eo.status",1);

       /* $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        return $tb->get()->toArray();
    }

    //获取采购详单对应的快递产品ID,只包含预交的
    public function getExpressOrderByPodID($podId): array
    {
        return $this->tb->from("gd_express_order as eo")
            ->select([
                'eo.product_id',
            ])
            ->where('eo.purchaseorder_detailed_id', $podId)->where('eo.status', 1)
            ->where("p.submit_status",3)
            ->join("gd_products as p", "p.id","eo.product_id")
            ->get()->toArray();
    }

    // 查找快递型号：PI单里的POd单，并统计所有的Pod单的数量
    public function getExpressPodByPi(int $order_item_id,$product_id):array
    {
        $tb = $this->tb->where("status",1)->where("order_item_id",$order_item_id)->where("product_id",$product_id);
        $totalTb = clone $tb;

        $list = $tb->from("gd_express_order as eo")
            ->select([
                "eo.id as express_order_id",
                "eo.quantity",
                "eo.submitted_quantity",
                "eo.purchaseorder_id",
                "eo.purchaseorder_detailed_id",
                "eo.purchaser_id",
                "po.Purchaseordername"
            ])
            ->join("purchaseorder as po", "po.purchaseorder_id","eo.purchaseorder_id")->get()->toArray();
        $totalResult = $totalTb->selectRaw('sum(quantity) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    public function getPurchaseOrderByExpressId(int $expressID):array|null
    {
        $list = $this->tb->from("gd_express_order as eo")
            ->select([
                "eo.purchaseorder_id",
                "po.Purchaseordername"
            ])
            ->join("purchaseorder as po", "po.purchaseorder_id","eo.purchaseorder_id")
            ->where("express_id",$expressID)->get()->toArray();

        return $list;
    }


    public function getSumQuantityByOrderItemID(int $order_item_id,$product_id):int
    {
        $tb = $this->tb->where("status",1)->where("order_item_id",$order_item_id)->where("product_id",$product_id);
        $totalResult = $tb->selectRaw('sum(quantity) count')->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    // 统计某个快递型号总的数量
    public function getSumQuantity(int $product_id):int
    {
        $totalResult = $this->tb->selectRaw('sum(quantity) count')->where("status",1)->where("product_id",$product_id)->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //统计实到数量，预交的数量
    public function getSumSumbmitQuantity(array $arg):int
    {
        $tb = $this->tb->from("gd_express_order as eo")->selectRaw('sum(eo.quantity) count')
            ->where("eo.status",1);

        if(!empty($arg["order_id"])){
            $tb = $tb->where("eo.order_id",$arg["order_id"]);
        }
        if(!empty($arg["order_item_id"])){
            $tb = $tb->where("eo.order_item_id",$arg["order_item_id"]);
        }
        if(!empty($arg["purchaseorder_id"])){
            $tb = $tb->where("eo.purchaseorder_id",$arg["purchaseorder_id"]);
        }
        if(!empty($arg["purchaseorder_detailed_id"])){
            $tb = $tb->where("eo.purchaseorder_detailed_id",$arg["purchaseorder_detailed_id"]);
        }
        if(!empty($arg["express_id"])){
            $tb = $tb->where("eo.express_id",$arg["express_id"]);
        }

        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //统计某个pod总的提交数(快递产品已预交的)
    public function getSumSubmittedQuantity(int $podId)
    {
        $tb = $this->tb->selectRaw('sum(gd_express_order.submitted_quantity) count')
            ->where("gd_express_order.status",1)
            ->where("purchaseorder_detailed_id",$podId)
            ->where("p.submit_status",3)
            ->join("gd_products as p", "p.id", "gd_express_order.product_id");

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //统计新快递某个型号的提交数
    public function getSumSubmitNum(array $expressProductId)
    {
        $tb = $this->tb->selectRaw('sum(gd_express_order.submitted_quantity) count')
            ->where("gd_express_order.status",1)
            ->whereIn("product_id",$expressProductId);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        var_dump($sql);*/

        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    // 设置提交数量=数量
    public function setSubmittedQuantity(int $product_id)
    {
        $sql = "UPDATE gd_express_order SET submitted_quantity = quantity WHERE status=1 and product_id=?";
        //DB::statement($sql);
        $res = DB::update($sql,[$product_id]);

        return $res;
    }

    public function setSubmittedQuantityF2(int $product_id,$quantity)
    {
        $sql = "UPDATE gd_express_order SET submitted_quantity = ". $quantity ." WHERE status=1 and product_id=?";
        $res = DB::update($sql,[$product_id]);
        return $res;
    }

    public function clearSubmittedQuantity(int $product_id){
        $sql = "UPDATE gd_express_order SET submitted_quantity = 0 WHERE status=1 and product_id=?";
        //DB::statement($sql);
        $res = DB::update($sql,[$product_id]);
    }

    //通过产品ID统计采购单个数
    public function countPodIDByProductId(int $product_id)
    {
        $totalResult = $this->tb->selectRaw('count(purchaseorder_detailed_id) count')->where("status",1)->where("product_id",$product_id)->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //
    public function getProductSubmitAndModel($arg)
    {
        $tb = $this->tb->select(
            'gd_express_order.id',
            'gd_express_order.product_id',
            'gd_express_order.quantity',
           // 'gd_express_order.submitted_quantity',
            'purchaseorder_id',
            'purchaseorder_detailed_id',
            'gd_express_order.order_item_id',
            "p.model",
        )
            ->join("gd_products as p", "p.id", "gd_express_order.product_id")
            ->where('gd_express_order.status', 1);

        if(!empty($arg["purchaseorder_id"])){
            $tb = $tb->where('purchaseorder_id', $arg["purchaseorder_id"]);
        }
        if(!empty($arg["product_id"])){
            $tb = $tb->where('gd_express_order.product_id', $arg["product_id"]);
        }
        if(!empty($arg["express_id"])){
            $tb = $tb->where('gd_express_order.express_id', $arg["express_id"]);
        }
        if(!empty($arg["order_id"])){
            $tb = $tb->where('gd_express_order.order_id', $arg["order_id"]);
        }

        return $tb->get()->toArray();
    }


    //获取采购详单对应有异常图片视频的
    public function getAttachmentByFlag(array $pod)
    {
        $tb = $this->tb->select(
            'gd_express_order.id as gd_express_order_id',
            'gd_express_order.purchaseorder_detailed_id',
            'gd_express_order.product_id',
            'a.id as gd_attachments_id',
            'a.flag',
            'a.type',
            'f.disk',
            'f.path',
        )
            ->join("gd_attachments as a", "a.product_id", "gd_express_order.product_id")
            ->join("gd_files as f", "f.id", "a.file_id")
            ->where('gd_express_order.status', 1)
            ->whereIn('purchaseorder_detailed_id', $pod)
            ->where("a.flag",0); //标记: 1正常,0异常

        return $tb->get()->toArray();
    }


    //根据快递统计应到数量
    public function getQuantityByExpressId(int $express_id):int
    {
        $totalResult = $this->tb->selectRaw('sum(quantity) count')->where("status",1)->where("express_id",$express_id)->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //根据快递获取对应信息
    public function getByExpressId(array $ids):array
    {
        return $this->tb->select("gd_express_order.order_id","gd_express_order.purchaser_id","gd_express_order.express_id","o.PI_name")
            ->where("gd_express_order.status",1)
            ->join("orders as o", "o.order_id","gd_express_order.order_id")
            ->whereIn("gd_express_order.express_id",$ids)
            ->distinct()
            ->get()->toArray();
    }

    public function getByPoPi($order_id,$args)
    {
        $tb =  $this->tb->from("gd_express_order as eo")
            ->select("eo.express_id","d.tracking_number","d.channel_id")
            ->join("gd_express_delivery as d", "d.id","eo.express_id")
            ->distinct()
            ->where("eo.status",1)
            ->where("eo.order_id",$order_id);

        if(!empty($args["purchaseorder_id"])){
            $tb = $tb->where("eo.purchaseorder_id",$args["purchaseorder_id"]);
        }
        if(!empty($args["purchaseorder_detailed_id"])){
            $tb = $tb->where("eo.purchaseorder_detailed_id",$args["purchaseorder_detailed_id"]);
        }
        if(!empty($args["user_id"])){
            $tb = $tb->where(function ($query) use ($args,$tb){
                $tb = $query->whereIn("d.user_id",$args["user_id"]);
                foreach ($args["user_id"] as $id){
                    $tb = $tb->orWhereRaw("FIND_IN_SET(?, d.purchaser_id)", $id);
                }
            });
        }
        return $tb->get()->toArray();
    }

    public function getPurchaseId($args):array
    {
        $tb = $this->tb->select("purchaser_id")->where("status",1);
        if(!empty($args["express_id"])){
            $tb = $tb->where("express_id",$args["express_id"]);
        }
        if(!empty($args["product_id"])){
            $tb = $tb->where("product_id",$args["product_id"]);
        }
        return $tb->pluck("purchaser_id")->toArray();
    }


}
