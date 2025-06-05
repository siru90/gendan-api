<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use \App\Services\ExpressDelivery;

#更新物流状态
class ExpressDeliverUpdate implements ShouldQueue
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
        # 更新单个快递物流状态
        if(!empty($this->express_id)){
            $data = ExpressDelivery::getInstance()->getExpressDelivery($this->express_id);
            # 判断快递是否存在，且状态为待收
            if($data && $data->submit_status == 1){
                $this->updateExpressSubmitStatus($data);
            }
        }
        else{
            #批量查找待收状态的快递，查看物流，如果物流已签收，则更新快递状态
            $list = ExpressDelivery::getInstance()->getExpressBySubmitStatus(1);
            Log::channel('sync')->info('更新快递物流: list count:'.count($list));
            foreach ($list as $k=>$obj){
                Log::channel('sync')->info('更新快递物流: tracking_number:'.json_encode($obj->tracking_number));
                $this->updateExpressSubmitStatus($obj);
            }
        }

    }


    // 更新快递提交状态
    public function updateExpressSubmitStatus($data)
    {
        # 查物流
        $validated = [
            "com"=>"auto",
            "nu"=>$data->tracking_number,
            "receiver_phone"=>$data->receiver_phone,
            "sender_phone"=>$data->sender_phone,
        ];
        $res = \App\Services\AliDeliver::getInstance()->aliDeliverShowapi($validated);
        Log::channel('sync')->info('AliDeliver: $res :'.json_encode($res));

        $param = $affect = null;
        # 已签收状态，更新数据库状态
        # 阿里的"status": "快递状态 1 暂无记录 2 在途中 3 派送中 4 已签收 (完结状态) 5 用户拒签 6 疑难件 7 无效单 (完结状态) 8 超时单 9 签收失败 10 退回",
        # express_status: 1 暂无记录 2 在途中 3 派送中 4 已签收 (完结状态) 5 用户拒签 6 疑难件 7 无效单 (完结状态) 8 超时单 9 签收失败 10 退回

        if(isset($res["showapi_res_body"])){
            if($res["showapi_res_body"]["ret_code"] != 0){
                /*
                 * 错误码ret_code:
                 *      0查询成功,1输入参数错误,2查不到物流信息,3单号不符合规则,4快递公司编码不符合规则
                 *      5快递查询渠道异常,6auto时未查到单号对应的快递公司,请指定快递公司编码,7单号与手机号不匹配
                 * */
                $param = ["logistic_status"=>$res["showapi_res_body"]["ret_code"]];
            }
            else{
                $param = [
                    "express_status"=>$res["showapi_res_body"]["status"],
                    "logistic_status"=>$res["showapi_res_body"]["ret_code"]
                ];
                if(in_array($res["showapi_res_body"]["status"], [4,7])) {
                    $param["submit_status"] = 2;
                }
            }
            $affect = ExpressDelivery::getInstance()->updateExpressDelivery($data->id,$param);
            Log::channel('sync')->info('AliDeliver: $param :'.json_encode($param) ."\n affect:".$affect);
        }
    }
}

?>
