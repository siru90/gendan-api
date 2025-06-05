<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use \App\Services\CheckDetail;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\Orders;

//异常快递处理
class ExpressAbnormalService extends BaseService
{
    //同步产品的关联数量到PI上
    //$data 是gd_products
    public function productNumToPI(object $data):array
    {
        $affectArray = [];
        #订单型号的到货型号对应，且数量相等，则将此数据同步到PI的序列号和验货数量上去
        $exporessOrder = ExpressOrders::getInstance()->getExpressOrderByProductId($data->id);
        #按订单明细统计关联产品数量
        foreach ($exporessOrder as $obj){
            $orderIds = [];   #存储异常图片的销售订单ID
            $obj->quantity = ExpressOrders::getInstance()->getSumQuantityByOrderItemID($obj->order_item_id,$data->id);
            $obj->userID = $data->user_id;
            #先去查找PI明细单
            $orderItem = OrdersItemInfo::getInstance()->getByIdAS($obj->order_item_id);
            $check = CheckDetail::getInstance()->getCheckPi($obj->order_item_id);
            if($check){
                #如果同步数量+ 已有序列号数量  > pi的数量
                if($obj->quantity + $check->serial_quantity > $orderItem->quantity){
                    #记日志？？
                    $express = ExpressDelivery::getInstance()->getById($obj->express_id);
                    $channel = \App\Services\M\ExpressCompany::getInstance()->getChannel($express->channel_id);
                    $str = $express->tracking_number."-".$channel->expName .$data->model ."同步数量超过未入库数，入库失败";
                    Oplog::getInstance()->addCheckLog($data->user_id, $orderItem->order_id, "异常快递入库数据同步失败",$str);

                    #发消息，给创建这条异常快递信息的用户发送信息
                    MessageService::getInstance()->AbnormalExpressSyncPiQty($obj->userID,[
                        "abnormal_source" => $express->abnormal_source,
                        "id"=>$express->id,
                        "tracking_number"=>$express->tracking_number,
                        "model"=>$data->model,
                        "quantity"=>$obj->quantity,
                        "order_id"=>$orderItem->order_id,
                    ]);

                    #更改按快递提交
                    ExpressDelivery::getInstance()->updateExpressDelivery($obj->express_id, ["submit_purcharse" =>1]);
                    continue;
                }
            }

            #把照片同步到核对详情去
            $obj->attach = Attachments::getInstance()->getAttachments($data->id,2);
            foreach ($obj->attach as $att){
                [$purchase,] = ExpressOrders::getInstance()->getExpressPodByPi($obj->order_item_id,$data->id);
                foreach ($purchase as $po){
                    $id = \App\Services\Attachments::getInstance()->addAttachment($obj->userID, $att->file_id, [
                        'correlate_id' => $orderItem->order_info_id,
                        'correlate_type' => 8,
                        'type' => $att->type,
                        'flag' => $att->flag,
                        'pod_id' => !empty($po->purchaseorder_detailed_id) ? $po->purchaseorder_detailed_id : 0,
                    ]);
                }
                #异常的销售单ID
                if($att->flag ==0 ){
                    $orderIds[] = $orderItem->order_id;
                }
            }

            #变更核对数量、序列号数量、快递提交
            $res = ["order_item_id" =>$obj->order_item_id,];
            $res["check_detail"] = CheckDetail::getInstance()->expressProductToCheck($check->id??0, $obj);
            $affectArray[] = $res;

            #给销售发消息
            $orderIds = array_unique($orderIds);
            if(count($orderIds)){
                foreach ($orderIds as $id){
                    $order = Orders::getInstance()->getByIdAS($id);
                    \App\Services\MessageService::getInstance()->checkInspectMessage($obj->userID,[
                        "Sales_User_ID"=>$order->Sales_User_ID,
                        "PI_name"=>$order->PI_name,
                        "order_id"=>$id,
                    ]);
                }
            }
        }

        $abnormal_status = ($data->abnormal_status ==0)? 0: 3;
        $affect = Products::getInstance()->updateModel($data->id, ["is_confirmed"=>1,"abnormal_status"=>$abnormal_status]);

        return [$affectArray,$affect];
    }


    //统计 得到快递总的状态？？
    public function expressAbnormalStatus($express_id)
    {
        $allProduct = Products::getInstance()->getProducts($express_id);
        $abnormal_status = [];
        foreach ($allProduct as $obj){
            if($obj->abnormal_status) $abnormal_status[] = $obj->abnormal_status;
        }
        $abnormal_status = array_unique($abnormal_status);
        if(count($abnormal_status)==1){
            if($abnormal_status[0] ==1) $tmpStatus = 1;
            else if($abnormal_status[0] ==3) $tmpStatus = 3;
            else if($abnormal_status[0] ==0) $tmpStatus = 3;
            else $tmpStatus = 2;
        }else{
            $tmpStatus = 2;
        }
        ExpressDelivery::getInstance()->updateExpressDelivery($express_id, ["abnormal_status"=>$tmpStatus]);
    }
}
