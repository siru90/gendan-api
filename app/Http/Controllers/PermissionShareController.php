<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use \Illuminate\Support\Facades\Redis;
use App\Ok\SysError;
use Illuminate\Http\Request;
use stdClass;

use \App\Http\Middleware\UserId;
use \App\Services\ExpressOrders;
use \App\Services\M\PurchaseOrder;
use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\PermissionShare;
use \App\Services\PermissionSingle;

use Illuminate\Support\Facades\Log;

use App\Services\M\User as MissuUser;

//核对PI单
class PermissionShareController extends Controller
{
    //权限共享用户
    public function shareUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated["user_id"] = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo(UserId::$user_id);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        $data = new stdClass();
        $data->share = PermissionShare::getInstance()->getShareUser($validated["user_id"]);
        return $this->renderJson($data);
    }

    //权限共享
    public function globalShare(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'share_user_id' => 'required|min:1|max:512',  //多个逗号隔开
            ]);
            $validated["share_user_id"] = explode(",", $validated["share_user_id"]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }

        #获取所有共享给当前采购的 所有用户ID
        $shareUserIds = PermissionShare::getInstance()->getShareUserId($userID);
        $shareUserIds = array_flip($shareUserIds);
        foreach ($validated["share_user_id"] as $key=>$id){
            if(isset($shareUserIds[$id])){
                unset($shareUserIds[$id]);  #过滤已经存在的用户，余下是需要删除的
                unset($validated["share_user_id"][$key]);  #过滤已经存在的用户，余下是需要新增的
            }
        }
        $data = new stdClass();
        if(empty($shareUserIds) && empty($validated["share_user_id"])){
            $data->affected = 0;
            return $this->renderJson($data);
        }
        #组装参数
        $addParam = $delParam = [];
        if(count($validated["share_user_id"])){
            foreach ($validated["share_user_id"] as $id){
                $addParam[] = [
                    "permission_share_id" => PermissionShare::getInstance()->get_unique_id(),
                    "purchaser_id" => $userID,
                    "shared_user_id" => $id,
                    "status"=>1,
                ];
            }
        }
        $shareUserIds = array_flip($shareUserIds);
        if(count($shareUserIds)){
            foreach ($shareUserIds as $id){
                $delParam[] = [
                    "purchaser_id" => $userID,
                    "shared_user_id" => $id,
                ];
            }
        }
        $data->affected = PermissionShare::getInstance()->save($addParam,$delParam);
        return $this->renderJson($data);
    }

    //权限共享回收
    public function globalRecovery(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'share_user_id' => 'required|min:1|max:512',  //多个逗号隔开
            ]);
            $validated["share_user_id"] = explode(",", $validated["share_user_id"]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }

        #
        $delParam = [];
        foreach ($validated["share_user_id"] as $id){
            $delParam[] = [
                "purchaser_id" => $userID,
                "shared_user_id" => $id,
            ];
        }

        $data = new stdClass();
        $data->affect = PermissionShare::getInstance()->batchDel($delParam);
        return $this->renderJson($data);
    }

    //快递权限分享
    public function expressShare(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'express_id' => 'required|min:1|max:512',  //多个逗号隔开
                'share_user_id' => 'required|min:1|max:512',  //多个逗号隔开
            ]);
            $validated["express_id"] = explode(",", $validated["express_id"]);
            $validated["share_user_id"] = explode(",", $validated["share_user_id"]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }

        $addParam = [];
        foreach($validated["express_id"] as $id){
            foreach ($validated["share_user_id"] as $shareId){
                $isExpress = PermissionSingle::getInstance()->getExpressPermission($id,$userID,$shareId);
                if(!$isExpress){
                    $addParam[] = [
                        "permission_id" => PermissionSingle::getInstance()->get_unique_id(),
                        "type" => 1,
                        "user_id" => $userID,
                        "purchaser_id" => $userID,
                        "shared_user_id" => $shareId,
                        "status" => 1,
                        "express_id" => $id,
                    ];
                }
            }
        }

        $data = new stdClass();
        $data->affected = PermissionSingle::getInstance()->batchSave($addParam);
        return $this->renderJson($data);
    }

    //pi权限分享
    public function piShare(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|min:1|max:512',  //多个PI逗号隔开
                'share_user_id' => 'required|min:1|max:512',  //多个逗号隔开
            ]);
            $validated["order_id"] = explode(",", $validated["order_id"]);
            $validated["share_user_id"] = explode(",", $validated["share_user_id"]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }

        $poIds = [];
        #先查找order_id和采购人员，得到对应的po单
        $po = PurchaseOrderDetailed::getInstance()->getByOrderIds($validated["order_id"],$userID);
        foreach ($po as $item){
            $poIds[] = $item->Purchaseorder_id;
        }
        $addParam = [];
        #再获取po单对应的所有PI单
        $pod = PurchaseOrderDetailed::getInstance()->getByPurchaseOderIds($poIds);
        foreach ($pod as $item){
            foreach ($validated["share_user_id"] as $id){
                #搜索
                $isExit = PermissionSingle::getInstance()->getSiglePermiss($item->order_info_id,$item->Purchaseorder_detailed_id,$id);
                if($isExit){continue;}
                $addParam[] = [
                    "permission_id" => PermissionSingle::getInstance()->get_unique_id(),
                    "type" => 2,
                    "user_id" => $userID,
                    "purchaser_id" => $userID,
                    "shared_user_id" => $id,
                    "status" =>1,
                    "order_id" => $item->order_id,
                    "order_info_id" => $item->order_info_id,
                    "purchaseorder_id" => $item->Purchaseorder_id,
                    "purchaseorder_detailed_id" => $item->Purchaseorder_detailed_id,
                ];
            }
        }

        $data = new stdClass();
        $data->affected = 0;
        if(count($addParam)){
            $data->affected = PermissionSingle::getInstance()->batchSave($addParam);
        }
        return $this->renderJson($data);
    }

    //权限列表：分页
    public function shareList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'integer|gt:0',  // 值：1pi, 2express
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 10;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $validated["user_id"] = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo(UserId::$user_id);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        $data = (object)($validated);

        [$data->list, $data->total] = PermissionSingle::getInstance()->permissionList($validated);

        # 补齐(shared_user_id) （内部用户user表，外部用户missu_users表）中对应的用户id,name 放到$data->list["purcharse"]字段里
        MissuUser::getInstance()->fillUsers($data->list, 'shared_user_id', 'shareuser');
        foreach ($data->list as $obj){
            if(empty($obj->permission_id)){
                $obj->permission_id = $obj->order_id.$obj->purchaseorder_id.$obj->shared_user_id;
            }

            #补充快递单号，订单号，采购单号，用户名
            if(!empty($obj->express_id)){
                $obj->express = \App\Services\ExpressDelivery::getInstance()->getExpressDelivery($obj->express_id, ["id","tracking_number","channel_id","actual_purchaser_id"]);
                $channel = \App\Services\M\ExpressCompany::getInstance()->getChannel($obj->express->channel_id);
                $obj->express->channel_name = $channel->expName;
                if(!empty($obj->express->actual_purchaser_id)){
                    $obj->express->actual_purchaser_id = MissuUser::getInstance()->getUserByIds(explode(",", $obj->express->actual_purchaser_id));
                }
                $obj->express->product = \App\Services\Products::getInstance()->getProducts($obj->express_id);
            }
            if(!empty($obj->order_id)){
                $obj->orders = Orders::getInstance()->getByIdAS($obj->order_id);
                $obj->orders->sale = MissuUser::getInstance()->getUserInfo($obj->orders->Sales_User_ID);
                unset($obj->orders->order_remark);

                //$obj->orders->purchaseorder = PurchaseOrder::getInstance()->getByOrderId($obj->order_id);
                $purchaseorder = PurchaseOrder::getInstance()->getByIdAS($obj->purchaseorder_id);
                $obj->orders->purchaseorder = [$purchaseorder];
                # 补齐(create_user) （内部用户user表，外部用户missu_users表）中对应的用户id,name 放到$data->list["purcharse"]字段里
                MissuUser::getInstance()->fillUsers($obj->orders->purchaseorder, 'create_user', 'purcharse');
            }
        }

        return $this->renderJson($data);
    }

    //单个权限回收
    public function singleRecovery(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'permission_id' => 'required|string',
            ]);
            $validated["permission_id"] = explode(",", $validated["permission_id"]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        $res = new stdClass();
        $res->affected =PermissionSingle::getInstance()->delByIDs($validated["permission_id"]);
        return $this->renderJson($res);
    }

    //pi权限回收
    public function piRecovery(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'permission_list' => 'required|string',  //[{"purchaser_id": 1000000192,"order_id": 73114,"purchaseorder_id": 92089,"shared_user_id": 1000000549,}]
            ]);
            $validated["permission_list"] = json_decode($validated["permission_list"],true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userID = UserId::$user_id;
        $info = MissuUser::getInstance()->getUserInfo($userID);
        #判断只采购有权限
        if($info->user_group_id !=3){
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        $res = new stdClass();
        $res->affected =PermissionSingle::getInstance()->delByPis($validated["permission_list"]);
        return $this->renderJson($res);
    }
}
