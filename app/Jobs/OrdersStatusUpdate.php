<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use \App\Services\M\ShipTaskItem;
use \App\Services\M\PurchaseOrder;
use \App\Services\M\PurchaseOrderTaskDetailed;
use \App\Services\M\OrdersItemInfo;
use Illuminate\Support\Facades\DB;


class OrdersStatusUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $shiptask_id;
    private int $id_type;


    /**
     * Create a new job instance.
     */
    public function __construct(int $shiptask_id, int $type = 0)
    {
        $this->shiptask_id = $shiptask_id;
        $this->id_type = $type;
    }

    /**
     * Execute the job.
     * @throws \Throwable
     */
    public function handle(): void
    {
        #发货的时候在订单明细表加总务发货数并修改销售单状态的方法
        $shiptask_id = (int)$this->shiptask_id;
        if ($shiptask_id) {
            $this->updateSendQty($shiptask_id);
        }
    }

    //更新订单详情的发货数量
    private function updateSendQty($shiptask_id)
    {
        $tb = Db::table('shiotask_item as s')
            ->select('s.order_info_id','s.Qtynumber','s.taken_quantity','o.order_id')
            ->leftJoin('orders_item_info as o', 's.order_info_id', 'o.order_info_id');

        if(!$this->id_type){
            $tb = $tb->where('s.Shiptask_id', $shiptask_id);
        }else{
            $tb = $tb->where('s.crm_shiptask_id', $shiptask_id);
        }
        $order_item = $tb->get()->toArray();
        $order_id = [];
        foreach ($order_item as $k => $v) {
            if($v->taken_quantity){
                $res = Db::table('orders_item_info')->where('order_info_id', $v->order_info_id)->increment('SendQty', $v->taken_quantity);
            }else{
                $res = Db::table('orders_item_info')->where('order_info_id', $v->order_info_id)->increment('SendQty', $v->Qtynumber);
            }
            if (!$res) {
                continue;
            }
            $order_id[$v->order_id] = $v->order_id;
        }

        $item = Db::table('orders_item_info')->select('order_id','quantity','SendQty')->whereIn('order_id', $order_id)->get()->toArray();
        foreach ($item as $value) {
            if ($value->SendQty < $value->quantity) {
                unset($order_id[$value->order_id]);
            }
        }

        if (!empty($order_id)) {
            $res = Db::table('orders')->whereIn('order_id', $order_id)->update(['status' => 3]);
        }

    }

}
