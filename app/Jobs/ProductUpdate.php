<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use \App\Services\M\PurchaseOrderDetailed;
use \App\Services\M\PurchaseOrder;
use \App\Services\M\PurchaseOrderTaskDetailed;
use \App\Services\SerialNumbers;
use \App\Services\Products;
use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\ExpressOrders;


class ProductUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?int $product_id;
    private ?int $purchaseorder_detailed_id;  //?代表这是一个可空类型，意味着该变量可以是该类型或者null
    private ?int $order_id;

    /**
     * Create a new job instance.
     */
    public function __construct(?array $args, ?int $product_id)
    {
        $this->order_id = isset($args["order_id"]) ? $args["order_id"] : 0;
        $this->purchaseorder_detailed_id = isset($args["purchaseorder_detailed_id"]) ? $args["purchaseorder_detailed_id"]: 0;
        $this->product_id = $product_id;
    }

    /**
     * Execute the job.
     * @throws \Throwable
     */
    public function handle(): void
    {
        $product_id = (int)$this->product_id;
        if ($product_id) {
            $this->product($product_id);
            $this->updateState($product_id);
        }

        if($this->purchaseorder_detailed_id){
            $this->updatePurState($this->purchaseorder_detailed_id);
        }

        if($this->order_id){
            $this->updateOrder($this->order_id);
        }

    }

    //
    private function updateOrder($order_id)
    {
        $orderInfo = OrdersItemInfo::getInstance()->getOrderItemsF1($order_id);
        foreach ($orderInfo as $val){
            $actualNum = SerialNumbers::getInstance()->getSumQuantity(["order_info_id"=>$val->order_info_id]); #统计实到件数

            # orders_item_info表State字段：1未分配订货，2预定货，3待收货，4待分配发货，5部分分配发货，6已分配发货，7已签收，8部分订货，9部分到货、10全部到货
            $state = 9;
            if($actualNum >= $val->quantity){
                $state = 10;
                #更新详细表
                PurchaseOrderTaskDetailed::getInstance()->updateTaskDetail($val->order_info_id,["State"=>3]);
                PurchaseOrderDetailed::getInstance()->updateByOrderInfoId($val->order_info_id,["pod.State"=>4]);
            }
            else{
                #更新详细表
                PurchaseOrderTaskDetailed::getInstance()->updateTaskDetail($val->order_info_id,["State"=>2]);
                PurchaseOrderDetailed::getInstance()->updateByOrderInfoId($val->order_info_id,["pod.State"=>3]);
            }

            $podList = PurchaseOrderDetailed::getInstance()->getPurchaseOrderByOrderInfoId($val->order_info_id);
            foreach ($podList as $pod){
                $this->updatePurState($pod->Purchaseorder_detailed_id);
            }

            $tmp = ["purchase_arrival_qty"=>$actualNum,"State"=>$state];
            OrdersItemInfo::getInstance()->updateOrdersItem($val->order_info_id,$tmp);
        }

        #订单状态 orders.`status` '订单状态 1-待开始，2-进行中， 3-已完成'
        Orders::getInstance()->updateOrder($order_id,["status"=>2]);
    }


    //更新采购单状态
    private function updatePurState($purchaseorder_detailed_id):void
    {
        $podInfo = PurchaseOrderDetailed::getInstance()->getInfo($purchaseorder_detailed_id); #当前单个产品
        $purchaseorder_id = $podInfo->Purchaseorder_id;
        $podItems = PurchaseOrderDetailed::getInstance()->getByPoIdF1($purchaseorder_id);  #当前采购单所有产品

        #循环所有采购详单
        $serialQuantity = $state = $modelNum = [];
        foreach ($podItems as $val){
            $val->serialQuantity = SerialNumbers::getInstance()->getSumQuantity(["purchaseorder_detailed_id"=>$val->Purchaseorder_detailed_id]);  #统计采购详单的序列号总数
            if($purchaseorder_detailed_id == $val->Purchaseorder_detailed_id){
                $serialQuantity = $val->serialQuantity;
            }
            if($val->serialQuantity == $val->Qtynumber){ #purchaseorder_detailed表，State 采购状态  1代付款  2已付款 3 到货中 4采购完毕
                $state[] = 4;
                $podInfo->State = 4;
            }
            /*else if(($val->serialQuantity != $val->Qtynumber) && $val->serialQuantity>0){
                $state[] = 3;
                $podInfo->State = 3;
            }*/
            else{
                $state[] = $podInfo->State;
                $podInfo->State = 3;
            }
        }
        $state = array_unique($state);

        #采购任务详细信息
        $poTd = PurchaseOrderTaskDetailed::getInstance()->getByIdAS($podInfo->Purchaseordertesk_detailed_id);

        #销售详单到货数量
        $serialQ = SerialNumbers::getInstance()->getSumQuantity(["order_info_id"=>$poTd->order_info_id]);

        if(count($state)==1 && $state[0]==4){  #采购完成，更新采购订单，采购任务相关的状态
            $this->updateStateToComplete($purchaseorder_id,$poTd->Purchaseordertesk_id,$purchaseorder_detailed_id,$serialQ);
        }
        else{  #采购到货中
            $this->updatePartState($purchaseorder_id,$purchaseorder_detailed_id, $poTd->Purchaseordertesk_id,$podInfo->Purchaseordertesk_detailed_id,$podInfo->State,$serialQ);
        }

        #更新缓存
        //$this->setPurchaseorder($purchaseorder_id);

        #判断销售订单的明细状态
        //$this->updateOrderItemState($poTd->order_info_id,$podInfo->State,$serialQ);

    }

    #更新缓存
    private function setPurchaseorder($purchaseorder_id)
    {
        //\Illuminate\Support\Facades\Redis::command("del", ["gd_purchaseorder_".$purchaseorder_id]);
        $purchaseorder = PurchaseOrder::getInstance()->getByIdF3($purchaseorder_id);
        \Illuminate\Support\Facades\Redis::command("set", ["gd_purchaseorder_".$purchaseorder_id, json_encode($purchaseorder), ['EX' => 3600 * 24]]);
    }


    /**
     * @throws \Throwable
     */
    // 编辑快递产品，会调用此方法
    // 根据序列号数量，统计产品的数量、提交数量
    private function product(int $product_id): void
    {
        Products::getInstance()->db->beginTransaction();
        try {
            $sum = SerialNumbers::getInstance()->getProductSerialNumbersCount($product_id);
            Products::getInstance()->tb->where('id', $product_id)->update([
                'quantity' => $sum,
            ]);

            #加个处理，自动填充gd_express_order表的submitted_quantity， 序列号加删改

            # 统计产品ID 关联的采购单个数
            $num = ExpressOrders::getInstance()->countPodIDByProductId($product_id);
            if($num == 1){
                ExpressOrders::getInstance()->setSubmittedQuantityF2($product_id,$sum);
            }

            # 根据产品ID，查所有采购单总数$total（quantity）
            $totalQuantity = ExpressOrders::getInstance()->getSumQuantity($product_id);
            # 如果$sum = $total, 则更新gd_express_order表submitted_quantity数量 = quantity
            if($sum == $totalQuantity){
                $res = ExpressOrders::getInstance()->setSubmittedQuantity($product_id);
            }else{
                if($num > 1){
                    ExpressOrders::getInstance()->clearSubmittedQuantity($product_id);
                }
            }
            Products::getInstance()->db->commit();
        } catch (\Throwable $e) {
            Products::getInstance()->db->rollBack();
            throw $e;
        }

    }


    //更新产品对应的采购单状态
    private function updateState(int $product_id):void
    {
        $product = Products::getInstance()->getProduct($product_id);
        #根据产品ID查对应的所有采购单
        $expressOrder = ExpressOrders::getInstance()->getProductSubmitAndModel(["product_id"=>$product_id]);
        if (!$expressOrder) return;
        $pod_arr = [];
        foreach ($expressOrder as $obj){
            $pod_arr[] = $obj->purchaseorder_id;
        }
        $pod_arr = array_unique($pod_arr);

        # 可能会有多个采购单
        foreach($pod_arr as $purchaseorder_id){

            # 采购订单详单，按model分组 计算总的采购数量;   $podInfo表示当前快递型号对应的采购详单
            $podModelQuantity = []; $podInfo = [];
            $pods = PurchaseOrderDetailed::getInstance()->getPods($purchaseorder_id);

            //var_dump($pods);

            foreach ($pods as $pod) {
                if($pod->Model == $product->model){
                    $podInfo = $pod;
                }
                $podModelQuantity[$pod->products_Name] = ($podModelQuantity[$pod->products_Name] ?? 0) + $pod->Qtynumber;
            }

            # 获取采购订单的提交信息,按model分组 计算总的提交数量
            $submitModelQuantity = [];
            $submits = ExpressOrders::getInstance()->getProductSubmitAndModel(["purchaseorder_id"=>$purchaseorder_id]);
            foreach ($submits as $submit) {
                $submitModelQuantity[$submit->model] = ($submitModelQuantity[$submit->model] ?? 0) + $submit->submitted_quantity;
            }

            # 取两个数组的差集
            $diff1 = array_diff_assoc($submitModelQuantity, $podModelQuantity);
            $diff2 = array_diff_assoc($podModelQuantity, $submitModelQuantity);

            if (!$podInfo) return;
            #采购任务详细信息
            $poTd = PurchaseOrderTaskDetailed::getInstance()->getByIdAS($podInfo->Purchaseordertesk_detailed_id);

            # 采购完成，更新采购订单，采购任务相关的状态
            if (empty($diff1) && empty($diff2)) {
                $this->updateStateToComplete($purchaseorder_id,$poTd->Purchaseordertesk_id);
            }
            elseif (!empty($diff1) || !empty($diff2))
            {
                # 部分采购完成
                # 当前采购数量 和 已提交数量 比较，更新当前采购订单详细状态
                if( !empty($submitModelQuantity[$podInfo->Model]) && ($podInfo->Qtynumber == $submitModelQuantity[$podInfo->Model])){
                    $state = 4;  //更新为4
                }else{
                    $state= 3;  //更新为3
                }

                $this->updatePartState($podInfo->Purchaseorder_id,$podInfo->Purchaseorder_detailed_id, $poTd->Purchaseordertesk_id,$podInfo->Purchaseordertesk_detailed_id,$state,$submitModelQuantity[$podInfo->Model]);
            }
        }
    }


    //采购完成
    private function updateStateToComplete($purchaseorder_id,$purchaseordertesk_id,$purchaseorder_detailed_id,$serialQuantity)
    {
        $affected = PurchaseOrder::getInstance()->updateAllStateToComplete($purchaseorder_id, $purchaseordertesk_id);

        #配置RabbitMq的队列，交换机，路由key
        $order_rabbitmq = \Illuminate\Support\Facades\Config::get('app.order_rabbitmq');

        # 同步数据到RabbitMq
        $messageBody = [
            "method"=>"updateAllStateToComplete", //全部采购完成
            "purchaseorder_id"=>$purchaseorder_id,
            "purchaseorder_detailed_id"=>$purchaseorder_detailed_id,
            "purchaseordertesk_id"=>$purchaseordertesk_id,
            "serialQuantity" => $serialQuantity,  //表示采购订单对应的销售明细总的序列号数量
            "sate"=>4,
        ];
        \App\Ok\RabbitmqConnection::getInstance()->push($order_rabbitmq["queue"],$order_rabbitmq["exchange"],$order_rabbitmq["routeKey"],$messageBody);

        /*
            # 调用curl，发送消息到外部系统
            $url = env('MESSAGE_URL')."/api/systemMessage/add";
            $data = [
                "event_name"=>"PurchaseOrder_Signed",
                "event_params"=>[
                    "purchaseorder_id"=>16510,
                ]
            ];
            $output = \App\Ok\Curl::getInstance()->curlPost($url, $data);
            OplogApi::getInstance()->addLog($info->user_id, "curl: $url ", " [data]:".json_encode($data)." [output]:".json_encode($output));
        */
    }

    //采购部分完成
    private function updatePartState($purchaseorder_id,$purchaseorder_detailed_id,$purchaseordertesk_id,$purchaseordertesk_detailed_id,$state,$serialQuantity)
    {
        $affected = PurchaseOrder::getInstance()->updatePartStateToComplete($purchaseorder_id,$purchaseorder_detailed_id,
            $purchaseordertesk_id,$purchaseordertesk_detailed_id,$state);

        #配置RabbitMq的队列，交换机，路由key
        $order_rabbitmq = \Illuminate\Support\Facades\Config::get('app.order_rabbitmq');

        //同步数据到RabbitMq
        $messageBody = [
        "method"=>"updatePartStateToComplete", //部分采购完成
        "purchaseorder_id"=>$purchaseorder_id,
        "purchaseorder_detailed_id"=>$purchaseorder_detailed_id,
        "purchaseordertesk_id"=>$purchaseordertesk_id,
        "purchaseordertesk_detailed_id"=>$purchaseordertesk_detailed_id,
        "serialQuantity" => $serialQuantity,  //表示采购订单对应的销售明细总的序列号数量
        "sate"=>$state,
        ];
        \App\Ok\RabbitmqConnection::getInstance()->push($order_rabbitmq["queue"],$order_rabbitmq["exchange"],$order_rabbitmq["routeKey"],$messageBody);
    }


    //销售订单：部分到货/全部到货
    private function updateOrderItemState($order_info_id,$state,$serialQ)
    {
        #purchaseorder_detailed表，State：采购状态  1代付款  2已付款 3 到货中 4采购完毕
        #purchaseorder_detailed表，State：1未分配订货，2预定货，3待收货，4待分配发货，5部分分配发货，6已分配发货，7已签收(弃用)，8部分订货, 9部分到货，10全部到货

        $order = OrdersItemInfo::getInstance()->getByIdAS($order_info_id);
        if($state == 3){  #采购状态，到货中
            $orderItemState = 9;
        }
        else if($state == 4){
            //$order_info_id 对应有几个采购单，每个采购单的状态是不是采购完毕(且所有数量加起来等于销售的数量)，就能知道这个销售细单是不是全部到货
            $task = PurchaseOrderTaskDetailed::getInstance()->getByOiIdF1($order_info_id);
            $taskIds=[];
            foreach ($task as $v){
                $taskIds[] = $v->Purchaseordertesk_detailed_id;
            }
            $item = PurchaseOrderDetailed::getInstance()->getByTaskIdAS($taskIds);
            $state=[]; $total=0;
            foreach ($item as $v){
                $state[] = $v->State;
                $total += $v->Qtynumber;
            }
            $state = array_unique($state);
            if($total == $order->quantity  && (count($state)==1 && $state[0]==4)){  #采购的数量=销售数量 && 采购的状态为完毕
                $orderItemState = 10;
            }else{
                $orderItemState = 9;  #9部分到货,10全部到货
            }
        }

        if($state == 3 || $state == 4){
            \App\Services\M\OrdersItemInfo::getInstance()->updateOrdersItem($order_info_id,["State"=>$orderItemState]);
        }

    }
}
