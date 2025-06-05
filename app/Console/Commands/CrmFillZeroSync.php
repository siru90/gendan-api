<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;
use  \App\Services\M\ShipTask;
use \App\Services\M\ShipTaskItem;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\Attachments;

class CrmFillZeroSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:crm-fill-zero-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步Rabbitmq中的包裹任务';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        #填充为零的数据
/*        #处理gd_sync_crm表中sync_res为0的数据
        $crmData = \App\Services\SyncCrm::getInstance()->getsyncRes();
        foreach ($crmData as $obj){
            $param = json_decode($obj->sync_content,true);
            if(!isset($param["method"])) continue;
            switch ($param["method"]) {
                case "sendShiptask":
                    Log::channel('sync')->info('555999 \n');
                    $res = \App\Services\Sync\ShipTaskSync::getInstance()->sendShiptask($param);
                    break;
                default:
                    break;
            }
            if($res){
                \App\Services\SyncCrm::getInstance()->updateSyncContent($obj->sync_id,["sync_res"=>1]);
            }
        }
*/

        #用户补录mq_message 表中，crm_so_queue队列名，status为0的数据
        $url = env("APP_URL")."/api/tracking_order/crmsync/so_edit_fill";
        $result = \App\Ok\Curl::getInstance()->curlGet($url);


        #搜索数据库发货表中，crm_order_id,且order_id为0的数据， 根据crm_order_id到orders里找order_id，进行补充
        $data = ShipTask::getInstance()->getOderIdIsZero(["order_id"=>0]);
        if(!empty($data)){
            foreach ($data as $obj){
                #更新发货表中的order_id
                $order = Orders::getInstance()->getOrderIdByCrmOrderId($obj->crm_order_id);
                if(!empty($order)){
                    ShipTask::getInstance()->updateShipTask($obj->Shiptask_id,["order_id"=>$order->order_id]);
                }

                #g更新明细表中的order_info_id
                $soItem = ShipTaskItem::getInstance()->getItemsF1($obj->Shiptask_id);
                if(empty($soItem)) continue;
                foreach ($soItem as $item){
                    $orderItem = OrdersItemInfo::getInstance()->getInfoByCrmOrderInfoId($item->crm_order_info_id);
                    if($orderItem){
                        ShipTaskItem::getInstance()->updateShipTaskItem($item->ShioTask_item_id,["order_info_id"=>$orderItem->order_info_id]);
                    }
                }
            }
        }

        #搜索数据库发货明细表中，crm_order_info_id，且order_info_id为0的数据，根据crm_order_info_id到orders_item_info表中找到，进行补充
        $soItemdata = \Illuminate\Support\Facades\DB::table("shiotask_item")->select("order_info_id","crm_order_info_id","ShioTask_item_id")
            ->where("crm_order_info_id","!=",0)->where("order_info_id",0)->get()->toArray();
        if(!empty($soItemdata)){
            foreach ($soItemdata as $obj){
                $orderItem = OrdersItemInfo::getInstance()->getInfoByCrmOrderInfoId($obj->crm_order_info_id);
                if($orderItem){
                    ShipTaskItem::getInstance()->updateShipTaskItem($obj->ShioTask_item_id,["order_info_id"=>$orderItem->order_info_id]);
                }
            }
        }


        #搜索gd_attachments表，查找crm_shiptask_id不为空，且correlate_id为0的数据， 去shiptask表查Shiptask_id 进行补充
        $attach = Attachments::getInstance()->getSoIdZero(["correlate_id"=>0]);
        if(!empty($attach)){
            foreach ($attach as $att){
                $so = ShipTask::getInstance()->getIdByCrmShipTaskId($att->crm_shiptask_id);
                if(!empty($so)){
                    Attachments::getInstance()->updateByIds([$att->id],["correlate_id"=>$so->Shiptask_id]);
                }
            }
        }
        //----


    }

}

?>
