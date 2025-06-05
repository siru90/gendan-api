<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \App\Services\Oplog;
use \App\Services\M\ShipTaskItem;
use \App\Services\SerialNumbers;
use  \App\Services\M\ShipTask;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\PurchaseOrderDetailed;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;


class OpLogController extends Controller
{
    //获取核对日志
    public function getCheckLogs(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'order_id' => 'required|integer|gt:0',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 1000;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;

        [$data->list, $data->total] = Oplog::getInstance()->getCheckLogs($validated);
        MissuUser::getInstance()->fillUsers($data->list, 'user_id');

        $itemMap = [];

        foreach ($data->list as $item) {
            # 解析f9：验货日志[{"inspect_status":2,"inspect_quantity":1,"order_id":135603,"order_info_id": "198771"}]
            $item->f9 = !empty($item->f9) ? json_decode($item->f9) : "";
            if (!empty($item->f9)) {
                foreach ($item->f9 as $obj) {
                    //$podItem = PurchaseOrderDetailed::getInstance()->getInfo($obj->purchaseorder_detailed_id);
                    $id = $obj->order_info_id;
                    $item = OrdersItemInfo::getInstance()->getByIdAS($id);
                    $obj->products_Name = $item->product_name_pi;
                    $itemMap[$id] = $obj->products_Name;
                }
            }

            # f10: 审核驳回日志：
            # [{"order_info_id":135605,"serial_numbers":[{"id":"1019475518299437109","quantity":1},{"id":"1019475518299293632","quantity":1}]},{"pod_id":135606,"serial_numbers":[{"id":"1019475535105303727","quantity":2}]}]
            $item->f10 = !empty($item->f10) ? json_decode($item->f10) :"";
            if (!empty($item->f10)) {
                foreach ($item->f10 as $obj)
                {
                    $id = $obj->order_info_id;

                    if(isset($itemMap[$id])) $obj->products_Name = $itemMap[$id];
                    else {
                        //$item = PurchaseOrderDetailed::getInstance()->getInfo($obj->pod_id);
                        $item = OrdersItemInfo::getInstance()->getByIdAS($id);
                        $obj->products_Name = $item->product_name_pi;
                        $itemMap[$id] = $item->product_name_pi;
                    }
                    if(isset($obj->serial_numbers)){
                        foreach ($obj->serial_numbers as $i=> $k){
                            $ser = SerialNumbers::getInstance()->getSerialNumberById($k->id);
                            $k->serial_number = isset($ser->serial_number)?$ser->serial_number:"";
                            $k->type = isset($ser->type)?$ser->type:"";
                        }
                    }
                }
            }
        }
        return $this->renderJson($data);
    }

    //获取快递日志
    public function getExpressLogs(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'express_id' => 'required|integer|gt:0',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 1000;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;
        [$data->list, $data->total] = Oplog::getInstance()->getExpLogs($validated);
        MissuUser::getInstance()->fillUsers($data->list, 'user_id');
        $this->ok($data);
        return $this->renderJson($data);
    }

    //获取SO日志
    public function getSoLogs(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'so_id' => 'required|integer|gt:0',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 1000;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $res = ShipTask::getInstance()->getByIdF1($validated['so_id']);
        if (!$res) {
            return $this->renderErrorJson(\App\Ok\SysError::SO_ID_ERROR);
        }

        $data = (object)$validated;
        [$data->list, $data->total] = Oplog::getInstance()->getSoLogs($validated);
        MissuUser::getInstance()->fillUsers($data->list, 'user_id');

        foreach ($data->list as $item) {
            # 解析f9：合并型号日志[{"order_info_id":185334,"quantity":4,"PI_name":"MA2405131493","order_id":58493}]
            if (!empty($item->f9)) {
                $item->f9 = json_decode($item->f9);
                foreach ($item->f9 as $obj){
                    $obj->products_Name="";
                    if(isset($obj->order_info_id)){
                        $item = OrdersItemInfo::getInstance()->getByIdAS($obj->order_info_id);
                        $obj->products_Name = $item->product_name_pi;
                    }
                }
            } else {
                unset($item->f9);
            }
            #解析f10：妥交内退日志[{"order_id":21815,"PI_name":"OL2203309877","Sales_User_ID":9,"address_customer_info_id":3739,"Customer_Seller_info_id":3739,"items":[{"ShioTask_item_id":19001,"Shiptask_id":7881,"products_id":111349,"products_Name":"NJ101-9000","order_info_id":55936,"Qtynumber":7,"State":1,"Leading_name":"In Stock","taken_quantity":0,"shiptaskSubmit":{"id":1019027148294482977,"shiptask_item_id":19001,"pod_id":0,"delivery_num":3,"return_num":4,"express_product_id":"","real_quantity":7,"reject_quantity":0}}]}]
            if(!empty($item->f10)){
                $item->f10 = json_decode($item->f10);
                foreach ($item->f10 as $pi){
                    foreach ($pi->items as $model){
                        $model->specs = \App\Services\M\IhuProductSpecs::getInstance()->getByProductId($model->products_id);
                    }
                }
            }
        }

        //$this->ok($data);
        return $this->renderJson($data);
    }

    private function ok(object $data): void
    {
        foreach ($data->list as $item) {
            if (!empty($item->attachment_ids)) {
                $attachment_ids = explode(',', $item->attachment_ids);
                $item->attachments = \App\Services\Attachments::getInstance()->getAttachmentByIds($attachment_ids);
            }
            if (!empty($item->f9)) {
                $item->f9 = json_decode($item->f9);

                if(!empty($item->so_id)){
                    foreach ($item->f9 as $obj){
                        $obj->products_Name="";
                        if(isset($obj->order_info_id)){
                            $item = OrdersItemInfo::getInstance()->getByIdAS($obj->order_info_id);
                            $obj->products_Name = $item->product_name_pi;
                        }
                    }
                }
            } else {
                unset($item->f9);
            }
            if (!empty($item->f10)) {
                $item->f10 = json_decode($item->f10);

                if(!empty($item->so_id)){
                    foreach ($item->f10 as $obj){
                        $obj->products_Name="";
                        if(isset($obj->so_item_id)){
                            $item = ShipTaskItem::getInstance()->getItemById($obj->so_item_id);
                            $obj->products_Name = $item->products_Name;
                        }
                        /*if(isset($obj->serial_numbers)){
                            foreach ($obj->serial_numbers as $k){
                                $ser = SerialNumbers::getInstance()->getSerialNumberById($k->id);
                                $k->serial_number = isset($ser->serial_number)?$ser->serial_number:"";
                            }
                        }*/

                    }
                }
            } else {
                unset($item->f10);
            }
        }
    }
}
