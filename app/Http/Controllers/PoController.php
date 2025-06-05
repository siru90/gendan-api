<?php

namespace App\Http\Controllers;

use App\Ok\Enum\PoState;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

use \App\Services\M\PurchaseOrder;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\SerialNumbers;
use \App\Services\ExpressOrders;
use Illuminate\Support\Facades\DB;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class PoController extends Controller
{

    //PO列表（采购列表）
    public function poList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'sales_id' => 'integer|gt:0',
                'purchaser_id' => 'integer|gt:0',
                'state' => [new Enum(PoState::class),],
                'start_time' => 'string',
                'end_time' => 'string',
                //'submit_status' => [new Enum(SubmitStatus::class),],
                'keyword' => 'string',  //['Purchaseordername', 'pi_names'];
                'keyword_type' => 'string', //默认显示PO，可切换PI、PO、型号(支持搜索型号）
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;

        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = \App\Http\Middleware\UserId::$user_id;
        $data = new \stdClass();
        $data->list = [];$data->total = 0;

        $sales_id = 0;
        if(!empty($validated["sales_id"])){
            $sales_id = $validated["sales_id"];
        }
        $validated["sales_id"] = [];
        if($sales_id){
            $validated["sales_id"][] = $sales_id;
        }

        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin($userID);
        if($userInfo){
            $info = MissuUser::getInstance()->getUserInfo($userID);
            if($info->user_group_id ==1){  #判断是不是销售
                $validated["sales_id"]= [$userID];
            }
            else if($info->user_group_id ==103){
                $validated["sales_id"] = $userInfo;
            }
            else{
                $validated["user_id"] = $userInfo;
            }
        }

        # 查询采购订单表数据
        [$data->list,$data->total] = PurchaseOrder::getInstance()->poList($validated);

        # 补齐create_user对应的信息，放入$data->list['purchaser']字段里
        MissuUser::getInstance()->fillUsers($data->list, 'create_user', 'purchaser');
        foreach ($data->list as $po) {
            # 查询采购订单详单,一个采购订单有多个详单
            $po->items = PurchaseOrderDetailed::getInstance()->getByPoIdF1($po->purchaseorder_id);
        }
        return $this->renderJson($data);
    }

    //采购详情
    public function poInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'purchaseorder_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = PurchaseOrder::getInstance()->getByIdF3($validated["purchaseorder_id"]);
        if (!$data) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $data->modelNum = 0;  #型号总数
        $data->podNum =0;     #采购总件数
        $data->receivedNum = 0; #实到数量

        $data->users = MissuUser::getInstance()->getUserInfo($data->create_user);
        $data->item = PurchaseOrderDetailed::getInstance()->getByPoIdF1($validated["purchaseorder_id"]);
        foreach ($data->item as $val){
            $data->modelNum++;
            $data->podNum += $val->Qtynumber;

            #产品序列号总的数量
            if($val->State ==4){
                $val->serialQuantity = $val->Qtynumber;
            }else{
                $val->serialQuantity = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_detailed_id"=>$val->Purchaseorder_detailed_id]);
            }
            $data->receivedNum += $val->serialQuantity;

            # 查询销售订单相关信息
            $val->order = OrdersItemInfo::getInstance()->getByOrderId($val->order_id,$val->products_id);
            if ($val->order->Sales_User_ID ?? null) {
                # 获取销售用户相关信息
                $val->order->sales = MissuUser::getInstance()->getUserInfo($val->order->Sales_User_ID);
            }
        }

        return $this->renderJson($data);
    }

    //获取待处理数量
    public function unConfirmed(Request $request): \Illuminate\Http\JsonResponse
    {
        //判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->isNotAdmin(\App\Http\Middleware\UserId::$user_id);

        # PO待处理数量，即除了采购完成状态(4)的采购订单之外的所有
        $data = new \stdClass();
        $data->unConfirmed = PurchaseOrder::getInstance()->getUnConfirmedCount($userInfo?:null,["state"=>4]);
        return $this->renderJson($data);
    }


    public function setSalesID()
    {
        //sql : UPDATE purchaseorder_detailed  as p
        //join `orders` as `o` on `o`.`order_id` = `p`.`order_id`
        //SET p.sales_user_id = o.Sales_User_ID

        $list = DB::table('purchaseorder_detailed as p')
            ->where("p.sales_user_id",0)
            ->join("orders", "orders.order_id","p.order_id")
            ->update(["p.sales_user_id"=>DB::raw('orders.Sales_User_ID')]);
    }
}
