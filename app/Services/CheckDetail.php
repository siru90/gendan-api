<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;

class CheckDetail extends BaseService
{

    protected string $table = 'gd_check_detail';

    public function addCheck(array $values) : int|null
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $this->tb->insert($values);
        return $id;
    }

    // 添加或更新
    public function setCheckByInfoId($order_info_id, array $values): array
    {
        $this->db->beginTransaction();
        try {
            $info = $this->getCheckPi($order_info_id);
            if ($info) {
                $affected = $this->tb->where('id', $info->id)->update($values);
                $id = $info->id;
            } else {
                $id = $this->get_unique_id();
                $values['id'] = $id;
                $affected = $this->tb->insert($values);
            }
            $this->db->commit();
            return [$id, $affected];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return [false, 0];
        }
    }

    //根据类型更新
    public function updateCheck($type,$id,$values)
    {
        $tb = $this->tb;
        if($type == "order_info_id"){
            $tb->where("order_info_id",$id);
        }
        return $tb->update($values);
    }

    public function updateByIds(array $ids, array $values): int
    {
        return $this->tb->whereIn('order_info_id', $ids)->update($values);
    }

    public function getByOrderId($order_id):array
    {
        return $this->tb->select([
            "id",
            "order_id",
            "order_info_id",
            //"submit_purcharse"
        ])->where('order_id', $order_id)->get()->toArray();
    }

    public function getCheckPi($order_info_id): object|null
    {
        return $this->tb->select([
            "id",
            "shelf_position",
            "serial_quantity",
            "inspect_quantity",
            "audit_quantity",
            "attach_exceptional",
            "confirm_status",
            "inspect_status",
            "is_reject",
            "audit_status",
            "is_audit",
            "order_info_id",
            "note",
            "audit_note"
        ])->where('order_info_id', $order_info_id)->first();
    }

    public function returnDefault($order_info_id):object
    {
        $data = [
            "shelf_position"=>"",
            "serial_quantity"=>0,
            "inspect_quantity"=>0,
            "attach_exceptional"=>0,
            "confirm_status"=>0,
            "inspect_status"=>0,
            "is_reject"=>0,
            "audit_status"=>0,
            "is_audit"=>0,
            "note"=>"",
            "order_info_id"=>$order_info_id,
        ];
        return (object)$data;
    }


    public function returnIds($args):array
    {
        $returnId = $args["return_id"];
        $tb = $this->tb->select($returnId);
        if(!empty($args["order_info_id"])){
            $tb = $tb->where("order_info_id",$args["order_info_id"]);
        }
        if(isset($args["inspect_status"])){
            $tb = $tb->where("inspect_status",$args["inspect_status"]);
        }
        if(isset($args["is_audit"])){
            $tb = $tb->where("is_audit",$args["is_audit"]);
        }
        if(isset($args["attach_exceptional"])){
            $tb = $tb->where("attach_exceptional",$args["attach_exceptional"]);
        }
        if (!empty($args["start_time"]) && !empty($args["end_time"])) {
            $tb = $tb->whereBetween('created_at', [$args["start_time"],$args["end_time"]]);
        }

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        return $tb->pluck($returnId)->toArray();
    }


    //验货：更新审核状态
    public function updateAuditStatus(array $orderInfoIds, array$modelList, array $userId)
    {
        $res = null;
        $this->db->beginTransaction();
        try {
            #每次提交验货，初始化审核状态, 驳回状态

            #处理当前验货数据,序列号数据
            foreach ($modelList as $obj)
            {
                if($obj["is_audit"]==1 && $obj["audit_status"] ==1){  #没有异常，已审核数量=验货数量
                    $obj["audit_quantity"] = $obj["inspect_quantity"];
                }
                $res = $this->updateCheck("order_info_id",$obj["order_info_id"],$obj);

                #处理序列号
                $param = ["inspect_quantity"=>DB::raw('quantity'),];
                SerialNumbers::getInstance()->updateSerial(["order_info_id"=>$obj["order_info_id"],"purchaser_id"=>$userId],$param);
            }
            $this->db->commit();
            return $res;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }


    //审核提交，驳回型号数组
    public function auditSubmit($args):int|null
    {
        $order_id = 0; $operate = $audit_satus = $audit_note = $rejectModel= $file_ids = null;
        $order_info_ids = [];
        extract($args);
        $affect = null;

        #初始化，清除所有序列号的驳回数量,已审核数量
        SerialNumbers::getInstance()->updateSerial(["order_info_id"=>$order_info_ids],["reject_quantity"=>0,"audit_quantity"=>0]);

        #审核保存
        if($operate == "save"){
            $this->db->beginTransaction();
            try {
                #整单驳回
                if($audit_satus == 3)
                {
                    #保存序列号的驳回数量，销售订单审核状态
                    $sql = "UPDATE gd_serial_numbers SET reject_quantity=quantity where order_id=?";
                    DB::update($sql,[$order_id]);
                }
                #部分驳回：4等待货齐,5部分先发货
                //$rejectModel: [{"order_info_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
                if( in_array($audit_satus, [4,5]) && !empty($rejectModel)){
                    foreach ($rejectModel as $obj){
                        if(empty($obj["serial_numbers"])) continue;
                        foreach ($obj["serial_numbers"] as $k=>$serial)
                        {
                            $sql = "UPDATE gd_serial_numbers SET reject_quantity=? where status=1 and id=?";
                            DB::update($sql,[$serial["quantity"],$serial["id"] ]);
                        }
                    }
                }
                #更新主表审核状态
                $affect = $this->updateByIds($order_info_ids,["audit_note"=>$audit_note,"is_reject"=>0,"audit_status"=>$audit_satus]);
                $this->db->commit();
                return $affect;
            }catch (\Throwable $e) {
                $this->db->rollBack();
                return false;
            }
        }
        #审核提交
        else{
            $is_audit = 1; $submit_status=1;  #主表的状态
            $this->db->beginTransaction();
            try {
                #整单驳回，所有序列号删除，已验收数量为0, 审核置为待审核, 整个销售单变为未提交
                if($audit_satus == 3)
                {
                    #删除所有序列号
                    SerialNumbers::getInstance()->updateSerial(["order_info_ids"=>$order_info_ids],["status"=>-1,"reject_quantity"=>0]);

                    #更新型号的审核状态
                    $tmp = [
                        "audit_quantity"=>0,
                        "inspect_quantity"=>0,
                        "serial_quantity"=>0,
                        "inspect_status"=>0,
                        "confirm_status"=>0,
                        "is_reject"=>1,
                        "audit_status"=>0,
                        "shelf_position"=>"",
                        //"attach_exceptional"=>??,
                        "is_audit"=>0,
                        "audit_note"=>$note,
                    ];

                    //$actualNum = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_id"=>$purchaseorder_id]); #统计实到件数

                    #删除附件
                    //$affect = \App\Services\Attachments::getInstance()->removeByCorrelateId($pod_ids);
                }

                #审核同意
                if($audit_satus == 2){
                    #更新所有型号的审核状态
                    $tmp = ["attach_exceptional"=>0,"audit_quantity"=>DB::raw('inspect_quantity'),"audit_status"=>$audit_satus,"is_audit"=>$is_audit,"audit_note"=>$audit_note,];
                }

                #审核同意/等待货齐/部分先发货，处理异常图片为正常
                if( in_array($audit_satus,[2,4,5]) && !empty($file_ids) ){
                    \App\Services\Attachments::getInstance()->updateByIds($file_ids,["flag"=>1]);
                }

                #部分驳回：4等待货齐,5部分先发货
                //$rejectModel: [{"order_info_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
                if( in_array($audit_satus, [4,5]))
                {
                    #处理驳回型号
                    if(!empty($rejectModel)){
                        foreach ($rejectModel as $obj){
                            if(empty($obj["serial_numbers"])) continue;
                            $orderInfo = $this->getCheckPi($obj["order_info_id"]); #当前单个产品

                            $serial_quantity = $orderInfo->serial_quantity;
                            $inspect_quantity = $orderInfo->inspect_quantity;
                            foreach ($obj["serial_numbers"] as $k=>$serial)
                            {
                                $ser = SerialNumbers::getInstance()->getSerialNumberById($serial["id"] );
                                if($serial["quantity"] >= $ser->quantity){  #驳回数量>=序列号数量
                                    $serial["quantity"] = $ser->quantity;
                                    $affect = SerialNumbers::getInstance()->updateSerial(["id"=>$serial["id"]],["status"=>-1]);
                                }
                                else{
                                    $sql = "UPDATE gd_serial_numbers SET quantity=GREATEST(quantity-?,0), inspect_quantity=GREATEST(inspect_quantity-?,0) where id=?";  #GREATEST()确保自减后最小值为0，不会出现负数
                                    DB::update($sql,[$serial["quantity"],$serial["quantity"],$serial["id"] ]);

                                    /*$tmp = [
                                        "quantity" => DB::raw('quantity')- $serial["quantity"],
                                        "inspect_quantity" => DB::raw('inspect_quantity') -$serial["inspect_quantity"],
                                    ];*/
                                    //$affect = SerialNumbers::getInstance()->updateSerial(["id"=>$serial["id"]],$tmp);
                                }
                                $serial_quantity -= $serial["quantity"];
                                $inspect_quantity -= $serial["quantity"];
                            }
                            $inspect_quantity = ($inspect_quantity >0) ? ($inspect_quantity):0;

                            #处理验货状态，确认状态,已审核数量？？
                            $status = ($inspect_quantity>0) ? 1: 0;   #已验货数量-驳回数量>0
                            $tmp = ["is_reject"=>1,"inspect_status"=>$status,"confirm_status"=>$status,"serial_quantity"=>$serial_quantity,"inspect_quantity"=>$inspect_quantity,"audit_quantity"=>$inspect_quantity,"audit_note"=>$audit_note,];

                            //var_dump($tmp);
                            $affect = $this->updateCheck("order_info_id",$obj["order_info_id"],$tmp);
                            //var_dump($affect);
                        }
                    }

                    #更新所有型号的审核状态
                    $tmp = ["audit_note"=>$audit_note,"audit_status"=>$audit_satus,"is_audit"=>$is_audit];
                    if($audit_satus != 3){
                        $tmp["attach_exceptional"] = 0;
                    }
                }
                $affect = $this->updateByIds($order_info_ids, $tmp);

                $this->db->commit();
                return $affect;
            }catch (\Throwable $e) {
                $this->db->rollBack();
                return false;
            }
        }

    }


    //编辑快递产品时：同步快递产品中的关联数量到核对里
    public function expressProductToCheck(int $checkId, $expOrder):int|null
    {
        #判断有没有图片异常
        $isExcept = Attachments::getInstance()->getExceptional($expOrder->order_item_id);

        //var_dump($expOrder);
        #根据采购人员分组获取关联数量
        $purcharselist = DB::table("gd_express_order")
            ->select([
                "purchaser_id",
                DB::raw('sum(quantity) as quantity'),
            ])
            ->where("order_item_id",$expOrder->order_item_id)->where("product_id",$expOrder->product_id)->groupBy("purchaser_id")->get()->toArray();
        $orderItem = OrdersItemInfo::getInstance()->getByIdAS($expOrder->order_item_id);

        $this->db->beginTransaction();
        try {
            #更新
            if($checkId){
                $check = $this->tb->where("id",$checkId)->first();
                #序列号：查找无序列号类型，添加或者编辑
                foreach ($purcharselist as $val){
                    $ser = SerialNumbers::getInstance()->getByType($expOrder->order_item_id,$val->purchaser_id);  //按采购ID来的
                    if($ser){
                        $param = [
                            "quantity" => $ser->quantity + $val->quantity,
                            "inspect_quantity" => $ser->inspect_quantity + $val->quantity,
                        ];
                        SerialNumbers::getInstance()->updateSerial(["purchaser_id"=>$val->purchaser_id,"order_info_id"=>$expOrder->order_item_id],$param);
                    }else{
                        $param = [
                            "id" => $this->get_unique_id(),
                            "user_id" => $expOrder->userID,
                            "serial_number" => "",
                            "quantity" => $val->quantity,
                            "type" => 3,
                            "inspect_quantity" => $val->quantity,
                            "order_id" => $expOrder->order_id,
                            "order_info_id" => $expOrder->order_item_id,
                            "purchaser_id"=>$val->purchaser_id,
                        ];
                        SerialNumbers::getInstance()->addSerialNumber($param);
                    }
                }
                #更新核对表的数量？？
                $confirm_status = (($check->serial_quantity+$expOrder->quantity) == $orderItem->quantity) ? 2 : 1;
                $tmp = [
                    "serial_quantity" => $check->serial_quantity + $expOrder->quantity,
                    "inspect_quantity" => $check->inspect_quantity + $expOrder->quantity,
                    "confirm_status" => $confirm_status,
                    "inspect_status" => $confirm_status,
                    //"submit_purcharse" =>1,
                ];
                if($isExcept){
                    $tmp["attach_exceptional"] =1;
                    $tmp["is_audit"] =0;
                    $tmp["audit_status"] =0;
                }
                $affect = $this->tb->where("id",$checkId)->update($tmp);
            }
            #新增
            else{
                #新增到序列号表
                foreach ($purcharselist as $val){
                    $param = [
                        "user_id" =>$expOrder->userID,
                        "serial_number" => "",
                        "quantity" => $val->quantity,
                        "type" => 3,
                        "inspect_quantity" => $val->quantity,
                        "audit_quantity" => $val->quantity,
                        "order_id" => $expOrder->order_id,
                        "order_info_id" => $expOrder->order_item_id,
                        "purchaser_id"=>$val->purchaser_id,
                    ];
                    SerialNumbers::getInstance()->addSerialNumber($param);
                }

                #新增到核对表
                $confirm_status = ($expOrder->quantity == $orderItem->quantity) ? 2 : 1;
                $checkparam = [
                    "user_id" => $expOrder->userID,
                    "serial_quantity" => $expOrder->quantity,
                    "inspect_quantity" => $expOrder->quantity,
                    "confirm_status" => $confirm_status,
                    "inspect_status" => $confirm_status,
                    "attach_exceptional" => 0,
                    "audit_status" => 1,
                    "is_audit" => 1,
                    "order_id" => $expOrder->order_id,
                    "order_info_id" => $expOrder->order_item_id,
                    //"submit_purcharse" =>1,
                ];
                if($isExcept){
                    $checkparam["attach_exceptional"] =1;
                    $checkparam["is_audit"] =0;
                    $checkparam["audit_status"] =0;
                }
                $affect = $this->addCheck($checkparam);
            }

            #更改按快递提交
            ExpressDelivery::getInstance()->updateExpressDelivery($expOrder->express_id, ["submit_purcharse" =>1]);

            $this->db->commit();
            return $affect;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }



}
