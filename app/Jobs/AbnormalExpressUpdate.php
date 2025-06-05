<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use \App\Services\ExpressDelivery;
use \App\Services\Products;

#异常快递状态处理
class AbnormalExpressUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?int $express_id;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $expressId = null)
    {
        $this->express_id = $expressId;
    }

    /**
     * Execute the job.
     * @throws \Throwable
     */
    public function handle(): void
    {
        $data = ExpressDelivery::getInstance()->getAbnormalInfo($this->express_id);
        if(empty($data)) return;

        #处理快递异常状态
        $data->actual_purchaser_id = explode(",", $data->actual_purchaser_id);
        $data->purchaser_id = explode(",", $data->purchaser_id);

        $param = [];
        $param["abnormal_status"] = 3;
        if(array_diff($data->actual_purchaser_id, $data->purchaser_id)){  #仅一部分采购关联了异常快递为处理中
            $param["abnormal_status"] = 2;
        }else{
            #快递单号、型号、数量都对应上，变更为已处理
            $product = \Illuminate\Support\Facades\DB::table("gd_products")->select("id","model","actual_model","quantity","actual_quantity","abnormal_status")
                ->where("express_id",$this->express_id)
                ->where("status",1)->get()->toArray();
            foreach ($product as $obj){
                if($obj->model != $obj->actual_model || $obj->quantity != $obj->actual_quantity){
                    if($obj->quantity ==0 && $obj->abnormal_status){
                        $param["abnormal_status"] = 1;
                    }else{
                        $param["abnormal_status"] = 2;
                    }
                }
                Products::getInstance()->updateModel($obj->id,["abnormal_status"=>$param["abnormal_status"]]);
            }
        }
        #采购已关联，则视为该采购已有提交记录
        $param["abnormal_record"] = implode(",", array_unique($data->purchaser_id));
        $affect = ExpressDelivery::getInstance()->updateExpressDelivery($this->express_id, $param);
    }


}

?>
