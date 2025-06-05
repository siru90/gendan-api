<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ShiptaskSubmit extends BaseService
{

    protected string $table = 'gd_shiptask_submit';

    public function batchUpdate(array $addList,$updateList,int $soId, string $operate)
    {
        $affect = $affect2 = null;
        $this->db->beginTransaction();
        try {
            # 批量添加
            if(!empty($addList)){
                $affect = $this->tb->insert($addList);
            }
            # 批量更新
            if(!empty($updateList)){
                $ids = [];
                $sql = "update gd_shiptask_submit set delivery_num = CASE id \n";
                $sql2 = " return_num = CASE id \n";
                //$sql3 = " real_quantity = CASE id \n";

                foreach ($updateList as $obj){
                    $ids[] = $obj['id'];
                    $sql .= sprintf("when %d then %d \n", $obj['id'],$obj['delivery_num']);
                    $sql2 .= sprintf("when %d then %d \n", $obj['id'],$obj['return_num']);
                    //$sql3 .= sprintf("when %d then %d \n", $obj['id'],$obj['return_num']+$obj['delivery_num']);   #实到数量
                }
                $sql .= " END,\n {$sql2} END \n";
                $sql .= "where id in(". implode(",", $ids).")";
//var_dump($sql);die;
                $affect2 = DB::statement($sql);
            }
            if($affect || $affect2){
                $affect = true;
            }

            if($operate == "submit") { #当提交时
                #批量修改实到数量
                $sql = "UPDATE gd_shiptask_submit SET real_quantity = (delivery_num + return_num) WHERE shiptask_id=?";
                $res = DB::update($sql,[$soId]);
            }

            $this->db->commit();
            return $affect;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }


    //批量插入，批量更新
    public function batchUpdateBak(array $addList,$updateList,int $soId, string $operate)
    {
        $affect = $affect2 = null;
        $productIdArr =$this->getProductId($addList, $updateList);

        $this->db->beginTransaction();
        try {
            # 批量添加
            if(!empty($addList)){
                $affect = $this->tb->insert($addList);
                foreach ($addList as $obj){
                    DB::table("gd_serial_numbers")->whereIn("product_id", explode(",", $obj["express_product_id"]))->update(["new_quantity"=>0]);
                }
            }

            # 批量更新
            if(!empty($updateList)){
                $ids = [];
                $sql = "update gd_shiptask_submit set delivery_num = CASE id \n";
                $sql2 = " return_num = CASE id \n";
                //$sql3 = " real_quantity = CASE id \n";
                $sql4 = " express_product_id = CASE id \n";

                foreach ($updateList as $obj){
                    $ids[] = $obj['id'];
                    $sql .= sprintf("when %d then %d \n", $obj['id'],$obj['delivery_num']);
                    $sql2 .= sprintf("when %d then %d \n", $obj['id'],$obj['return_num']);
                    //$sql3 .= sprintf("when %d then %d \n", $obj['id'],$obj['return_num']+$obj['delivery_num']);   #实到数量
                    $sql4 .= sprintf("when %d then '%s' \n", $obj['id'],$obj['express_product_id']);
                }
                $sql .= " END,\n {$sql2} END, \n {$sql4} END \n";
                $sql .= "where id in(". implode(",", $ids).")";
//var_dump($sql);die;
                $affect2 = DB::statement($sql);
            }
            if($affect || $affect2){
                $affect = true;
            }

            if($operate == "submit") { #当提交时
                #批量修改实到数量
                $sql = "UPDATE gd_shiptask_submit SET real_quantity = (delivery_num + return_num) WHERE shiptask_id=?";
                //var_dump($sql);
                $res = DB::update($sql,[$soId]);

                if(!empty($productIdArr)){
                    #批量清空序列号中new_quantity的数量
                    DB::table("gd_serial_numbers")->whereIn("product_id", $productIdArr)->update(["new_quantity"=>0]);
                }
            }

            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    private function getProductId(array $addList,$updateList)
    {
        $ids = [];
        if(!empty($addList)){
            foreach ($addList as $obj){
                $ids = array_merge($ids, explode(",", $obj["express_product_id"]) );
            }
        }
        if(!empty($updateList)){
            foreach ($updateList as $obj){
                $ids = array_merge($ids, explode(",", $obj["express_product_id"]) );
            }
        }
        return $ids;
    }

    //根据so详单ID
    public function getByItemId(int $soItemId):object|null
    {
        return $this->tb
            ->select("id","shiptask_item_id","pod_id","delivery_num","return_num","express_product_id","real_quantity","reject_quantity")
            ->where("shiptask_item_id",$soItemId)->where("status",1)
            ->first();
    }

    //根据so详单ID，采购订单详单ID
    public function getByItemAndPod(int $soItemId, $podId):object|null
    {
        return $this->tb
            ->select("id","shiptask_item_id","pod_id","delivery_num","return_num","express_product_id","real_quantity","reject_quantity")
            ->where("shiptask_item_id",$soItemId)->where("pod_id",$podId)->where("status",1)
            ->first();
    }


    //统计所有的型号的妥交，内退的数量，根据SOID
    public function getAllItemBySoId(int $soId):array
    {
        $tb = $this->tb->from("gd_shiptask_submit as ss")->select([
            "shiptask_item_id",
            "products_Name",
            DB::raw("SUM(delivery_num) as delivery_num"),
            DB::raw("SUM(return_num) as return_num"),
            DB::raw("SUM(real_quantity) as real_quantity"),
        ])
            ->join("shiotask_item", "ShioTask_item_id","ss.shiptask_item_id")
            ->where("ss.shiptask_id",$soId)->where("ss.status",1)
            ->groupBy("ss.shiptask_item_id");

/*        $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        return $tb->get()->toArray();
    }

    public function getSumBySoId(int $soId):object|null
    {
        $tb = $this->tb->select([
            DB::raw("SUM(delivery_num) as delivery_num"),
            DB::raw("SUM(return_num) as return_num"),
            DB::raw("SUM(real_quantity) as real_quantity"),
        ])
            ->where("shiptask_id",$soId)->where("status",1);
        return $tb->first();
    }


    //统计单个型号的妥交， 内退的数量
    public function getItemNum(int $shiotask_item_id):object|null
    {

        $tb = $this->tb->select([
            "shiptask_item_id",
            DB::raw("SUM(delivery_num) as delivery_num"),
            DB::raw("SUM(return_num) as return_num"),
        ])
            ->where("shiptask_item_id",$shiotask_item_id)->where("status",1)
            ->groupBy("shiptask_item_id");

/*        $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        return $tb->first();
    }

    //统计采购单里单个型号已录入的数量（其他发货单已发货）
    public function getEnterQuantity(int $soId,$podId):int
    {
        $tb = $this->tb->select([
            "shiptask_item_id",
            DB::raw("SUM(delivery_num) as delivery_num"),
            DB::raw("SUM(return_num) as return_num"),
        ])->where("shiptask_id","!=",$soId)->where("pod_id",$podId)
            ->where("status",1)
            ->groupBy("shiptask_item_id");

        $result = $tb->first();
        //var_dump($result);

        $delivery_num = $result->delivery_num ?? 0;
        $return_num = $result->return_num ?? 0;
        return $delivery_num + $return_num;
    }

    //获取so对应的采购详单ID
    public function getPodId(int $soId)
    {
        $data = $this->tb->select("pod_id")->where("status",1)->where("shiptask_id",$soId)->get()->toArray();
        $tmp = [];
        foreach ($data as $obj){
            $tmp[] = $obj->pod_id;
        }
        return $tmp;
    }


    //获取异常型号对应的所有快递序列号
    public function getserialNumbers(int $shiptask_item_id):array
    {
        $data = [];

        # 获取发货任务详细ID，对应的所有的快递产品ID
        $expressProductId = [];
        $productId = $this->tb->select("pod_id","express_product_id")->where("shiptask_item_id",$shiptask_item_id)->get()->toArray();
        foreach ($productId as $val){
            $tmp = explode(",", $val->express_product_id);


            #拿到快递产品ID对应的序列号
            $tmpSerial = DB::table("gd_serial_numbers")
                ->select(["id","type","quantity","serial_number","product_id","express_id"])
                ->whereIn("product_id",$tmp)->where("status",1)
                ->orderByDesc("type")->orderBy("express_id")
                ->get()->toArray();

            foreach ($tmpSerial as $v){
                $v->pod_id = $val->pod_id;
            }
            $data = array_merge($tmpSerial,$data);

            //$expressProductId = array_merge($tmp,$expressProductId);
        }

        #拿到快递产品ID对应的序列号
/*        $data = DB::table("gd_serial_numbers")
            ->select(["id","type","quantity","serial_number","product_id","express_id"])
            ->whereIn("product_id",$expressProductId)->where("status",1)
            ->orderByDesc("type")->orderBy("express_id")
            ->get()->toArray();
*/


        return $data;
    }

    //统计型号的实到数量,驳回数量
    public function getItemRealQuantiy($shiptask_item_id):object
    {
        $result = $this->tb->select([
            DB::raw("SUM(real_quantity) as real_quantity"),
            DB::raw("SUM(reject_quantity) as reject_quantity")
        ])->where("shiptask_item_id",$shiptask_item_id)->where("status",1)->first();
        return $result;
    }





}
