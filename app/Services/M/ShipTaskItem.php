<?php

namespace App\Services\M;

use Illuminate\Support\Facades\DB;
use \App\Services\SoStatus;
use \App\Services\ExpressOrders;

class ShipTaskItem extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'shiotask_item';

    public function items(int $id): array
    {
        return $this->tb->where('Shiptask_id', $id)->get()->toArray();
    }

    public function geitemtByIds(array $ids)
    {
        return $this->tb->select("Model","ShioTask_item_id","products_id")->whereIn("ShioTask_item_id",$ids)->get()->toArray();
    }

    public function getItems(int $Shiptask_id): array
    {
        return $this->tb->select([
            "ShioTask_item_id",
            "Shiptask_id",
            "products_id",
            "products_Name",
            "Qtynumber",
            "State",
            "Purchaser_id",
            "Comments",
            "order_info_id",
            "Brand_name"
        ])->where('Shiptask_id', $Shiptask_id)->get()->toArray();
    }

    public function getItemById(int $ShioTask_item_id):object
    {
        return $this->tb->select("products_id","products_Name","crm_shiptask_id","crm_shiptask_item_id")->where('ShioTask_item_id', $ShioTask_item_id)->first();
    }

    public function getByCrmItemId(int $crm_shiptask_item_id):object|null
    {
        return $this->tb->select("Shiptask_id","ShioTask_item_id","crm_shiptask_item_id")->where('crm_shiptask_item_id', $crm_shiptask_item_id)->first();
    }

    //返回所有明细ID
    public function returnItemIds($Shiptask_id):array
    {
        return $this->tb->select('ShioTask_item_id')->where('Shiptask_id', $Shiptask_id)->pluck("ShioTask_item_id")->toArray();
    }

    //发货order下所有SO的状态
    public function returnStates(int $order_info_id): array
    {
        return $this->tb->select('State')->where('order_info_id', $order_info_id)->pluck("State")->toArray();
    }


    //获取有异常的型号
    public function getItemsByAuditStatus(int $Shiptask_id): array
    {
        return $this->tb->select([
            "ShioTask_item_id",
            "Shiptask_id",
            "products_id",
            "products_Name",
            "Qtynumber",
        ])->where('Shiptask_id', $Shiptask_id)->where("audit_satus",">",0)->get()->toArray();
    }

    public function getItemsF1(int $Shiptask_id): array
    {
        return $this->tb->select(['ShioTask_item_id','order_info_id','crm_order_info_id',])->where('Shiptask_id', $Shiptask_id)
            ->where("order_info_id",0)->get()->toArray();
    }

    public function getOrderItemItems(int $order_info_id): array
    {
        return $this->tb->where('order_info_id', $order_info_id)->get()->toArray();
    }

    public function getShipTaskInfoById(int $order_info_id): array
    {
        return $this->tb
            ->select([
                'ShioTask_item_id',
                'shiptask.Shiptask_id',
                'shiptask.State',
                'products_Name',
                'Qtynumber',
                "shiptask.Shiptask_name",
                "shiptask.shipping_way",
                "shiptask.Shiptask_delivery_Singlenumber",
                "shiptask.Shitask_turn_delivery_Singlenumber",
            ])
            ->join("shiptask", "shiptask.Shiptask_id","shiotask_item.Shiptask_id")
            ->where('order_info_id', $order_info_id)
            ->where("shiptask.Enable",0)
            ->get()->toArray();
    }


    //根据$shiptask_id,$order_info_id查找
    public function getByOrderInfoId(int $shiptask_id, $order_info_id):object|null
    {
        return $this->tb->select([
            'ShioTask_item_id',
            'Shiptask_id',
            'order_info_id',
            'Qtynumber',
            'products_Name',
            'Model',
        ])
            ->where('Shiptask_id', $shiptask_id)
            ->where('order_info_id', $order_info_id)
            ->first();
    }

    # 获取订单id下的发货任务详细
    public function getShipItemByOrderId(int $order_id, int $shiptask_id):array
    {
        $result = OrdersItemInfo::getInstance()->getOrderItemsF1($order_id);
        $orderInfoId = [];
        foreach ($result as $obj){
            $orderInfoId[] = $obj->order_info_id;
        }
        return $this->tb->select([
            'ShioTask_item_id',
            'Shiptask_id',
            'products_id',
            'products_Name',
            'order_info_id',
            'Qtynumber',
            'State',
            'Leading_name',
            'taken_quantity',
        ])
            ->whereIn("order_info_id", $orderInfoId)->where("Shiptask_id", $shiptask_id)
            ->get()->toArray();
    }



    //获取所有的产品，搜索
    public function getProducts($param): array
    {
        $so_id = 0; $products_name = "";
        extract($param);
        $tb = $this->tb->select([
                'ShioTask_item_id',
                'Shiptask_id',
                'products_id',
                'products_Name',
            ])->where('Shiptask_id', $so_id);
        if(!empty($products_name)){
            $tb = $tb->where("products_Name","like","%{$products_name}%");
        }
        return $tb->get()->toArray();
    }

    //统计SO单下所有型号的总数量
    public function getSumQtynumber(int $shiptask_id)
    {
        $totalResult = $this->tb->selectRaw('sum(Qtynumber) count')->where("Shiptask_id",$shiptask_id)->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }

    //批量保存拿货数量
    public function saveTakenQuantity($param)
    {
        //$param: [{"shiptask_item_id":"","taken_quantity":""}]  so详细ID，拿货数量

        if(empty($param)) return 0;
        $this->db->beginTransaction();
        try {
            #批量更新
            $ids = [];
            $sql = "update shiotask_item set taken_quantity = CASE ShioTask_item_id \n";
            foreach ($param as $obj){
                $ids[] = $obj['shiptask_item_id'];
                $sql .= sprintf("when %d then %d \n", $obj['shiptask_item_id'],$obj['taken_quantity']);

                //$num = DB::raw('taken_quantity') + ($obj['taken_quantity']);
                //$sql .= sprintf("when %d then %d \n", $obj['shiptask_item_id'],$num);
            }
            $sql .= "END where ShioTask_item_id in(". implode(",", $ids).")";
            $affect = DB::statement($sql);

            $this->db->commit();
            return $affect;
        }catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }



    //更新审核状态，$modelList：有异常的型号数组
    public function updateAuditSatus(int $soId, array $modelList,string $exceptionalType):int|bool
    {
        // shiotask_item表 `audit_satus`  COMMENT '审核状态：0无异常,1有异常,2驳回',
        // shiptask表   `audit_satus`  COMMENT '审核状态：0待审核,1无需审核,2审核同意,3整单驳回,4等待货齐,5部分先发货,6驳回重发',
        // shiptask表    exceptional_type   异常类型
        //gd_so_status表 `audit_satus`  COMMENT '审核状态：0待审核,1已审核',  用于流程控制
        //gd_shiptask_submit 表 `reject_quantity` int(11) NOT NULL DEFAULT '0' COMMENT '驳回数量',

        $this->db->beginTransaction();
        try {
            DB::table("gd_shiptask_submit")->where("shiptask_id",$soId)->update(["reject_quantity"=>0,]);  #每次核对提交，原来的驳回清0
            $this->tb->where("Shiptask_id",$soId)->update(["audit_satus"=>0,"reject_quantity"=>0,"serial_numbers"=>""]);

            if(count($modelList)){  #存在有异常型号，SO单待审核
                $affect = $this->tb->whereIn("ShioTask_item_id", $modelList)->update(["audit_satus"=>1]);
                DB::table("shiptask")->where("Shiptask_id",$soId)->update(["audit_satus"=>0,"exceptional_type"=>$exceptionalType]);
                DB::table("gd_so_status")->where("so_id",$soId)->update(["audit_satus"=>0,]);  #每次核对提交都需要从新审核
            }
            else{ #不存在异常型号，SO单无需审核
                $affect = $this->tb->where("Shiptask_id",$soId)->update(["audit_satus"=>0,"reject_quantity"=>0,"serial_numbers"=>""]);
                DB::table("shiptask")->where("Shiptask_id",$soId)->update(["audit_satus"=>1,"exceptional_type"=>""]);
                DB::table("gd_so_status")->where("so_id",$soId)->update(["audit_satus"=>1,]);
            }
             $this->db->commit();
             return $affect;
         }catch (\Throwable $e) {
             $this->db->rollBack();
             return false;
         }
    }

    //更新审核状态，驳回型号数组
    public function updateAuditSatusF2($args):int|null
    {
        $so_id = $audit_satus = $note = $rejectModel = null;
        extract($args);
        $affect1 = $affect2 = null;

        //orders_item_info表 State 1未分配订货，2预定货，3待收货，4待分配发货，5部分分配发货，\n6已分配发货，7已签收，8部分订货
        //shiotask_item表 `audit_satus`  COMMENT '审核状态：0无异常,1有异常,2驳回',


        #先处理数据
        $param = [];
        if(!empty($args["rejectModel"]))
        {
            foreach ($args["rejectModel"] as $obj) {
                $param[$obj["so_item_id"]] = [];
                foreach ($obj["serial_numbers"] as $k) {
                    $tmpSerial = DB::table("gd_serial_numbers")->select("id","product_id")->where("id",$k["id"])->first();
                    $tmpSubmit = DB::table("gd_shiptask_submit")->select("pod_id")->where("shiptask_item_id",$obj["so_item_id"])
                        ->where("express_product_id", "like", "%".$tmpSerial->product_id."%")->first();
                    $k["pod_id"] = $tmpSubmit->pod_id;
                    $param[$obj["so_item_id"]][$tmpSubmit->pod_id] = isset($param[$obj["so_item_id"]][$tmpSubmit->pod_id]) ? $param[$obj["so_item_id"]][$tmpSubmit->pod_id] : [];
                    $param[$obj["so_item_id"]][$tmpSubmit->pod_id][] = $k;
                }
            }
        }


        $this->db->beginTransaction();
        try {
            #更新主表
            $affect1 = DB::table("shiptask")->where("Shiptask_id",$so_id)->update(["audit_satus"=>$audit_satus,"comments"=>$note]);

            #恢复数据
            $this->tb->where("Shiptask_id",$so_id)->where("audit_satus",2)->update([
                "audit_satus" => 1,  #0无异常,1有异常,2驳回
                "reject_quantity"=>0,
                "serial_numbers" => "",
            ]);
            DB::table("gd_shiptask_submit")->where("shiptask_id",$so_id)->update(["reject_quantity"=>0,]);

            #部分驳回，存在驳回型号
            if(!empty($rejectModel))
            {
                #更新SO详细表
                foreach ($rejectModel as $obj)
                {
                    $reject_quantity = 0;
                    foreach ($obj["serial_numbers"] as $k){
                        $reject_quantity += $k["quantity"];
                    }
                    $affect1 = $this->tb->where("ShioTask_item_id",$obj["so_item_id"])->update([
                        "audit_satus" => 2,  #2驳回
                        "reject_quantity"=>$reject_quantity,
                        "serial_numbers" => json_encode($obj["serial_numbers"]),
                    ]);
                }

                # 按pod_id 统计驳回数量
                foreach ($param as $soId=>$val)
                {
                    foreach ($val as $podId => $list){
                        $num = 0;
                        foreach ($list as $val2){
                            $num += $val2["quantity"];
                        }
                        $affect2 = DB::table("gd_shiptask_submit")->where("shiptask_item_id",$soId)->where("pod_id",$podId)->update([
                            "reject_quantity" => $num,
                        ]);
                    }
                }
            }

            #整单驳回
            if($audit_satus == 3)
            {
                # SO的所有型号置为驳回
                $affect2 = $this->tb->where("Shiptask_id",$so_id)->update([
                    "audit_satus" => 2,  #2驳回
                    "reject_quantity"=> DB::raw("Qtynumber"),
                    "serial_numbers" => "",
                ]);

                # 按pod_id统计驳回数量
                $submitList = DB::table("gd_shiptask_submit")->select("shiptask_item_id","pod_id")->where("shiptask_id",$so_id)->get()->toArray();
                foreach ($submitList as $obj){
                    $num = ExpressOrders::getInstance()->getSumSubmittedQuantity($obj->pod_id);

                    $affect1 = DB::table("gd_shiptask_submit")->where("shiptask_item_id",$obj->shiptask_item_id)
                        ->where("pod_id",$obj->pod_id)->update([
                        "reject_quantity" => $num,
                    ]);
                }
            }


            $affect = $affect1 || $affect2;
            //return $affect;
            $this->db->commit();
            return $affect;
        }catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //审核提交
    public function auditSubmit($args):int
    {
        $so_id = $args["so_id"];
        $affect1 = $affect2 = null;

        $this->db->beginTransaction();
        try {
            # 更新审核状态为1
            SoStatus::getInstance()->updateSoStatus($args['so_status_id'], ['audit_satus' => 1]);

            #整单驳回
            if($args["audit_satus"] == 3)
            {
                #更新orders_item_info表的状态state 和发货数量ShipQty
                # orders_item_info表State字段：1未分配订货，2预定货，3待收货，4待分配发货，5部分分配发货，6已分配发货，7已签收，8部分订货

                $shipItem = $this->tb->select("order_info_id","Qtynumber")->where("Shiptask_id",$so_id)->get()->toArray();
                foreach ($shipItem as $obj){
                    $sql = "UPDATE orders_item_info SET State=4,ShipQty=GREATEST(ShipQty-?,0) WHERE order_info_id=?";   #GREATEST()确保自减后最小值为0，不会出现负数
                    DB::update($sql,[$obj->Qtynumber,$obj->order_info_id]);
                }
            }

            #部分驳回
            if(!empty($args["rejectModel"]))
            {
                #更新orders_item_info表的状态和发货数量
                $shipItem = $this->tb->select("order_info_id","reject_quantity")->where("audit_satus",2)->where("Shiptask_id",$so_id)->get()->toArray();
                foreach ($shipItem as $obj){
                    $sql = "UPDATE orders_item_info SET State=5,ShipQty=GREATEST(ShipQty-?,0) WHERE order_info_id=?";   #GREATEST()确保自减后最小值为0，不会出现负数
                    DB::update($sql,[$obj->reject_quantity,$obj->order_info_id]);
                }
            }
            $affect = $affect1 || $affect2;
            $this->db->commit();
            return $affect;
        }catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }

    }

    //填充型号，规格  $objs格式: [{"id":"","num":"","specs_id":""}]
    public function fillModel($objs,$userMap,$from="id",$to="model")
    {
        if(empty($objs)) return;
        //$userMap = $speceMap = $ids = $specesId = [];
        $specesId = $speceMap=[];


        /*foreach ($objs as $obj) {
            $ids[] = $obj->$from;
            if(!empty($obj->specs_id)){
                $specesId[] = $obj->specs_id;
            }
        }*/

        foreach ($objs as $obj) {
            if(!empty($obj->specs_id)){
                $specesId[] = $obj->specs_id;
            }
            $obj->delivery_num = $userMap[$obj->id]->delivery_num;
            $obj->reduce_num= $userMap[$obj->id]->reduce_num -= $obj->num;
            $obj->numException = 0;
            if ($obj->reduce_num < 0) {
                $obj->numException = 1;
            }
        }
        $specs = IhuProductSpecs::getInstance()->getByIdF1($specesId);
        foreach ($specs as $val){
            $speceMap[$val->specs_id] = $val;
        }

        foreach ($objs as $obj) {
            #补充型号字段
            $obj->$to = isset($userMap[$obj->$from]) ? $userMap[$obj->$from]->Model: "";
            #处理规格id
            if(!empty($obj->specs_id)){
                $obj->specs_name = $speceMap[$obj->specs_id]->specs_name;
                $obj->condition = $speceMap[$obj->specs_id]->condition;
                $obj->sku = $speceMap[$obj->specs_id]->sku;
            }
            else{
                $res = IhuProductSpecs::getInstance()->DefaultSpecs($userMap[$obj->$from]->products_id);
                $obj->specs_id = $res->specs_id;
                $obj->specs_name = $res->specs_name;
                $obj->condition = $res->condition;
                $obj->sku = $res->sku;
            }
        }
    }

    //
    public function updateShipTaskItem($soItemId,$values)
    {
        return $this->tb->where('ShioTask_item_id', $soItemId)->update($values);
    }
}
