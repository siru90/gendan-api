<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SerialNumbers extends BaseService
{

    protected string $table = 'gd_serial_numbers';

    //添加序列号
    public function addSerialNumber(array $values):int
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $values['status'] = 1;
        $this->tb->insert($values);
        return $id;
    }

    public function returnIds($args):array
    {
        $tb = $this->tb->select('id');
        if($args["order_info_id"]){
            $tb = $tb->where("order_info_id",$args["order_info_id"]);
        }
        if($args["purchaser_id"]){
            $tb = $tb->where("purchaser_id",$args["purchaser_id"]);
        }
        return $tb->pluck("id")->toArray();
    }

    public function serialNumberList(array $args): array
    {
        $purchaseorder_detailed_id = $product_id = $purchaser_id = $order_info_id = 0;
        extract($args);

        $tb = $this->tb->where('status', 1);
        if($purchaseorder_detailed_id){
            $tb = $tb->where('purchaseorder_detailed_id', $purchaseorder_detailed_id);
        }
        if($product_id){
            $tb = $tb->where('product_id', $product_id);
        }
        if($order_info_id){
            $tb = $tb->where('order_info_id', $order_info_id);
        }
        if($purchaser_id){
            $tb = $tb->where('purchaser_id', $purchaser_id);
        }

        $totalTb = clone $tb;
        $list = $tb->select([
            "id",
            "user_id",
            "purchaser_id",
            "serial_number",
            "quantity",
            "type",
            "inspect_quantity",
            "audit_quantity",
            "reject_quantity",
            "order_id",
            "order_info_id",
            //"new_quantity",
        ])->orderByDesc("id")->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;

        return [$list, $total];
    }

    public function getProductSerialNumbers(int $product_id): array
    {
        return $this->tb->select('id','user_id','express_id','product_id','serial_number','quantity','note')
            ->where('product_id', $product_id)->where('status', 1)->get()->toArray();
    }

    public function getProductSerialNumbersCount(int $product_id): int
    {
        return $this->mtb->where('product_id', $product_id)->where('status', 1)->sum('quantity');
    }

    public function isExitsByProductId(int $express_id, int $product_id):bool|null
    {
        return $this->tb
            ->where('express_id', $express_id)
            ->where('product_id', $product_id)
            ->where('status', 1)
            ->exists();
    }


    public function getSerialNumberById(int $id): object|null
    {
        return $this->mtb->where('id', $id)->where('status', 1)->first();
    }

    public function getSerialNumberByNumber(string $serial_number):object|null
    {
        return $this->tb->where('serial_number', $serial_number)->where('status', 1)->first();
    }

    //获取无序列号类型的： 一个采购，一个销售明细是唯一一条无序列号
    public function getByType(int $orderInfoId, $purchaser_id):object|null
    {
        return $this->tb->where("order_info_id",$orderInfoId)->where("purchaser_id",$purchaser_id)->where('type', 3)->where('status', 1)->first();
    }


    //统计序列号总数
    public function getSumQuantity(array $args):int
    {
        $tb = $this->tb->selectRaw('sum(quantity) count')->where("status",1);
        if(!empty($args["product_id"])){
            $tb = $tb->where("product_id",$args["product_id"]);
        }
        if(!empty($args["purchaseorder_id"])){
            $tb = $tb->where("purchaseorder_id",$args["purchaseorder_id"]);
        }
        if(!empty($args["purchaseorder_detailed_id"])){
            $tb = $tb->where("purchaseorder_detailed_id",$args["purchaseorder_detailed_id"]);
        }
        if(!empty($args["order_info_id"])){
            $tb = $tb->where("order_info_id",$args["order_info_id"]);
        }
        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }


    //产品新提交的数量，已验货数量
    public function getAllQuantity($args)
    {
        $tb = $this->tb->selectRaw('sum(quantity) quantity, SUM(inspect_quantity) as inspectQuantity')
            ->where("status",1);

        if(!empty($args["purchaseorder_detailed_id"])){
            $tb = $tb->where("purchaseorder_detailed_id",$args["purchaseorder_detailed_id"]);
        }
        if(!empty($args["order_info_id"])){
            $tb = $tb->where("order_info_id",$args["order_info_id"]);
        }
        if(!empty($args["purchaser_id"])){
            $tb = $tb->whereIn("purchaser_id",$args["purchaser_id"]);
        }

        $res = $tb->first();

        $res->quantity = $res->quantity ?? 0;
        $res->inspectQuantity = $res->inspectQuantity ?? 0;
        return [$res->quantity,$res->inspectQuantity];
    }

    //批量保存序列号,针对核对产品为未提交
    public function saveAllSerialNumber(array $data, int $pod_id):int|bool
    {
        $this->db->beginTransaction();
        try {
            #先查有没有数据,先删除,再添加
            //$exists = $this->tb->where('product_id', $product_id)->where('status', 1)->exists();
            $exists = $this->tb->where('purchaseorder_detailed_id', $pod_id)->where('status', 1)->exists();
            if ($exists) {
                //$this->tb->where('product_id', $product_id)->where('status', 1)->delete();
                $this->tb->where('purchaseorder_detailed_id', $pod_id)->where('status', 1)->delete();
            }

            # 再批量添加
            $affected = $this->tb->insert($data);
            $this->db->commit();
            return $affected;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //批量保存序列号，针对核对产品为提交的
    public function updateSerialNumber(array $addParam, $updateParam, $delIds=null)
    {
        $this->db->beginTransaction();
        try {
            $affect = $affect2= null;
            if(!empty($delIds)){
                $affect = $this->tb->whereIn("id",$delIds)->update(["status"=>-1]);
            }

            # 批量添加新增的序列号
            if(!empty($addParam)){
                $affect = $this->tb->insert($addParam);
            }

            #批量更新
            if(!empty($updateParam)){
                $ids = [];
                $sql = "update gd_serial_numbers set quantity = CASE id \n";
                //$sql2 = " new_quantity = CASE id \n";  #去掉了
                $sql3 = " serial_number = CASE id \n";

                foreach ($updateParam as $obj){
                    $ids[] = $obj['id'];
                    $sql .= sprintf("when %d then %d \n", $obj['id'],$obj['quantity']);
                    //$sql2 .= sprintf("when %d then %d \n", $obj['id'],$obj['new_quantity']);
                    $sql3 .= sprintf("when %d then '%s' \n", $obj['id'],$obj['serial_number']);
                }

                //$sql .= " END,\n {$sql2} END, \n {$sql3} END \n";
                $sql .= " END,\n {$sql3} END \n";
                $sql .= "where id in(". implode(",", $ids).")";
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

    //获取产品新提交的数量
    public function getNewQuanitty(array $productId)
    {
        return $this->tb->select([
            "id",
            "serial_number",
            "new_quantity",
        ])->whereIn("product_id",$productId)->where("new_quantity",">",0)->where("status",1)->get()->toArray();
    }


    //设置待验货数量=序列号数量
    public function setNewQuantity(int $poId)
    {
        $sql = "UPDATE gd_serial_numbers SET new_quantity=0,inspect_quantity=quantity WHERE status=1 and purchaseorder_id=?";
        //DB::statement($sql);
        $res = DB::update($sql,[$poId]);
        return $res;
    }

    //设置待验货数量= 验货数量+新提交的数量,注意new_quantity的赋值要在后面
    public function setInspectQuantity(int $poId,$submit_status=0)
    {
        if(!$submit_status){  // 0表示第一次提交，序列号数量=验货数量
            $param = ["inspect_quantity"=>DB::raw('quantity'),"new_quantity"=>0];
        }
        else{  //表示第二次及以上提交，待验货数量= 原待验货数量+新提交数量
            $param = ["inspect_quantity"=>DB::raw('inspect_quantity + new_quantity'),"new_quantity"=>0];
        }
        return SerialNumbers::getInstance()->updateSerial(["purchaseorder_id"=>$poId],$param);
    }


    //更新序列号
    public function updateSerial($args,$values)
    {
        $id = $purchaseorder_id = $purchaseorder_detailed_id = null;
        $pod_ids = array();
        extract($args);
        $tb = $this->tb->where("status",1);

        if(!empty($id)){
            $tb = $tb->where('id', $id);
        }
        if(!empty($purchaseorder_id)){
            $tb = $tb->where('purchaseorder_id', $purchaseorder_id);
        }
        if(!empty($purchaseorder_detailed_id)){
            $tb = $tb->where('purchaseorder_detailed_id', $purchaseorder_detailed_id);
        }
        if(!empty($pod_ids)){
            $tb = $tb->whereIn('purchaseorder_detailed_id', $pod_ids);
        }
        if(!empty($args["order_info_id"])){
            $tb = $tb->where('order_info_id', $args["order_info_id"]);
        }
        if(!empty($args["purchaser_id"])){
            if(is_array($args["purchaser_id"])){
                $tb = $tb->whereIn('purchaser_id', $args["purchaser_id"]);
            }else{
                $tb = $tb->where('purchaser_id', $args["purchaser_id"]);
            }
        }
        if(!empty($order_info_ids)){
            $tb = $tb->whereIn('order_info_id', $order_info_ids);
        }

        return $tb->update($values);
    }







}
