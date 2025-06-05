<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use \Illuminate\Support\Facades\Redis;
use \App\Ok\Locker;

class ExpressPurchaseSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $args;
    private string $type;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, array $args)
    {
        $this->type = $type;
        $this->args = $args;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if($this->type == "add"){
            $this->addExpressPurchase();
        }
        else if($this->type == "remove"){
            #同步删除： 外部系统删除同步到内部系统
            $this->removeExpressPurchase();
        }
        else if($this->type == "syncAddExternalCrm"){
            $this->syncExpressToExternalCrm();
        }
        else if($this->type == "syncRemoveExternalCrm"){
            $this->syncRemoveToExternalCrm();
        }
    }

    //同步数据,外部系统快递同步到数据库
    public function addExpressPurchase()
    {
        Log::channel('sync')->info('Jobs\ExpressPurchaseSync--add--begin--');
        \App\Services\Sync\ExpressPurchaseSync::getInstance()->saveExpressPurchase($this->args);
    }

    //同步删除： 外部系统删除同步到内部系统
    public function removeExpressPurchase()
    {
        Log::channel('sync')->info('Jobs\ExpressPurchaseSync--remove--begin--');
        \App\Services\Sync\ExpressPurchaseSync::getInstance()->removeExpressPurchase($this->args);
    }

    //同步内部快递-到外部
    public function syncExpressToExternalCrm()
    {
        Log::channel('sync')->info('Jobs\ExpressPurchaseSync--syncExpressToCrm--begin--');
        \App\Services\Sync\ExpressPurchaseSync::getInstance()->syncExpressToExternalCrm($this->args);
    }

    //同步内部快递删除-到外部
    public function syncRemoveToExternalCrm()
    {
        Log::channel('sync')->info('Jobs\ExpressPurchaseSync--syncRemoveToCrm--begin--');

        $eo = DB::table("gd_express_order")->where('id',$this->args["id"])->first();
        $eo->updated_at_str = $eo->updated_at;
        $eo->created_at_str = $eo->created_at;
        unset($eo->updated_at,$eo->created_at);

        $data = new \stdClass();
        $data->GdExpressOrders = [$eo];

        # 调用curl，请求接口
        $url = env("EXTERNAL_CRM_HTTP_URL")."/crm/PurchaseOrder/ReExpressOrderStateSyncCrm";
        $output = \App\Ok\Curl::getInstance()->curlPost($url, $data);
    }

}
