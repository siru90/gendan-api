<?php

namespace App\Http\Controllers;

use App\Ok\Enum\CheckStatus;
use App\Ok\Enum\DbStatus;
use App\Ok\Enum\SoState;
use App\Ok\Enum\AuditSatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

use \App\Http\Middleware\UserId;
use  \App\Services\M\ShipTask;
use \App\Services\M\ShipTaskItem;
use \App\Services\Message;
use \App\Services\M\Orders;
use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\SoStatus;
use \App\Services\Attachments;
use \App\Services\Oplog;
use \App\Services\M\ShipTaskPackage;
use \App\Services\OplogApi;
use \App\Services\M\OrdersItemInfo;
use \App\Services\ExpressOrders;
use \App\Services\ShiptaskSubmit;
use \App\Services\SerialNumbers;
use \App\Services\M\IhuProductSpecs;
use \App\Services\M\CustomerInfo;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;




//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class SoController extends Controller
{
    #配置RabbitMq的队列，交换机，路由key
    protected string $queue;
    protected string $exchange;
    protected string $routeKey;
    //public static $numMap=array();

    public function __construct(){
        $so_queue = \Illuminate\Support\Facades\Config::get('app.so_rabbitmq');
        $this->queue = $so_queue["queue"];
        $this->exchange = $so_queue["exchange"];
        $this->routeKey = $so_queue["routeKey"];
    }

    //获取待处理数量
    public function unConfirmed(Request $request): \Illuminate\Http\JsonResponse
    {
        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin(\App\Http\Middleware\UserId::$user_id);

        # 待处理总数
        $data = new \stdClass();
        $data->unConfirmed = ShipTask::getInstance()->getUnConfirmedCount($userInfo?:null);
        return $this->renderJson($data);
    }


    //新的sO列表
    public function soList(Request $request): \Illuminate\Http\JsonResponse
    {
        $limitSize = 100;
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'sales_id' => 'integer|gt:0',
                'purchaser_id' => 'integer|gt:0',
                'state' => [new Enum(SoState::class),],
                //'submit_status' => [new Enum(SubmitStatus::class),],
                'so_status' => 'integer|gt:0',  //1总务跟单操作、2总务核对操作、3打包操作、4等待发货
                'keyword' => 'string',  // 默认显示PI，可切换SO、型号
                'start_time' => 'string',
                'end_time' => 'string',
                'keyword_type' => 'string', //默认显示PI，可切换SO、型号
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if(!empty($validated["so_status"]) && !in_array($validated["so_status"], [1,2,3,4])){
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $data = new \stdClass();
        $data->list = []; $data->total = 0;
        $validated['order_item_id'] = $validated['shiptask_id'] = [];

        # keyword: PI_name
        if(!empty($validated["keyword"]) && $validated["keyword_type"] == "pi"){
            $res = Orders::getInstance()->searchOrderId($validated["keyword"]);
            foreach ($res as $obj){
                $validated['order_item_id'][] = $obj->order_info_id;
            }
            unset($validated["keyword"]);
            if(empty($validated['order_item_id'])) return $this->renderJson($data);
        }

        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin(\App\Http\Middleware\UserId::$user_id);
        if($userInfo){
            $validated["user_id"] = $userInfo;
        }

        #获取发货任务
        [$data->list,$data->total] = ShipTask::getInstance()->soList($validated);

        $ff = [
            0 => '预发',
            1 => '待发货',
            2 => '已发货',
            3 => '到货中',
            4 => '到货完毕',
            5 => '暂无记录',
            6 => '在途中',
            7 => '派送中',
            8 => '已签收 (完结状态)',
            9 => '用户拒签',
            10 => '疑难件',
            11 => '无效单 (完结状态)',
            12 => '超时单',
            13 => '签收失败',
            14 => '退回',
            15 => '关闭',
        ];

        foreach ($data->list as $item) {
            //$item->state_txt = $ff[$item->State] ?? '';
            $item->pis = $item->sales = [];
            # 获取所有的PI单
            $orders = Orders::getInstance()->getByIdsF1(explode(",", $item->order_id));
            # 补齐create_user对应的信息，放入$data->list['purchaser']字段里
            MissuUser::getInstance()->fillUsers($orders, 'Sales_User_ID', 'sales');

            # 得到所有的PI_name, sales
            $sales = [];
            foreach ($orders as $v){
                $item->pis[] = $v->PI_name;
                if(!isset($sales[$v->sales->id])){
                    $item->sales[] = $v->sales;
                    $sales[$v->sales->id] = $v->sales;
                }
            }
            #获取当前SO的流程状态
            $item->soStatus = SoStatus::getInstance()->getStatus($item->Shiptask_id);
            if (!$item->soStatus) {
                SoStatus::getInstance()->setSoStatus($item->Shiptask_id, []);
                $item->soStatus = SoStatus::getInstance()->getStatus($item->Shiptask_id);
            }
        }

        //var_dump($data);
        return $this->renderJson($data);
    }

    // SO详情
    public function soInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ShipTask::getInstance()->getByIdF1($validated['id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $data->pack_user_id = explode(",", $data->pack_user_id);
        $data->soStatus = SoStatus::getInstance()->getSoStatus($validated['id']);
        if (!$data->soStatus) {
            SoStatus::getInstance()->setSoStatus($validated['id'], []);
            $data->soStatus = SoStatus::getInstance()->getSoStatus($validated['id']);
        }
        $packList = new \stdClass(); $packList->total = $packList->packableQuantity = 0;

        # 根据SO找对应的PI单
        $ids = array_filter(explode(',', $data->order_id));
        $list = Orders::getInstance()->getByIdsF1($ids);

        $soItemIds = [];
        foreach ($list as $obj){
            # 获取订单id下的本发货单的任务详细
            $obj->items = ShipTaskItem::getInstance()->getShipItemByOrderId($obj->order_id,$data->Shiptask_id);
            $obj->check_status = 0;  # 缺货\货齐 状态： 0 缺货 1 货齐

            foreach ($obj->items as $item) {
                $soItemIds[] = $item->ShioTask_item_id;
                # 获取对应的sku，规格
                $item->specs = IhuProductSpecs::getInstance()->getByProductId($item->products_id);
                # 获取对应的采购订单详细
                $item->pods = PurchaseOrderDetailed::getInstance()->getPurchaseOrderByOrderInfoId($item->order_info_id);
                # 补齐采购id的用户信息
                MissuUser::getInstance()->fillUsers($item->pods, 'Purchaser_id');
                $podIds = $auditIds =[];
                foreach ($item->pods as $pod) {
                    //if($pod->is_audit && in_array($pod->audit_status, [1,2,5])){  //audit_status 审核状态：0待审核,1无需审核,2审核同意,3整单驳回,4等待货齐,5部分先发货
                    //    $auditIds[] = $pod->Purchaseorder_detailed_id;  #拿到已审核的采购详单的ID
                    //}
                    $podIds[] = $pod->Purchaseorder_detailed_id;  #拿到所有采购详单的ID
                }
                #销售审核通过(无需审核，审核同意，部分先发货)的数量,已验货数量
                //$item->checkQuantity = PurchaseOrderDetailed::getInstance()->getAuditQuantity($auditIds);
                #货齐or货缺
                //if($item->checkQuantity < $item->Qtynumber) $obj->check_status=0;

                #获取异常且同意的图片,采购单详单
                $item->abnormalAttach = Attachments::getInstance()->getAttachmentsByPodId( $podIds,1 );

                #获取发货详单图片
                $item->attachments = Attachments::getInstance()->getAttachments( $item->ShioTask_item_id,5);

                # 获取SO详单，采购详单(组合查询) 的提交记录
                $item->shiptaskSubmit = ShiptaskSubmit::getInstance()->getByItemId($item->ShioTask_item_id);

                //-获取打包总数---
                $packList->total += $item->Qtynumber;  #打包总数
            }
        }

        #获取打包数据
        $tmp = ShiptaskSubmit::getInstance()->getSumBySoId($data->Shiptask_id);
        $packList->packableQuantity = $tmp->delivery_num; #可打包的数量
        $packList->attachments = Attachments::getInstance()->getSoAttachments($data->Shiptask_id);
        $packList->packs = ShipTaskPackage::getInstance()->getPacks($data->Shiptask_id);

        $userMap = [];
        $Itemlist = ShipTaskItem::getInstance()->geitemtByIds($soItemIds);
        foreach ($Itemlist as $val){
            $submit = \App\Services\ShiptaskSubmit::getInstance()->getItemNum($val->ShioTask_item_id);
            $val->reduce_num = $val->delivery_num = isset($submit->delivery_num) ? $submit->delivery_num :0;  #实到数量
            $userMap[$val->ShioTask_item_id] = $val;
        }

        $packageNum =0;
        foreach ($packList->packs as $pack){
            $packageNum += $pack->package_quantity;

            # shiptask_item_id格式: [{"id":"","num":"","specs_id":""}]
            $pack->shiptask_item_id = json_decode($pack->shiptask_item_id);
            if($pack->shiptask_item_id){
                //????
                ShipTaskItem::getInstance()->fillModel($pack->shiptask_item_id,$userMap);
            }
        }
        /*#实际打包数量 != 可打包数量
        if($packageNum != $packList->packableQuantity){
            $da_status=1;
            if($packageNum ==0){
                $da_status=0;
            }
            SoStatus::getInstance()->updateSoStatus($data->soStatus->id, ["db_status"=>$da_status]);
        }*/

        $data->list = $list;
        $data->packageList = $packList;
        return $this->renderJson($data);
    }

    // so附件
    public function soAttach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ShipTask::getInstance()->getByIdF1($validated['id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $data = new \stdClass();
        $data->soItemIds = ShipTaskItem::getInstance()->returnItemIds($validated["id"]);

        #获取发货详单图片
        $soItemAttach = Attachments::getInstance()->getAttachmentsBySOId($data->soItemIds);
        $data->soAttach = [];
        foreach ($soItemAttach as $k =>$obj){
            if(empty($data->soAttach[$obj->correlate_id])) $data->soAttach[$obj->correlate_id]=[];
            $data->soAttach[$obj->correlate_id][] = $obj;
        }
        unset($soItemAttach);

        return $this->renderJson($data);
    }

    // 获取SO的提交信息
    public function shiptaskSubmit(object $data)
    {
        # 根据SO找对应的PI单
        $ids = array_filter(explode(',', $data->order_id));
        $list = Orders::getInstance()->getByIdsF1($ids);

        $isExistNewSubmit = 0;  #是否存在新快递:0否，1是；存在新快递需要重新审核

        foreach ($list as $obj){
            # 获取订单id下的本发货单的任务详细
            $obj->items = ShipTaskItem::getInstance()->getShipItemByOrderId($obj->order_id,$data->Shiptask_id);

            foreach ($obj->items as $item) {
                //$item->serial_numbers = json_decode($item->serial_numbers,true);

                # 获取对应的sku，规格
                $item->specs = IhuProductSpecs::getInstance()->getByProductId($item->products_id);

                # 获取对应的采购订单详细
                $item->pods = PurchaseOrderDetailed::getInstance()->getPurchaseOrderByOrderInfoId($item->order_info_id);

                # 补齐采购id的用户信息
                MissuUser::getInstance()->fillUsers($item->pods, 'Purchaser_id');

                foreach ($item->pods as $pod) {
                    $pod->isExistNewSubmit = 0;
                    #采购单详单，对应的所有快递产品存在异常图片的
                    $pod->abnormalAttach = ExpressOrders::getInstance()->getAttachmentByFlag( [$pod->Purchaseorder_detailed_id] );
                    # 附件：图片,视频
                    $pod->attachments = Attachments::getInstance()->getAttachmentsByPodId( [$pod->Purchaseorder_detailed_id] ,1);

                    # 获取SO详单，采购详单(组合查询) 的提交记录
                    $pod->shiptaskSubmit = ShiptaskSubmit::getInstance()->getByItemAndPod($item->ShioTask_item_id,$pod->Purchaseorder_detailed_id);

                    # 如果SO已经发货了，实到数量不会再变更
                    if($data->State >1){
                        $pod->realisticToQuantity = $pod->shiptaskSubmit->real_quantity ?? 0;
                    }
                    else
                    {
                        #如果SO还没发货，查最新的快递和提交记录
                        $newExpressProductId = []; $newQuantity = [];

                        $pod->actualQuantity = ExpressOrders::getInstance()->getSumSubmittedQuantity($pod->Purchaseorder_detailed_id);  #快递实到数量
                        $pod->usedQuantity = ShiptaskSubmit::getInstance()->getEnterQuantity($data->Shiptask_id,$pod->Purchaseorder_detailed_id);  # 在其他So里已用的数量
                        $pod->residueQuantity = $pod->actualQuantity - $pod->usedQuantity;  #剩余数量

                        #采购详单对应的快递产品（所有已预交的）
                        $pod->expressProductID = $this->getExpressProductID($pod->Purchaseorder_detailed_id);

                        if(!empty($pod->shiptaskSubmit)){
                            $pod->shiptaskSubmit->express_product_id = !empty($pod->shiptaskSubmit->express_product_id) ? explode(",", $pod->shiptaskSubmit->express_product_id) : [];

                            #判断是否有新快递
                            $newExpressProductId = array_diff($pod->expressProductID, $pod->shiptaskSubmit->express_product_id);
                            if(!empty($newExpressProductId)) {
                                $pod->isExistNewSubmit = 1;
                                $isExistNewSubmit = 1;
                            }

                            #判断旧快递是否有新提交数量
                            $newQuantity = SerialNumbers::getInstance()->getNewQuanitty($pod->shiptaskSubmit->express_product_id);

                            $pod->realisticToQuantity = $pod->shiptaskSubmit->real_quantity;  #实到数量，等于上一次总务核对提交的数量（妥交+内退）
                            if($isExistNewSubmit ==1){  #当有新快递，加上新快递到的数量
                                $num = ExpressOrders::getInstance()->getSumSubmitNum($newExpressProductId);
                                $pod->realisticToQuantity += $num;

                                #销售驳回部分型号后，对应的驳回型号在SO核对流程详情页标红，实到、妥交、内退数量暂时保存
                                #直到该新快递再次提交后实到数量变更为最新的数量，妥交数量保留且不能减少，内退数量清0
                                if($data->soStatus->audit_satus==1 && $item->audit_satus ==2){
                                    $pod->shiptaskSubmit->old_return_num = $pod->shiptaskSubmit->return_num;
                                    $pod->shiptaskSubmit->return_num = 0;
                                }
                            }
                            if(count($newQuantity)){
                                $pod->isExistNewSubmit = 1;
                                $isExistNewSubmit =1;  # 1表示有新的提交，前端根据这个放开总务核对提交按钮，可以重新审核
                                foreach ($newQuantity as $obj){
                                    $pod->realisticToQuantity += $obj->new_quantity; # 再加上旧快递新提交的数量
                                }

                                #销售驳回部分型号后
                                #直到该新快递再次提交后实到数量变更为最新的数量，妥交数量保留且不能减少，内退数量清0
                                if($data->soStatus->audit_satus==1 && $item->audit_satus ==2){
                                    $pod->shiptaskSubmit->old_return_num = $pod->shiptaskSubmit->return_num;
                                    $pod->shiptaskSubmit->return_num = 0;
                                }
                            }
                        }
                        else{
                            #之前没有提交记录，现在又存在快递，表示当前快递没有提交过是新快递
                            if(count($pod->expressProductID)){
                                $isExistNewSubmit =1;
                            }
                            #实到数量，没有总务核对提交记录，则按剩余数量和型号数量对比，谁小取谁
                            $pod->realisticToQuantity = ($pod->residueQuantity > $item->Qtynumber) ? $item->Qtynumber : $pod->residueQuantity;
                        }
                    }

                }
            }
        }

        return [$isExistNewSubmit,$list];
    }

    //私有函数，获取采购详单ID 关联的产品ID（查已预交的产品）
    private function getExpressProductID($purchaseorder_detailed_id):array
    {
        $expressProductID = [];
        $express = ExpressOrders::getInstance()->getExpressOrderByPodID($purchaseorder_detailed_id);
        foreach ($express as $obj){
            $expressProductID[] = $obj->product_id;
        }
        return $expressProductID;
    }

    // 打包操作列表：获取打包信息
    public function packageList(object $data)
    {
        $res = new \stdClass();
        $res->attachments = Attachments::getInstance()->getSoAttachments($data->Shiptask_id);
        $res->packs = ShipTaskPackage::getInstance()->getPacks($data->Shiptask_id);
        $item = ShipTaskItem::getInstance()->getItems($data->Shiptask_id);

        #统计实到数量
        $res->total = $res->packableQuantity = 0;
        foreach ($item as $obj){
            $tmp = ShiptaskSubmit::getInstance()->getItemRealQuantiy($obj->ShioTask_item_id);
            $res->total += $obj->Qtynumber;  #打包总数
            $res->packableQuantity += ($tmp->real_quantity - $tmp->reject_quantity); #实到数量-驳回数量=可打包的数量
        }

        #获取打包数据
        foreach ($res->packs as $pack){
            # shiptask_item_id格式: [{"id":"","num":"","specs_id":""}]
            $pack->shiptask_item_id = json_decode($pack->shiptask_item_id);
            if($pack->shiptask_item_id){
                ShipTaskItem::getInstance()->fillModel($pack->shiptask_item_id);
            }
        }
        //MissuUser::getInstance()->fillUsers($res->packs, 'create_user');
        return $res;
    }

    //搜索so型号
    public function productsSerach(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0',
                'products_name' => 'string',
            ]);
            if(!isset($validated['products_name'])) $validated["products_name"] = "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;
        $data->list = [];
        $list = ShipTaskItem::getInstance()->getProducts($validated);
        foreach ($list as $obj){
            $obj->shipTaskSubmit = $tmp = ShiptaskSubmit::getInstance()->getItemNum($obj->ShioTask_item_id);  #
            if(!empty($obj->shipTaskSubmit->delivery_num)){  #过滤妥交为0的型号
                # 获取对应的sku，规格
                $obj->specs = IhuProductSpecs::getInstance()->getByProductId($obj->products_id);

                $obj->realQuantity = !empty($obj->shipTaskSubmit->delivery_num)? $obj->shipTaskSubmit->delivery_num :0; //$obj->shipTaskSubmit->real_quantity - $obj->shipTaskSubmit->reject_quantity;  #实到数量
                $data->list[] = $obj;
            }
        }

        # 补齐ShioTask_item_id在包裹里用了多少数量，放入$data->list['useQuantity']字段里
        ShipTaskPackage::getInstance()->fillUserQuantity($validated["so_id"],$data->list, 'ShioTask_item_id', 'useQuantity');

        return $this->renderJson($data);
    }

    //总务跟单操作
    public function gd_op(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0',
                'check_status' => [new Enum(CheckStatus::class), 'required', 'filled'],
                'remarks' => 'string|max:256',
                'operate' => 'required|string',   //提交submit, 保存save
                'so_status_id' => 'required|integer|gt:0',
                'takenList' => 'string', // [{"shiptask_item_id":"","taken_quantity":""}]  so详细ID，拿货数量是总的数据
            ]);
            $validated["remarks"] = !empty($validated["remarks"]) ? htmlspecialchars($validated["remarks"]) : "";
            $validated["takenList"] = !empty($validated["takenList"]) ? json_decode($validated["takenList"],true): [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $userId = UserId::$user_id;

        # 更新SO操作状态表
        $param = ['check_status' => $validated['check_status'], 'zw_gd_note' => $validated['remarks']];
        if($validated["operate"] == "submit") $param["zw_gd_status"] = 1;   //只有跟单提交才能为1
        $result = new \stdClass();
        SoStatus::getInstance()->updateSoStatus($validated['so_status_id'], $param);

        #处理takenList
        $tankenNum = 0;
        if(!empty($validated["takenList"])){
            foreach ($validated["takenList"] as $obj){
                $tankenNum += $obj["taken_quantity"];
            }
            $result->affected = ShipTaskItem::getInstance()->saveTakenQuantity($validated["takenList"]);
        }

        # 跟单提交时，如操作状态为缺货，则发送消息
        $Qtynumber = ShipTaskItem::getInstance()->getSumQtynumber($data->Shiptask_id);
        if($validated["operate"] == "submit"){
            ShipTask::getInstance()->updateShipTask($validated['so_id'],["Update_tiem"=>date("Y-m-d H:i:s")]);
            # 记录日志
            $flag = "状态：" . ($Qtynumber == $tankenNum ? "货齐" : "缺货");
            Oplog::getInstance()->addSoLog($userId, $validated['so_id'], "采购跟单操作", $validated['remarks'],["flag"=>$flag]);
        }

        return $this->renderJson($result);
    }

    //总务核对操作
    public function kd_op(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0', // 即shiptask_id
                'check_status' => [new Enum(CheckStatus::class), 'required', 'filled'],
                'remarks' => 'string|max:256',
                'addList' => 'string', // [{"shiptask_item_id":"","delivery_num":"","return_num":""}]
                'updateList' =>'string', //[{"id":"","delivery_num":"","return_num":""}]  需修改的提交数据
                'operate' => 'required|string',   //提交submit, 保存save
                'so_status_id' => 'required|integer|gt:0',
                'check_weight' => 'required|numeric|gt:0',
                'delivery_number' => 'required|string', //运单号
            ]);
            $validated["remarks"] = !empty($validated["remarks"]) ? htmlspecialchars($validated["remarks"]) : "";
            $validated["updateList"] = !empty($validated["updateList"]) ? json_decode($validated["updateList"],true) :[];
            $validated["addList"] = !empty($validated["addList"]) ? json_decode($validated["addList"],true) :[];
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if(empty($validated["check_weight"])){
            unset($validated["check_weight"]);
        }
        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $userId = UserId::$user_id;

        $prestatus = SoStatus::getInstance()->getSoStatus($validated["so_id"]);

        //验证妥交+内退数量
        $tmp2=[];
        if(!empty($validated["addList"])){
            foreach($validated["addList"] as $key=>$obj){
                # 过滤无效数据
                if($obj["delivery_num"] ==0 && $obj["return_num"] ==0){
                    unset($validated["addList"][$key]);
                    continue;
                }
                # 验证addlist里的数据在数据库里是否已经存在
                $res = ShiptaskSubmit::getInstance()->getByItemId($obj["shiptask_item_id"]);
                if($res){
                    $tmp2[] = ["id"=>$res->id, "delivery_num"=>$obj["delivery_num"], "return_num"=>$obj["return_num"]];
                    unset($validated["addList"][$key]);
                    continue;
                }
                $validated["addList"][$key]["id"] = ShiptaskSubmit::getInstance()->get_unique_id();
                $validated["addList"][$key]["shiptask_id"] = $validated["so_id"];
                $validated["addList"][$key]["real_quantity"] = $obj["delivery_num"] + $obj["return_num"];
            }
        }
        $validated["updateList"] = array_merge($validated["updateList"], $tmp2);
        $resTj = null;
        if(!empty($validated["addList"]) || !empty($validated["updateList"])){
            # 批量更新妥交，内退
            $resTj = ShiptaskSubmit::getInstance()->batchUpdate($validated["addList"],$validated["updateList"],$validated["so_id"], $validated["operate"]);
        }

        $result = new \stdClass();
        $param = ['check_status' => $validated['check_status'], 'zw_kd_note' => $validated['remarks']];
        $tmp = [
            "Shiptask_delivery_Singlenumber"=>$validated['delivery_number'],
            "check_weight"=>$validated["check_weight"],
            "remarks" => $validated["remarks"],
        ];
        if($validated["operate"] == "submit"){
            $param["zw_kd_status"] = 1;   //只有核对提交才能为1
            $tmp["Update_tiem"] = date("Y-m-d H:i:s");  //只有核对提交才能更新时间
        }

        # 更新gd_so_status操作状态表
        SoStatus::getInstance()->updateSoStatus($validated['so_status_id'], $param);
        #更新主表
        $affect2 = ShipTask::getInstance()->updateShipTask($validated['so_id'],$tmp);
        #发送消息: 第一次提交
        if($validated["operate"] == "submit" && $prestatus->zw_kd_status==0){
            \App\Services\MessageService::getInstance()->soKdMessage($userId,$data->Shiptask_id,$data->Shiptask_name, $data->order_id);
        }

        #记录日志
        if($validated["operate"] == "submit"){
            # 获取整个so的型号对应的总的妥交数、内退数; 写日志
            $res = $this->getSubmitInfo($data);
            Oplog::getInstance()->addSoLog($userId, $validated['so_id'], "总务核对操作",$validated["remarks"],["f10"=>json_encode($res)]);
            OplogApi::getInstance()->addLog($userId, "总务核对操作", json_encode($validated));
        }

        $result->affected = ($affect2 || $resTj) ? 1: 0;
        if (!empty($result->affected)) {
            # 同步到RabbitMq
            $messageBody = array(
                "method"=>"updateShippingWay",
                "params"=>[
                    "shiptask_id"=>$validated['so_id'],
                    "crm_shiptask_id"=>$data->crm_shiptask_id,
                    "shipping_way"=>$data->shipping_way,
                    "Shiptask_delivery_Singlenumber"=>$validated["delivery_number"],
                    "Shitask_turn_delivery_Singlenumber"=>$data->Shitask_turn_delivery_Singlenumber,
                ],
            );
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);
        }

        return $this->renderJson($result);
    }

    //审核详情
    public function auditInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $data->soStatus = SoStatus::getInstance()->getSoStatus($validated['so_id']);
        $data->item = ShipTaskItem::getInstance()->getItemsByAuditStatus($validated["so_id"]); #获取有异常型号的
        foreach ($data->item as $item){  # 获取异常型号对应的快递序列号
            # 获取对应的sku，规格
            $item->specs = IhuProductSpecs::getInstance()->getByProductId($item->products_id);
            $item->realQuantity = ShiptaskSubmit::getInstance()->getItemRealQuantiy($item->ShioTask_item_id);  #实到数量
            $item->serialNumbers = ShiptaskSubmit::getInstance()->getserialNumbers($item->ShioTask_item_id);
        }
        $data->expectedQuantity =  ShipTaskItem::getInstance()->getSumQtynumber($validated["so_id"]);  #应到数量，即SO所有型号数量总和

        $submit = ShiptaskSubmit::getInstance()->getAllItemBySoId($validated["so_id"]);  # 统计所有的型号的妥交，内退的数量，根据SOID
        $data->delivery_num = $data->return_num = $data->realQuantity = 0;
        foreach ($submit as $obj){
            $data->delivery_num += $obj->delivery_num; #妥交数量
            $data->return_num += $obj->return_num;  #内退数量
            $data->realQuantity +=  $obj->real_quantity;   #实到数量
        }

        #货物图片
        $podId = ShiptaskSubmit::getInstance()->getPodId($validated["so_id"]);
        #采购单详单，对应的所有快递产品存在异常图片的
        $data->abnormalAttach = ExpressOrders::getInstance()->getAttachmentByFlag($podId);
        #采购单附件：图片,视频
        $data->attachments = Attachments::getInstance()->getAttachmentsByPodId($podId);

        return $this->renderJson($data);
    }

    //审核提交
    public function auditSubmit(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0', // 即shiptask_id
                'operate' => 'required|string',   //提交submit, 保存save
                'note' => 'string|max:256',
                'so_status_id' => 'required|integer|gt:0',
                'audit_satus' => [new Enum(AuditSatus::class), 'required', 'filled'],
                'rejectModel' => 'string',  // 驳回型号 [{"so_item_id":"","serial_numbers":[{"id":"","quantity":""}]  }]
            ]);
            if(!empty($validated["note"])) $validated["note"] = htmlspecialchars($validated["note"]);
            $validated["rejectModel"] = !empty($validated["rejectModel"]) ? json_decode($validated["rejectModel"],true): "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if($validated["audit_satus"] > AuditSatus::AGREE->value && empty($validated["note"])){
            return $this->renderErrorJson(21, "备注为必填");
        }

        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $userId = UserId::$user_id;

        #审核同意/整单驳回，没有驳回类型
        if(in_array($validated["audit_satus"], [AuditSatus::AGREE->value,AuditSatus::REJECTION->value])){
            $validated["rejectModel"] = "";
        }

        #处理驳回型号
        #标注so表状态，so详细表型号标记驳回，数量，驳回对应的序列号
        $affect1 = ShipTaskItem::getInstance()->updateAuditSatusF2($validated);

        $result = new \stdClass();
        $affect2=null;
        # 更新SO操作状态表
        if($validated["operate"] == "submit"){  //只有提交才能为1,保存为0

            $affect2 = ShipTaskItem::getInstance()->auditSubmit($validated);

            //SoStatus::getInstance()->updateSoStatus($validated['so_status_id'], ['audit_satus' => 1]);
            //$affect2 = ShipTaskItem::getInstance()->updateShipQty($validated);

        }
        $result->affected = $affect1 || $affect2;

        #发送消息
        if($validated["operate"] == "submit"){
            $recipient = [];
            #获取该条SO的跟单员
            $user = MissuUser::getInstance()->getUserByGroupId(98);  #98跟单员
            foreach ($user as $val){
                $recipient[] = $val->id;
            }
            $tmp = [
                "user_id" => $userId,
                "source" => "APP内推送",
                "jump_url" => "/soModule/pages/soDetails/soDetails?id=".$data->Shiptask_id."&invoice=".$data->Shiptask_name."&processIdentification=verification",
            ];
            if($validated["audit_satus"] == AuditSatus::AGREE->value){   #异常，销售同意打包

                #给提交该条SO的跟单员、打包员发送
                $user = MissuUser::getInstance()->getUserByGroupId(99);  #99打包员
                foreach ($user as $val){
                    $recipient[] = $val->id;
                }
                $tmp["title"] = "您的SO已进入打包流程，点击查看";
                $tmp["content"] = $data->Shiptask_name."异常运输单已被同意发货";
                Message::getInstance()->addMessage($tmp,$recipient);
            }
            if($validated["audit_satus"] > AuditSatus::AGREE->value){   #异常，销售驳回
                #给跟单员、采购经理
                $purcharse = ShipTaskItem::getInstance()->getItems($validated['so_id']);
                foreach ($purcharse as $val){
                    $recipient[] = $val->Purchaser_id;
                }
                $tmp["title"] = "您的SO已被驳回，请及时查看！";
                $tmp["content"] = $data->Shiptask_name."异常运输单已被驳回";
                Message::getInstance()->addMessage($tmp,$recipient);
            }
        }

        #日志记录
        if($validated["operate"] == "submit"){
            $str = "";
            if($validated["audit_satus"] == AuditSatus::AGREE->value) $str = "审核同意";
            else if($validated["audit_satus"] == AuditSatus::REJECTION->value) $str = "整单驳回";
            else if($validated["audit_satus"] == AuditSatus::WAITEING->value) $str = "等待货齐";
            else if($validated["audit_satus"] == AuditSatus::PARTSEND->value) $str = "部分先发货";
            $f10 = !empty($validated["rejectModel"]) ? json_encode($validated["rejectModel"]) : "";
            Oplog::getInstance()->addSoLog($userId, $validated['so_id'], "审核类型：{$str}",$validated['note'],["f10"=>$f10]);
            OplogApi::getInstance()->addLog($userId, "销售审核类型：{$str}", json_encode($validated));
        }
        return $this->renderJson($result);
    }

    // 打包操作
    public function db_op(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0',
                'db_status' => [new Enum(DbStatus::class), 'required', 'filled'],
                'remarks' => 'string|max:256',
                'so_status_id' => 'required|integer|gt:0',
                'operate' => 'required|string',   //提交submit, 保存save
                'pack_weight'=>'numeric|gt:0',
                'pack_user_id' => 'required|string',  //打包员,多个逗号隔开
            ]);
            $validated["remarks"] = !empty($validated["remarks"]) ? htmlspecialchars($validated["remarks"]) : "";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userId = UserId::$user_id;
        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }

        #一个包裹图片视频至少需要两张
        $attachments = Attachments::getInstance()->getSoAttachments($validated['so_id']);
        $packs = ShipTaskPackage::getInstance()->getPacks($validated['so_id']);
        $attachments_count = count($attachments);
        $packs_count = count($packs);
        if($attachments_count ==0){
            return $this->renderErrorJson([21,"参数错误：请上传包裹图片"]);
        }

        # 更新SO操作状态表
        $result = new \stdClass();
        $param = ['db_note' => $validated['remarks'],];
        $soParam = ["pack_user_id"=>$validated["pack_user_id"]];
        if(!empty($validated["pack_weight"]))  $soParam["pack_weight"] = $validated["pack_weight"];
        //if($validated["operate"] == "submit") {  //只有提交才变更状态
            $param["db_status"] = $validated['db_status'];
            $soParam["Update_tiem"] = date("Y-m-d H:i:s");
        //}
        $result->affected = SoStatus::getInstance()->updateSoStatus($validated['so_status_id'], $param);
        ShipTask::getInstance()->updateShipTask($validated['so_id'],$soParam);

        #发送消息：装箱完毕
        if($validated["db_status"] ==3){
            \App\Services\MessageService::getInstance()->soDbMessage($userId,$data->Shiptask_id,$data->Shiptask_name, $data->order_id);
        }

        # 添加so日志
        $note = "待打包";
        if($validated['db_status'] == 3) $note = "装箱完毕";
        if($validated['db_status'] == 2) $note = "打包完毕";
        if($validated['db_status'] == 1) $note = "打包中";
        Oplog::getInstance()->addSoLog($userId, $validated['so_id'], "打包操作", $note.",".$validated['remarks']);
        OplogApi::getInstance()->addLog($userId, "打包操作", json_encode($validated));
        return $this->renderJson($result);
    }

    //编辑SO的快递信息
    public function updateExpress(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0', // 即shiptask_id
                'shipping_way' => 'string',
                'turn_delivery_number' => 'string', //string|required_with:shipping_way',
                'remarks' => 'string|max:256',
            ]);
            $validated["remarks"] = !empty($validated["remarks"])? $validated["remarks"] : "";
            $validated['shipping_way'] = !empty($validated['shipping_way'])?$validated['shipping_way']:"";
            $validated['turn_delivery_number'] = !empty($validated['turn_delivery_number'])?$validated["turn_delivery_number"]:"";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $tmp = [];
        foreach (\App\Ok\Enum\ShippingWays::map() as $obj){
            $tmp[] = $obj["name"];
        }
        if(!empty($validated["shipping_way"]) && !in_array($validated["shipping_way"], $tmp)){
            return $this->renderErrorJson(21, "shipping_way参数错误");
        }

        $userId = UserId::$user_id;
        $result = new \stdClass();
        $param = [
            "Update_tiem"=>date("Y-m-d H:i:s"),
            "remarks"=>$validated["remarks"]
        ];
        if(!empty($validated['shipping_way'])) $param["shipping_way"] = $validated['shipping_way'];
        if(!empty($validated['turn_delivery_number'])) $param["Shitask_turn_delivery_Singlenumber"] = $validated['turn_delivery_number'];

        $result->affected = ShipTask::getInstance()->updateShipTask($validated['so_id'],$param);

        if (!empty($result->affected)) {
            # 同步到RabbitMq
            $messageBody = array(
                "method"=>"updateShippingWay",
                "params"=>[
                    "shiptask_id"=>$validated['so_id'],
                    "crm_shiptask_id"=>$data->crm_shiptask_id,
                    "shipping_way"=>$validated['shipping_way'],
                    "Shitask_turn_delivery_Singlenumber"=>$validated['turn_delivery_number'],
                    "Shiptask_delivery_Singlenumber"=>$data->Shiptask_delivery_Singlenumber,
                    "remarks"=>$validated["remarks"],
                ],
            );
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);

            Oplog::getInstance()->addSoLog($userId, $validated['so_id'], "编辑快递信息",
                sprintf("修改发货任务物流号：%s： %s", $validated['shipping_way'], $validated["turn_delivery_number"]));
        }

        return $this->renderJson($result);
    }

    //确认发货
    public function confirmShipment(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'so_id' => 'required|integer|gt:0', // 即shiptask_id
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userId = UserId::$user_id;
        $data = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }
        $validated["State"] = 2;
        $validated["Update_tiem"] = date("Y-m-d H:i:s");
        $validated["Shipdatetime"] = date("Y-m-d H:i:s");

        #更新主表，子表状态
        $data->affected = ShipTask::getInstance()->updateState($validated["so_id"],$validated);

        #发送消息：如果不是so单的销售发货
        if($userId != $data->Sales_User_ID){
            \App\Services\MessageService::getInstance()->soConfirmMessage($userId,$data->Shiptask_id,$data->Shiptask_name, $data->order_id);
        }

        if (!empty($data->affected)) {
            # 同步到RabbitMq
            $messageBody = array(
                "method"=>"confirmShipment",
                "params"=>[
                    "shiptask_id"=>$validated['so_id'],
                    "crm_shiptask_id"=>$data->crm_shiptask_id,
                    "state"=>$validated["State"],
                    "Shipdatetime"=>$validated["Shipdatetime"]
                ],
            );
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,$messageBody);
            Oplog::getInstance()->addSoLog(UserId::$user_id, $validated['so_id'], "确认发货","");
        }

        #队列处理，更新pI状态
        \App\Jobs\OrdersStatusUpdate::dispatchSync($validated["so_id"]);

        return $this->renderJson($data);
    }

    #妥交内退日志需要的数据
    private function getSubmitInfo($data)
    {
        # 根据SO找对应的PI单
        $ids = array_filter(explode(',', $data->order_id));
        $list = Orders::getInstance()->getByIdsF1($ids);

        foreach ($list as $obj){
            # 获取订单id下的本发货单的任务详细
            $obj->items = ShipTaskItem::getInstance()->getShipItemByOrderId($obj->order_id,$data->Shiptask_id);
            foreach ($obj->items as $item) {
                # 获取item下对应的so提交记录
                $item->shiptaskSubmit = ShiptaskSubmit::getInstance()->getByItemId($item->ShioTask_item_id);
            }
        }
        return $list;
    }


    //导出
    public function soExport(Request $request)
    {
        try {
            $validated = $request->validate([
                'so_id_list' => 'required|string', // 即shiptask_id
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userId = UserId::$user_id;
        $data = ShipTask::getInstance()->exportList(explode(",", $validated['so_id_list']));
        if (empty($data)) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }

        // 创建一个 Spreadsheet 对象
        $spreadsheet = new Spreadsheet();
        // 获取当前活动的工作表
        $sheet = $spreadsheet->getActiveSheet();
        // 设置表头
        $sheet->setCellValue('A1', '日期');
        $sheet->setCellValue('B1', '实重');
        $sheet->setCellValue('C1', '长');
        $sheet->setCellValue('D1', '宽');
        $sheet->setCellValue('E1', '高');
        $sheet->setCellValue('F1', '体积重');
        $sheet->setCellValue('G1', 'so');
        $sheet->setCellValue('H1', 'PI');
        $sheet->setCellValue('I1', '渠道');
        $sheet->setCellValue('J1', '运单号');
        $sheet->setCellValue('K1', '国家');
        $sheet->setCellValue('L1', '销售');
        $sheet->setCellValue('M1', '件数');
        $sheet->setCellValue('N1', '发票抬头');
        $sheet->setCellValue('O1', '是否含电池');
        $sheet->setCellValue('P1', '品牌');
        $sheet->setCellValue('Q1', '打包人');
        $sheet->setCellValue('R1', '备注');


        # 补齐客户国家信息
        CustomerInfo::getInstance()->fillCountry($data, 'Country_id');

        // 添加多行数据
        $row = 2; // 从第二行开始添加数据（第一行是表头）
        foreach ($data as $item) {

           $item->pack_user = [];
           $pack_user = MissuUser::getInstance()->getUserByIds(explode(",", $item->pack_user_id));
           foreach ($pack_user as $obj){
               $item->pack_user[] = $obj->name;
           }

           $soItem = ShipTaskItem::getInstance()->getItems($item->Shiptask_id);
           $productId = $item->Brand_name = []; $item->Qtynumber=0; ;
           foreach ($soItem as $obj){
               $productId[] = $obj->products_id;
               $item->Qtynumber += $obj->Qtynumber;
               if($obj->Brand_name =="SIEMENS") $item->Brand_name[] = $obj->Brand_name;  #如果是西门子就导出，不是西门子的就显示空
           }
           $keywords_model_id = \App\Services\M\IhuProduct::getInstance()->returnKeywordsModelIds($productId);
           $keywords_model_id = array_unique($keywords_model_id);
           if(count($keywords_model_id)==1 && $keywords_model_id[0] ==8) $item->battery="无电池";
           else $item->battery="内置电池";

           $item->soStatus = SoStatus::getInstance()->getSoStatus($item->Shiptask_id);

           $orders = Orders::getInstance()->getByIdsF1(explode(",", $item->order_id));
           # 补齐create_user对应的信息，放入$data->list['purchaser']字段里
           MissuUser::getInstance()->fillUsers($orders, 'Sales_User_ID', 'sales');

           # 得到所有的PI_name, sales
           $item->sales = $item->pis = $sales = [];
           foreach ($orders as $v){
               $item->pis[] = $v->PI_name;
               if(!isset($sales[$v->sales->id])){
                   $item->sales[] = $v->sales->name;
                   $sales[$v->sales->id] = $v->sales;
               }
           }

           $packs = ShipTaskPackage::getInstance()->getPacks($item->Shiptask_id);
           $column = 'A';
           $j = $row;
           //echo"---\n";
           //var_dump($row);
           foreach ($packs as $p){
               if($p->volume_weight) {$tj = $p->volume_weight;}
               else {$tj = round(($p->long * $p->wide * $p->high)/5000, 3); }  //round($number, 3)
               $column = 'A';
               $sheet->setCellValue($column++ . $j, date("Y-m-d",strtotime($p->createTime)));
               $sheet->setCellValue($column++ . $j, $p->netWeight);
               $sheet->setCellValue($column++ . $j, $p->long);
               $sheet->setCellValue($column++ . $j, $p->wide);
               $sheet->setCellValue($column++ . $j, $p->high);
               $sheet->setCellValue($column++ . $j, $tj); //体积重
               $j++;
            }
            $j -= 1;  //回到上一行

            $column = 'G';
            $sheet->setCellValue($column++ . $row, $item->Shiptask_name);
            $sheet->setCellValue($column++ . $row, implode(",", $item->pis));
            $sheet->setCellValue($column++ . $row, $item->shipping_way?:"");
            $sheet->setCellValue($column++ . $row, $item->Shiptask_delivery_Singlenumber?:"");
            $sheet->setCellValue($column++ . $row, implode(",", $item->country_name));
            $sheet->setCellValue($column++ . $row, implode(",", $item->sales));
            $sheet->setCellValue($column++ . $row, $item->Qtynumber); //总件数
            $sheet->setCellValue($column++ . $row, "原发票抬头");
            $sheet->setCellValue($column++ . $row, $item->battery); //内置电池??
            $sheet->setCellValue($column++ . $row, implode(",", $item->Brand_name)); //不是西门子的品牌不需要导出，显示空
            $sheet->setCellValue($column++ . $row, implode(",", $item->pack_user)); //打包人
            $sheet->setCellValue($column . $row, !isset($item->soStatus)? $item->soStatus->db_note:""); //SO打包流程的备注


            // 设置文本居中
            $styleArray = [
//                'font' => [
//                    'bold' => true,
//                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ];

            $column = 'G';
            // 合并单元格
            // 参数是一个范围，比如 'A1:B1' 表示合并从A1到B1的单元格
            //$range = $column.$row.':'.$column++.$j;
            //$sheet->mergeCells($column.$row.':'.$column++.$j);
            if(count($packs)){
                // 合并后的单元格内容将只显示第一个单元格的内容（在这个例子中是'Hello'），
                // 但你可以通过设置合并后的单元格的值来覆盖它
                for ($i=0; $i<12; $i++){
                    $sheet->mergeCells($column.$row.':'.$column.$j);
                    // 应用样式到合并的单元格
                    $sheet->getStyle($column.$row)->applyFromArray($styleArray);
                    $column++;
                }
                $row = $j+1;
            }else{
                $row++;
            }
        }


       // 创建一个写入器来写入Excel 2007文件（xlsx）
       $writer = new Xlsx($spreadsheet);
       // 保存Excel 2007文件
       $fileName = storage_path().'/logs/hello_world.xlsx';
       //$writer->save($fileName);



        // 设置HTTP头
        //header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        //header('Content-Disposition: attachment;filename="example.xlsx"');
        //header('Cache-Control: max-age=0');

        // 创建写入器并保存（注意这里不是保存到文件，而是直接输出）
        //$writer = new Xlsx($spreadsheet);
        $writer->save('php://output'); // 保存到php输出流，而不是文件, 由微信小程序解析流

    }
}
