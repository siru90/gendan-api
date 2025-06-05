<?php

namespace App\Services\M;


use Illuminate\Support\Facades\DB;
use \App\Services\SerialNumbers;


class PurchaseOrder extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'purchaseorder';

    public function updatePurchaseOrder(int $id, array $values):int
    {
        if(isset($values['purchaseorder_id']))  unset($values['purchaseorder_id']);
        return $this->tb->where('purchaseorder_id', $id)->update($values);
    }

    public function getByIdAS($id): ?object
    {
        return $this->mtb->where('purchaseorder_id', $id)->where("Enable",0)->first();
    }

    public function getByIdF3($id): ?object
    {
        return $this->tb->select([
            'purchaseorder_id',
            "Purchaseordername",
            "order_id",
            "create_user",
            "create_time",
            //"audit_status",
            "submit_status",
            //"is_audit",
            "Comments",
            "State",
        ])
            ->where('purchaseorder_id', $id)->where("Enable",0)->first();
    }

    public function getByName($name): ?array
    {
        return $this->tb->select(['purchaseorder_id', "Purchaseordername", "order_id",])
            ->where('Purchaseordername',"like","%$name%")->where("Enable",0)->get()->toArray();
    }

    public function getPurchaseorderIdByName($name): array
    {
        return $this->tb->select("purchaseorder_id")
            ->where('Purchaseordername',"like","%$name%")->where("Enable",0)->pluck("purchaseorder_id")->toArray();
    }

    public function getByIdF1($id): ?object
    {
        return $this->tb->select('Purchaseordername')->where('purchaseorder_id', $id)->where("Enable",0)->first();
    }

    public function getByCrmPurchaseorderId($crm_purchaseorder_id):?object
    {
        if (empty($crm_purchaseorder_id)) return null;
        return $this->tb->select('purchaseorder_id')->where('crm_purchaseorder_id', $crm_purchaseorder_id)->where("Enable",0)->first();
    }

    public function getByOrderId($orderId):array
    {
        return $this->tb->select('purchaseorder_id','Purchaseordername',"order_id","create_time","create_user")
            ->where('order_id', "like", "%{$orderId}%")->where("Enable",0)
            ->where('State', "!=",9)  #过滤草稿的数据;
            ->orderByDesc("purchaseorder_id")->get()->toarray();
    }


    //po列表:  State '采购状态  1待付款  2已付款 3 到货中 4采购完毕, 6部分付款, 9草稿';
    public function poList(array $args)
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);

        $tb = $this->tb->from("purchaseorder as p")->where("p.Enable",0);
        $tb = $tb->where(function ($query) use ($args,$tb){
            if (!empty($args["sales_id"])) {
                $tb = $query->whereIn('o.Sales_User_ID', $args["sales_id"]);
                //$tb = $tb->whereIn('pod.sales_user_id', $args["sales_id"]);  #已弃用
            }
            if (!empty($args["purchaser_id"])) {
                $tb = $tb->where('pod.Purchaser_id', $args["purchaser_id"]);
            }
            if (!empty($args["state"])) {
                $tb = $tb->where('p.State', $args["state"]);
            }else{
                $tb = $tb->where('p.State', "!=",9);  #过滤草稿的数据
            }
            if (!empty($args["start_time"]) && !empty($args["end_time"])) {
                $tb = $tb->whereBetween('p.create_time', [$args["start_time"],$args["end_time"]]);
            }

            if(!empty($args["keyword"])){
                if($args["keyword_type"] == "model"){
                    $tb = $tb->where("pod.Model","like","%{$args["keyword"]}%");
                }
                elseif($args["keyword_type"] == "pi"){
                    $tb = $tb->where("o.PI_name","like","%{$args["keyword"]}%");
                }
                else{
                    $tb = $tb->where("Purchaseordername", "like", "%{$args["keyword"]}%");
                }
            }
        });
        if(!empty($args["user_id"])){
            $tb = $tb->whereIn('pod.Purchaser_id', $args["user_id"]);
        }
        $condition = (!empty($args["keyword_type"]) && $args["keyword_type"] == "model");
        if($condition  || !empty($args["purchaser_id"]) || !empty($args["user_id"])){
            $tb = $tb->join("purchaseorder_detailed as pod", "pod.Purchaseorder_id", "p.purchaseorder_id");
        }
        if(!empty($args["keyword"]) && $args["keyword_type"] == "pi" || !empty($args["sales_id"])){
        //if(!empty($args["sales_id"]) || !empty($args["user_id"]) || !empty($args["keyword"])){
            //$tb = $tb->join("orders as o", "o.order_id", "pod.order_id");
            $tb = $tb->join("orders as o", "o.order_id", "p.order_id");
        }


        $orderStr = "p.create_time";

        $totalTb = clone $tb;
        $tb = $tb->select([
            "p.purchaseorder_id",
            "p.create_user",
            "p.Purchaseordername",
            "p.create_time",
            "p.State",    #采购状态  1代付款  2已付款 3 到货中 4采购完毕, 6部分付款, 9草稿
        ])
            ->distinct() ->orderByDesc($orderStr);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->offset($offset)->limit($args["size"])->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT p.purchaseorder_id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    //状态更新为采购完毕
    public function updateAllStateToComplete($purchaseorder_id,$purchaseordertesk_id)
    {
        # 更新采购订单，采购订单详单状态为：采购完毕
        #  `State` int(11) NOT NULL COMMENT '采购状态  1代付款  2已付款 3 到货中 4采购完毕 ',

        #更新采购任务，采购任务详细状态为：
        #  `State` int(11) NOT NULL COMMENT '采购任务状态   1待采购  2采购中  3采购完成',

        //$State=4
        $this->db->beginTransaction();
        try {
            $affected = $this->mtb->where('purchaseorder_id', $purchaseorder_id)->whereIn('State', [1, 2, 3])
                ->update(['State' => 4,]);

            PurchaseOrderDetailed::getInstance()->tb->where('purchaseorder_id', $purchaseorder_id)->whereIn('State', [1, 2, 3])
                ->update([
                    'State' => 4,
                ]);

            PurchaseOrderTask::getInstance()->tb
                ->where('Purchaseordertesk_id', $purchaseordertesk_id)
                ->update([
                    'State' => 3,
                ]);

            PurchaseOrderTaskDetailed::getInstance()->tb
                ->where('Purchaseordertesk_id', $purchaseordertesk_id)
                ->update([
                    'State' => 3,
                ]);

            $this->db->commit();
            return $affected;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }

    }


    //部分状态更新为采购完毕
    public function updatePartStateToComplete($purchaseorder_id,$podId,$purchaseordertesk_id,$Purchaseordertesk_detailed_id,$state)
    {
        # 更新采购订单，采购订单详单状态为：采购完毕
        #  `State` int(11) NOT NULL COMMENT '采购状态  1代付款  2已付款 3 到货中 4采购完毕 ',

        #更新采购任务，采购任务详细状态为：
        #  `State` int(11) NOT NULL COMMENT '采购任务状态   1待采购  2采购中  3采购完成',

        $temp = 3;
        $this->db->beginTransaction();
        try {
            DB::connection()->enableQueryLog();
            $affected = $this->tb->where('purchaseorder_id', $purchaseorder_id)->update(['State' => 3]);

            PurchaseOrderDetailed::getInstance()->tb->where('Purchaseorder_detailed_id', $podId)
                ->update([
                    'State' => $state,
                ]);

            PurchaseOrderTask::getInstance()->tb
                ->where('Purchaseordertesk_id', $purchaseordertesk_id)
                ->update([
                    'State' => 2,
                ]);

            if($state == 3) $temp = 2;
            PurchaseOrderTaskDetailed::getInstance()->tb->where('Purchaseordertesk_detailed_id', $Purchaseordertesk_detailed_id)
                ->update([
                    'State' => $temp,
                ]);

            $this->db->commit();
            return $affected;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }


    //待处理数量：PO列表，check列表
    public function getUnConfirmedCount($user_id,$args):int
    {
        $tb = $this->tb->from("purchaseorder as p")
            ->selectRaw('COUNT(DISTINCT p.purchaseorder_id) count')
            ->where("p.Enable",0);

        if(!empty($user_id)){
            $tb = $tb->whereIn('pod.Purchaser_id', $user_id);
        }

        # PO待处理数量，即除了采购完成状态(4)的采购订单之外的所有
        if(!empty($args["state"])){
            $tb = $tb->where("p.State", "!=", $args["state"]);
        }

        //核对待处理数量，包含未确认、部分确认、未提交、未验货、部分验货、待审核
        if(!empty($args["uncheck"])){
            $tb = $tb->where(function ($query) use ($args,$tb){
                $tb = $query->where('pod.confirm_status', "!=", 2)   #确认状态：0未确认，1部分确认，2已确认
                        ->orWhere('pod.inspect_status',"!=",2)       #验货状态：0未验货，1部分验货，2已验货
                        ->orWhere('p.submit_status',"=",0)           #提交状态：0未提交，1已提交
                        ->orWhere('pod.is_audit',"=",0);               #是否审核：0未审核，1已审核
            });
        }

        if(!empty($user_id) || !empty($args["uncheck"])){
            $tb = $tb->join("purchaseorder_detailed as pod", "pod.Purchaseorder_id", "p.purchaseorder_id");
        }

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $totalResult = $tb->first();
        $total = $totalResult->count ?? 0;
        return $total;
    }


    //核对列表
    public function checkList(array $args)
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);
        $tb = $this->tb->from("purchaseorder as p")->where("p.Enable",0);

        $orderStr = "p.Update_tiem";  //排序规则：有关键词按采购订单创建时间正序排列，无关键词按最新更新时间

        $tb = $tb->where(function ($query) use ($args,$tb){
            if (!empty($args["sales_id"])) {
                //$tb = $tb->whereIn('o.Sales_User_ID', $args["sales_id"]);
                $tb = $tb->whereIn('pod.sales_user_id', $args["sales_id"]);
            }
            if (!empty($args["purchaser_id"])) {
                $tb = $tb->where('pod.Purchaser_id', $args["purchaser_id"]);
            }
            if (!empty($args["state"])) {
                $tb = $tb->where('p.State', $args["state"]);
            }
            else{
                $tb = $tb->where('p.State', "!=",9);  #过滤草稿的数据
            }
            if (!empty($args["start_time"]) && !empty($args["end_time"])) {
                $tb = $tb->whereBetween('p.create_time', [$args["start_time"],$args["end_time"]]);
            }
            if(!empty($args["keyword"])){
                $orderStr = "p.create_time";

                if($args["keyword_type"] == "po"){
                    $tb = $tb->where("p.Purchaseordername", "like", "%{$args["keyword"]}%");
                }
                elseif($args["keyword_type"] == "pi"){
                    $tb = $tb->where("o.PI_name","like","%{$args["keyword"]}%");
                }
                else{
                    //效果不大
                    //$productIDs= DB::table("purchaseorder_detailed")->select('products_id')->where("Model","like","%{$args["keyword"]}%")->pluck("products_id")->toArray();
                    //$tb = $tb->whereIn("pod.products_id",$productIDs);

                    //var_dump($productIDs);
                    $tb = $tb->where("pod.Model","like","%{$args["keyword"]}%");
                }
            }
            else{
                $tb = $query->whereNull("o.status")->orWhere("o.status","!=",3);
            }
            if (isset($args["confirm_status"])) {
                $tb = $tb->where('pod.confirm_status', $args["confirm_status"]);
            }
            if (isset($args["inspect_status"])) {
                $tb = $tb->where('pod.inspect_status', $args["inspect_status"]);
            }
            if (isset($args["submit_status"])) {
                $tb = $tb->where('p.submit_status', $args["submit_status"]);
            }
            if (isset($args["is_audit"])) {   //0待审核，1已审核
                //$tb = $query->where('pod.is_audit', $args["is_audit"]); //0待审核，1已审核
                if($args["is_audit"] ==0) $tb = $query->where('pod.attach_exceptional', 1); #0无异常，1异常
                else  $tb = $query->where('pod.is_audit', $args["is_audit"])->where('pod.audit_status', "!=",1); //0待审核，1已审核
            }
        });
        if(!empty($args["user_id"])){
            //$tb = $tb->whereIn('u.id', $args["user_id"]);
            //$tb = $tb->join("user as u", "u.id","pod.Purchaser_id");
            $tb = $tb->whereIn('pod.Purchaser_id', $args["user_id"]);
        }

        $condition = !empty($args["sales_id"]) || !empty($args["user_id"]) || !empty($args["purchaser_id"])|| isset($args["confirm_status"]) || isset($args["inspect_status"]) || isset($args["is_audit"]) ||!empty($args["keyword"]);
        if($condition){
            $tb = $tb->join("purchaseorder_detailed as pod", "pod.Purchaseorder_id", "p.purchaseorder_id");
        }
        //if(!empty($args["sales_id"]) || !empty($args["user_id"]) || !empty($args["keyword"])){
        if( empty($args["keyword"]) ||  (!empty($args["keyword"]) && $args["keyword_type"] == "pi") ){
            //$tb = $tb->join("orders as o", "o.order_id", "pod.order_id");
            $tb = $tb->join("orders as o", "o.order_id", "p.order_id");
        }


        $totalTb = clone $tb;
        $tb = $tb->select([
            "p.purchaseorder_id",
            "p.create_user",
            "p.Purchaseordername",
            "p.create_time",
            "p.Update_tiem",
            "p.State",    #采购状态  1代付款  2已付款 3 到货中 4采购完毕, 6部分付款, 9草稿
            "p.order_id",
            "p.submit_status",
            "p.arrival_qty",
            //"p.audit_status",
            //"p.is_audit",
        ])
            ->distinct();

        if(!empty($args["keyword"]))  $tb = $tb->orderBy($orderStr,"asc");
        else $tb = $tb->orderByDesc($orderStr);

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->offset($offset)->limit($args["size"])->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT p.purchaseorder_id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }


    //验货：更新审核状态
    public function updateAuditStatus($is_exceptional,$poId,$modelList)
    {
        //is_exceptional: 0没异常，1有异常(包括图片、数量异常)
        // purchaseorder表，audit_status '审核状态：0待审核,1无需审核,2审核同意,3整单驳回,4等待货齐,5部分先发货'
        // purchaseorder表，is_audit  是否审核：0未审核，1已审核
        //purchaseorder_detailed, inspect_status 验货状态：0未验货，1部分验货，2已验货
        //gd_serial_numbers `inspect_quantity` COMMENT '待验货数量';
        //gd_serial_numbers `audit_quantity` COMMENT '待审核数量';

        $this->db->beginTransaction();
        try {
            #每次提交验货，初始化审核状态, 驳回状态
            $poParam = ["is_audit" => 0, "audit_status" => 0, "Comments"=>""];
            if($is_exceptional ==0){ #没有异常，无需审核
                $poParam = ["is_audit" => 1, "audit_status" => 1];
            }
            //$res = $this->updatePurchaseOrder($poId,$poParam);

            $this->updatePurchaseOrder($poId,["Comments"=>"","Update_tiem"=>date("Y-m-d H:i:s")]);
            $res = PurchaseOrderDetailed::getInstance()->updateModelByPoId($poId,["is_reject"=>0]);

            #处理当前验货数据
            foreach ($modelList as $obj)
            {
                if($is_exceptional ==0) { #没有异常，已审核数量=验货数量
                    $obj["audit_quantity"] = $obj["inspect_quantity"];
                }
                PurchaseOrderDetailed::getInstance()->updateModel($obj["purchaseorder_detailed_id"],$obj);

                /*
                $param = ["audit_quantity"=>DB::raw('inspect_quantity+audit_quantity'),"inspect_quantity"=>0];   #inspect_quantity先用于加，再清0
                if($obj["audit_status"]==1){
                    $param["audit_quantity"] = 0;
                }
                */
                $param = ["inspect_quantity"=>0]; //去掉了审核数量
                SerialNumbers::getInstance()->updateSerial(["purchaseorder_detailed_id"=>$obj["purchaseorder_detailed_id"]],$param);
            }
            $this->db->commit();
            return $res;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }

    }



    //审核提交，驳回型号数组
    public function auditSubmit($args):int|null
    {
        //purchaseorder `submit_status`  '提交状态：0未提交，1已提交';
        //purchaseorder `audit_status`  '审核状态：0待审核,1无需审核,2审核同意,3整单驳回,4等待货齐,5部分先发货';
        //purchaseorder `is_audit`  '是否审核：0未审核，1已审核';
        //purchaseorder_detailed `inspect_quantity`  '已验货的数量';
        //purchaseorder_detailed `audit_quantity`   '已审核的数量';
        //purchaseorder_detailed  `inspect_status`  '验货状态：0未验货，1部分验货，2已验货';
        //purchaseorder_detailed  `confirm_status`  '确认状态：0未确认，1部分确认，2已确认';
        //purchaseorder_detailed `is_reject`   '是否驳回：0无,1是';
        //gd_serial_numbers `reject_quantity`  '驳回数量';

        $purchaseorder_id = 0; $operate = $audit_satus = $note = $rejectModel= $file_ids = null;
        $pod_ids = array();
        extract($args);
        $affect = null;

        #初始化，清除所有序列号的驳回数量,待审核数量
        SerialNumbers::getInstance()->updateSerial(["pod_ids"=>$pod_ids],["reject_quantity"=>0,"audit_quantity"=>0]);
        PurchaseOrderDetailed::getInstance()->updateByIds($pod_ids,["is_reject"=>0]);


        #审核保存
        if($operate == "save"){
            $this->db->beginTransaction();
            try {
                #整单驳回
                if($audit_satus == 3)
                {
                    #保存序列号的驳回数量，采购订单审核状态
                    $sql = "UPDATE gd_serial_numbers SET reject_quantity=quantity where purchaseorder_id=?";
                    DB::update($sql,[$purchaseorder_id]);
                }
                #部分驳回：4等待货齐,5部分先发货
                //$rejectModel: [{"pod_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
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
                $tmp = ["Comments"=>$note??""];
                $affect = PurchaseOrder::getInstance()->updatePurchaseOrder($purchaseorder_id, $tmp);
                $affect = PurchaseOrderDetailed::getInstance()->updateByIds($pod_ids,["is_reject"=>0,"audit_status"=>$audit_satus]);

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
                #整单驳回，所有序列号删除，已验收数量为0, 审核置为待审核, 整个采购单变为未提交
                if($audit_satus == 3)
                {
                    #删除所有序列号
                    SerialNumbers::getInstance()->updateSerial(["pod_ids"=>$pod_ids],["status"=>-1,"reject_quantity"=>0]);

                    #更新主表审核状态
                    $actualNum = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_id"=>$purchaseorder_id]); #统计实到件数
                    $affect = PurchaseOrder::getInstance()->updatePurchaseOrder($purchaseorder_id, ["arrival_qty"=>$actualNum,"submit_status"=>$submit_status,"Comments"=>$note?:"","Update_tiem"=>date("Y-m-d H:i:s")]);

                    #更新所有型号的审核状态
                    $tmp = ["audit_quantity"=>0,"inspect_quantity"=>0,"inspect_status"=>0,"confirm_status"=>0,"is_reject"=>1,"audit_status"=>0,"is_audit"=>0];
                    $affect = PurchaseOrderDetailed::getInstance()->updateByIds($pod_ids,$tmp);

                    #删除附件
                    //$affect = \App\Services\Attachments::getInstance()->removeByCorrelateId($pod_ids);
                }


                #审核同意
                if($audit_satus == 2){
                    #更新主表审核状态
                    $affect = PurchaseOrder::getInstance()->updatePurchaseOrder($purchaseorder_id, ["Comments"=>$note?:"","Update_tiem"=>date("Y-m-d H:i:s")]);

                    #更新所有型号的审核状态
                    $tmp = ["attach_exceptional"=>0,"audit_quantity"=>DB::raw('inspect_quantity'),"audit_status"=>$audit_satus,"is_audit"=>$is_audit];
                    $affect = PurchaseOrderDetailed::getInstance()->updateByIds($pod_ids,$tmp);
                }

                #审核同意/等待货齐/部分先发货，处理异常图片为正常
                if( in_array($audit_satus,[2,4,5]) && !empty($file_ids) ){
                    \App\Services\Attachments::getInstance()->updateByIds($file_ids,["flag"=>1]);
                }

                #部分驳回：4等待货齐,5部分先发货
                //$rejectModel: [{"pod_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
                if( in_array($audit_satus, [4,5]))
                {
                    #处理驳回型号
                    if(!empty($rejectModel)){
                        foreach ($rejectModel as $obj){
                            if(empty($obj["serial_numbers"])) continue;
                            $podInfo = PurchaseOrderDetailed::getInstance()->getInfo($obj["pod_id"]); #当前单个产品

                            $inspect_quantity = $podInfo->inspect_quantity;
                            foreach ($obj["serial_numbers"] as $k=>$serial)
                            {
                                $ser = SerialNumbers::getInstance()->getSerialNumberById($serial["id"] );
                                if($serial["quantity"] >= $ser->quantity){  #驳回数量>=序列号数量
                                    $serial["quantity"] = $ser->quantity;
                                    $affect = SerialNumbers::getInstance()->updateSerial(["id"=>$serial["id"]],["status"=>-1]);
                                }
                                else{
                                    $sql = "UPDATE gd_serial_numbers SET quantity=GREATEST(quantity-?,0) where id=?";  #GREATEST()确保自减后最小值为0，不会出现负数
                                    DB::update($sql,[$serial["quantity"],$serial["id"] ]);
                                }
                                $inspect_quantity -= $serial["quantity"];
                            }
                            $inspect_quantity = ($inspect_quantity >0) ? ($inspect_quantity):0;


                            #处理验货状态，确认状态,已审核数量？？
                            $status = ($inspect_quantity>0) ? 1: 0;   #已验货数量-驳回数量>0
                            //$sql = "UPDATE purchaseorder_detailed SET is_reject=?,inspect_status=?,confirm_status=?,inspect_quantity=? where Purchaseorder_detailed_id=?";
                            //DB::update($sql,[1,$status,$status,$inspect_quantity,$obj["pod_id"] ]);
                            $tmp = ["is_reject"=>1,"inspect_status"=>$status,"confirm_status"=>$status,"inspect_quantity"=>$inspect_quantity,"audit_quantity"=>$inspect_quantity];

                            //var_dump($tmp,$obj["pod_id"]);echo"--";
                            $affect = PurchaseOrderDetailed::getInstance()->updateModel($obj["pod_id"],$tmp);
                            //var_dump($affect);
                        }
                    }

                    #更新主表审核状态
                    $actualNum = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_id"=>$purchaseorder_id]); #统计实到件数
                    $affect = PurchaseOrder::getInstance()->updatePurchaseOrder($purchaseorder_id, ["arrival_qty"=>$actualNum,"Comments"=>$note?:"","Update_tiem"=>date("Y-m-d H:i:s")]);

                    #更新所有型号的审核状态
                    $affect = PurchaseOrderDetailed::getInstance()->updateByIds($pod_ids,["attach_exceptional"=>0,"audit_status"=>$audit_satus,"is_audit"=>$is_audit]);
                }

                $this->db->commit();

                return $affect;
            }catch (\Throwable $e) {
                $this->db->rollBack();
                return false;
            }
        }


    }





}
