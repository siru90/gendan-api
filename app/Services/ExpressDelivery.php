<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class ExpressDelivery extends BaseService
{

    protected string $table = 'gd_express_delivery';

    public function addExpressDelivery(array $values): int
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $values['status'] = 1;
        $this->tb->insert($values);
        return $id;
    }

    public function updateExpressDelivery(int $id, array $values): int
    {
        if(isset($values['id']))  unset($values['id']);
        return $this->tb->where('id', $id)->update($values);
    }

    public function deleteExpressDelivery(int $id): int
    {
        return $this->tb->where('id', $id)->update(['status' => -1]);
    }

    public function getExpressDelivery(int $id, ?array $field = null): object|null
    {
        $tb = $this->tb->where('id', $id)->where('status', 1);
        if(!empty($field)){
            $tb->select($field);
        }
        return $tb->first();
    }

    public function getById(int $id): object|null
    {
        return $this->mtb->select(['id','user_id','channel_id','tracking_number','submit_status','purchaser_id','check_status','is_confirmed',"abnormal_source"])
            ->where('id', $id)->where('status', 1)->first();
    }

    public function getExistedExpressDelivery(int $channel_id, string $tracking_number): object|null
    {
        return $this->tb
            ->where('channel_id', $channel_id)
            ->where('tracking_number', $tracking_number)
            ->where('status', 1)
            ->first();
    }

    //根据快递号查
    public function getByTrackingNumber($tracking_number):array
    {
        return $this->tb->where('tracking_number', $tracking_number)->where('status', 1)->get()->toArray();
    }

    //快递列表
    public function getExpressList(array $args): array
    {
        $page = $size = 0;
        extract($args);

        $tb = $this->tb->from("gd_express_delivery as d")->where('d.status', 1);
        $offset = max(0, ($page - 1) * $size);

        if (!empty($args["channel_id"])) {
            $tb = $tb->where('d.channel_id', $args["channel_id"]);
        }
        if(!empty($args["keyword"])){
            if($args["keyword_type"] == "express"){
                $tb = $tb->where("d.tracking_number", "like", "%{$args["keyword"]}%");
            }
        }
        if(!empty($args['purchaser_id'])){
            $tb = $tb->where(function ($query) use ($args,$tb){
                $tb = $query->whereRaw("FIND_IN_SET(?, d.actual_purchaser_id)", $args["purchaser_id"])
                    ->orWhereRaw("FIND_IN_SET(?, d.purchaser_id)", $args["purchaser_id"]);
            });
        }
        if(!empty($args['abnormal_status'])){
            $tb = $tb->whereIn('d.abnormal_status', $args['abnormal_status']);
        }

        $totalTb = clone $tb;
        $list = $tb->select([
            'd.id',
            'd.user_id',
            'd.channel_id',
            'd.tracking_number',
            'd.purchaser_id',
            'd.actual_purchaser_id',
            'd.abnormal_status',
            'd.abnormal_source',
        ])
            ->distinct()->orderBy('d.id','desc')->offset($offset)->limit($size);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT d.id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }


    //批量查找待收状态的快递，查看物流
    public function getExpressBySubmitStatus($submit_status):array
    {
        $time = date("Y-m-d",strtotime("-20 day"));
        $tb = $this->tb->select('id','tracking_number','sender_phone','receiver_phone')
            //->where('submit_status', $submit_status)
            ->where("created_at",">", $time)
            ->whereNull("logistic_status")
            ->where("status",1)
            ->orderByDesc("id");


        $tb = $tb->where(function ($query) use ($submit_status,$tb){
            $tb = $query->where('submit_status', $submit_status)
                    ->orWhereIn("express_status",[1,2,3]);
        });

/*        $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);
        \Illuminate\Support\Facades\Log::channel('sync')->info('更新快递物流: $sql:'.$sql);
*/

        return $tb->get()->toArray();
    }

    //快递关联采购订单
    public function expressOrderService(array $args):array|null
    {
        $args["model_list"] = str_replace('\\\"', '', $args["model_list"]);
        $args["model_list"] = str_replace("\\", "", $args["model_list"]);

        $model_list = json_decode($args["model_list"], true);
        unset($args["model_list"]);
        $purcharse_id = [];

        $this->db->beginTransaction();
        try {
            # 添加快递数据
            $isExpress = $this->getExistedExpressDelivery($args["channel_id"],$args['tracking_number']);
            if(!$isExpress){
                $expressID = $this->addExpressDelivery($args);
            }else{
                $expressID = $isExpress->id;
            }

            $pIds = $eoIds =[];
            foreach ($model_list as $obj){
                #汇总采购ID
                $purcharse_id[] = $obj["purchaser_id"];

                # 获取订单详细信息
                $orderInfo = \App\Services\M\PurchaseOrderDetailed::getInstance()->getOrderByPodId($obj["pod_id"]);

                $productId = null;
                $prdouct = DB::table("gd_products")->where("status",1)->where('model',$obj["model"])->where("express_id",$expressID)->first();
                if(!$prdouct){
                    $params = [
                        "user_id" => $args["user_id"],
                        "express_id" => $expressID,
                        "model" => $obj["model"],
                        "ihu_product_id" => isset($orderInfo->products_id)??0,
                        "note" =>"",
                        "is_confirmed" =>0,
                        "submit_status" =>$args["submit_status"],
                        "actual_model" => $obj["model"],
                    ];
                    # 添加快递产品表
                    $productId = Products::getInstance()->addModel($params);
                }
                else{
                    $productId = $prdouct->id;
                }
                $pIds[] = $productId;

                $params = [
                    "user_id" => $args["user_id"],
                    "express_id" => $expressID,
                    "product_id" => $productId,
                    "quantity" => $obj["quantity"],
                    "purchaseorder_detailed_id" => $obj["pod_id"],
                    "purchaser_id"=>$obj["purchaser_id"],
                    "purchaseorder_id" => $orderInfo->Purchaseorder_id,
                    "order_id" => $orderInfo->order_id,
                    "order_item_id" => $orderInfo->order_info_id,
                ];
                # 添加快递采购订单表
                $expressOrderId = ExpressOrders::getInstance()->addExpressOrder($params);
                $eoIds[] = $expressOrderId;
            }
            $this->db->commit();

            return [$expressID,$pIds,$eoIds];
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return null;
        }
    }

    //填充采购ID
    public function fillPurchaseId(int $expressID, array $productIds)
    {
        #统计得到快递的采购人员，快递产品的采购人员
        $purcharse_ids = ExpressOrders::getInstance()->getPurchaseId(["express_id"=>$expressID]);
        $purcharse_ids = array_unique($purcharse_ids);
        $this->updateExpressDelivery($expressID,["purchaser_id"=>implode(",", $purcharse_ids)]);
        foreach ($productIds as $pid){
            $quantity = ExpressOrders::getInstance()->getSumQuantity($pid);
            $purcharse_ids = ExpressOrders::getInstance()->getPurchaseId(["product_id"=>$pid]);
            $purcharse_ids = array_unique($purcharse_ids);
            Products::getInstance()->updateModel($pid, ["quantity"=>$quantity,"purchaser_id"=>implode(",", $purcharse_ids)]);
        }
    }

    //返回ids中异常的快递
    public function getAbnormalExpress(array $ids):array|null
    {
        return $this->tb->whereIn('id', $ids)->where("status",1)->whereIn("abnormal_status",[1,2])->get()->toArray();
    }

    //获取异常快递信息
    public function getAbnormalInfo($id)
    {
        return $this->tb->where('id', $id)->where("status",1)->where("abnormal_status","!=",0)->first();
    }

    //添加异常快递,及快递产品
    public function saveAbnormalExpress(array $args, array $productIds):int
    {
        $productIds = array_flip($productIds);
        $expressId = $args["express_id"]??0;
        $model_list = !empty($args["model_list"]) ? json_decode($args["model_list"], true) : [];
        $express_filids = !empty($args["express_fileids"])? json_decode($args["express_fileids"], true) :[];

        unset($args["model_list"],$args["express_id"],$args["express_fileids"]);
        $this->db->beginTransaction();
        try {
            # 添加快递数据
            if(!empty($expressId)){
                $this->updateExpressDelivery($expressId,$args);
            }else{
                $expressId = $this->addExpressDelivery($args);
            }
            #添加快递图片
            $expressFileIds = \App\Services\Attachments::getInstance()->returnIds(["correlate_id"=>$expressId,"correlate_type"=>1,"return_id"=>"file_id"]);
            if(count($express_filids)){
                foreach ($express_filids as $v){
                    $file = \App\Services\Files::getInstance()->getById($v["id"]);
                    $extension = pathinfo($file->path, PATHINFO_EXTENSION);
                    $type = (strtolower($extension) === 'mp4') ? 1:0;

                    #如果已存在则更新标志
                    if(isset($expressFileIds[$v["id"]])){
                        $attachId = \App\Services\Attachments::getInstance()->updateByFlag($v["id"],$expressId,1,$v["flag"]);
                    }else{
                        $attachId = \App\Services\Attachments::getInstance()->addAttachment($args['user_id'], $v["id"], [
                            'correlate_id' => $expressId,
                            'correlate_type' => 1,
                            'type' => $type,
                            'flag' => $v["flag"],
                        ]);
                    }
                    unset($expressFileIds[$v["id"]]);  //去掉已经编辑的，剩余则为需要删除的
                }
            }
            foreach ($model_list as $obj){
                $ihuProduct = \App\Services\M\IhuProduct::getInstance()->getFirstModel($obj["model"]);
                $params = [
                    "user_id" => $args["user_id"],
                    "express_id" => $expressId,
                    "model" => $obj["model"],
                    "ihu_product_id" => isset($ihuProduct->product_id)??0,
                    "note" =>"",
                    "is_confirmed" =>0,
                    "submit_status" =>2,
                    "abnormal_status" =>1,
                    "actual_model" => $obj["model"],
                    "quantity"=>0,
                    "actual_quantity"=>$obj["quantity"]
                ];
                if(!empty($obj["id"])){
                    # 关联快递新增异常型号
                    $expOrder = ExpressOrders::getInstance()->getByPorductId($obj["id"]);
                    $product = Products::getInstance()->getProduct($obj["id"]);
                    if($expOrder){ # 该产品已关联则不能修改关联型号，关联数量
                        unset($params["model"],$params["quantity"]);
                        if($obj["model"]==$product->model && $obj["quantity"]==$product->quantity){  //型号和数量一致
                            $params["abnormal_status"] = 0;
                        }
                    }
                    $affect = Products::getInstance()->updateModel($obj["id"], $params);
                    unset($productIds[$obj["id"]]);  //去掉已经编辑的，剩余则为需要删除的
                    $productId = $obj["id"];
                }else{
                    # 添加快递产品表
                    $productId = Products::getInstance()->addModel($params);
                }
                #处理产品里的文件ID，全部新增关联
                if(empty($obj["fileIds"])) continue;
                $productFilesIds = \App\Services\Attachments::getInstance()->returnIds(["correlate_id"=>$productId,"correlate_type"=>2,"return_id"=>"file_id"]);

                $productFilesIds = array_flip($productFilesIds);
                //var_dump($productFilesIds);die;
                foreach ($obj["fileIds"] as $v){
                    $file = \App\Services\Files::getInstance()->getById($v["id"]);
                    $extension = pathinfo($file->path, PATHINFO_EXTENSION);
                    $type = (strtolower($extension) === 'mp4') ? 1:0;
                    if(isset($productFilesIds[$v["id"]])){
                        $attachId = \App\Services\Attachments::getInstance()->updateByFlag($v["id"],$productId,2,$v["flag"]);
                    }
                    else{
                        $attachId = \App\Services\Attachments::getInstance()->addAttachment($args['user_id'], $v["id"], [
                            'correlate_id' => $productId,
                            'correlate_type' => 2,
                            'type' => $type,
                            'flag' => $v["flag"],
                        ]);
                    }
                    unset($productFilesIds[$v["id"]]);
                }
                #批量删除快递产品图片
                if(count($productFilesIds)){
                    \App\Services\Attachments::getInstance()->batchDelete(array_flip($productFilesIds),$productId,2);
                }
            }
            #批量删除产品
            if(count($productIds)){
                Products::getInstance()->batchDelete(array_flip($productIds));
            }

            #批量删除快递图片
            if(count($expressFileIds)){
                \App\Services\Attachments::getInstance()->batchDelete(array_flip($expressFileIds),$expressId,1);
            }

            $this->db->commit();
            return $expressId;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }


    //异常快递产品，批量编辑
    public function saveAbnormalProduct(array $args):int|null
    {
        $expressId = $args["express_id"];
        $model_list = json_decode($args["model_list"], true);
        unset($args["model_list"],$args["express_id"]);
        $this->db->beginTransaction();
        try {
            #更新快递
            $this->updateExpressDelivery($expressId,$args);
            foreach ($model_list as $obj){
                $params = [
                    //"model" => $obj["model"],
                    //"ihu_product_id" => isset($ihuProduct->product_id)??0,
                    "actual_model" => $obj["model"],
                    "actual_quantity"=>$obj["quantity"]
                ];
                #先去判断有没有关联
                $expressOrder = ExpressOrders::getInstance()->getByPorductId($obj["id"]);
                if(empty($expressOrder)){
                    $ihuProduct = \App\Services\M\IhuProduct::getInstance()->getFirstModel($obj["model"]);
                    $params["model"] = $obj["model"];
                    $params["ihu_product_id"] = isset($ihuProduct->product_id)??0;
                }
                if(!empty($obj["id"])){
                    $productId = Products::getInstance()->updateModel($obj["id"], $params);
                }
            }
            $this->db->commit();
            return $expressId;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //异常快递列表
    public function abnormalExpressList(array $args):array
    {
        $page = $size = 0;
        $purchaser_id = $channel_id = $submit_status = $keyword = $sales_id = null;
        extract($args);
        $offset = max(0, ($page - 1) * $size);

        $tb = $this->tb->from("gd_express_delivery as d");
        if (!empty($args["start_time"]) && !empty($args["end_time"])) {
            $tb = $tb->whereBetween('d.created_at', [$args["start_time"],$args["end_time"]]);
        }
        if(!empty($args["keyword"])){
            if($args["keyword_type"] == "express"){
                $tb = $tb->where('d.tracking_number', "like", "%{$args["keyword"]}%");
            }
            if($args["keyword_type"] == "model"){
                $tb = $tb->where("p.model", "like", "{$args["keyword"]}%");
                $tb = $tb->join("gd_products as p", "p.express_id", "d.id");
            }
        }
        //if(!empty($args['purchaser_id']) && $args["user_group_id"] !=3){
        if(!empty($args['purchaser_id'])){
            $tb = $tb->where(function ($query) use ($args,$tb){
                $tb = $query->whereRaw("FIND_IN_SET(?, d.actual_purchaser_id)", $args["purchaser_id"])
                    ->orWhereRaw("FIND_IN_SET(?, d.purchaser_id)", $args["purchaser_id"]);
            });
        }
        if(!empty($args["express_id"])){
            $tb = $tb->whereIn('d.id', $args['express_id']);
        }
        if($args["user_group_id"] ==3){ #采购能看自己创建的草稿的数据
            $tb = $tb->where(function ($query) use ($args,$tb){
                foreach ($args["user_id"] as $id){
                    $tb = $query->orWhereRaw("FIND_IN_SET(?, d.purchaser_id)", $id);
                }
                $tb = $query->orWhereIn("d.user_id",$args["user_id"]);
                $tb = $query->orWhere(function ($query)  use ($args,$tb) {
                    $query->where("abnormal_submit",1);
                    foreach ($args["user_id"] as $id){
                        $tb = $query->whereRaw("FIND_IN_SET(?, d.actual_purchaser_id)", $id);;
                    }
                });
            });
        }
        if(!empty($args['abnormal_status'])){
            $tb = $tb->where('d.abnormal_status', $args['abnormal_status']);
        }else{
            $tb = $tb->where("d.abnormal_status", "!=",0);
        }
        $tb = $tb->where("d.status",1);

        if(!empty($args["permission_and"]) && $args["permission_and"] == "or"){
            if(!empty($args["permission_express_id"])){
                $tb = $tb->orWhereIn('d.id', $args['permission_express_id']);
            }
        }
        else if(!empty($args["permission_and"]) && $args["permission_and"] == "and"){
            $tb = $tb->whereIn('d.id', $args['permission_express_id']);
        }

        $totalTb = clone $tb;
        $list = $tb->select([
            'd.id',
            'd.user_id',
            'd.channel_id',
            'd.tracking_number',
            'd.purchaser_id',
            'd.created_at',
            'd.updated_at',
            'd.abnormal_status',
            'd.actual_purchaser_id',
            'd.abnormal_source',
            'd.abnormal_submit',
        ])
            ->distinct()->orderBy('d.abnormal_status','asc')->orderByDesc('d.created_at')->offset($offset)->limit($size);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT d.id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }


    //异常快递状态变更
    public function updateAbnormalStatus(int $expressId, array $args):int
    {
        $this->db->beginTransaction();
        try {
            $affect = $this->updateExpressDelivery($expressId, ["abnormal_status"=>$args["abnormal_status"],"abnormal_record"=>$args["abnormal_record"]]);
            Products::getInstance()->updateModelByExpress($expressId,["abnormal_status"=>$args["abnormal_status"]]);
            $this->db->commit();
            return $affect;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

}
