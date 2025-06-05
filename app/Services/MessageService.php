<?php

namespace App\Services;

use \Illuminate\Support\Facades\DB;
use \App\Services\M\User as MissuUser;
use \App\Services\M\Orders;
use \App\Services\M\PurchaseOrderDetailed;
use \Illuminate\Support\Facades\Log;

class MessageService extends BaseService
{
    const REDIS_NOTIFY_KEY = 'gd.ws.notify.list.0001.';

    //so核对操作消息
    public function soKdMessage($userId,$shiptask_id,$shiptask_name, $order_ids)
    {
        $tmp = [
            "user_id" => $userId,
            "title" => "您的SO已进入打包流程，点击查看",
            "content" =>  $shiptask_name."运输单总务已提交至打包",
            "source" => "APP内推送",
            "jump_url" => "/soModule/pages/soDetails/soDetails?id=".$shiptask_id."&invoice=".$shiptask_name."&processIdentification=documentation",
            "recipient" => "",
        ];
        $recipient = $this->returnSoRecipient(explode(",", $order_ids));
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }


    //so打包：发送消息：装箱完毕
    public function soDbMessage($userId,$shiptask_id,$shiptask_name,$order_ids)
    {
        $tmp = [
            "user_id" => $userId,
            "title" => "您的SO已打包完毕，请及时处理",
            "content" =>  $shiptask_name."运输单已打包完毕",
            "source" => "APP内推送",
            "jump_url" => "/soModule/pages/soDetails/soDetails?id=".$shiptask_id."&invoice=".$shiptask_name."&processIdentification=documentation",
            "recipient" => "",
        ];
        $recipient = $this->returnSoRecipient(explode(",", $order_ids));
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }

    //so确认发货消息
    public function soConfirmMessage($userId,$shiptask_id,$shiptask_name,$order_ids)
    {
        $tmp = [
            "user_id" => $userId,
            "title" => "您的SO已发货，点击查看",
            "content" =>  $shiptask_name."运输单已发货！",
            "source" => "APP内推送",
            "jump_url" => "/soModule/pages/soDetails/soDetails?id=".$shiptask_id."&invoice=".$shiptask_name."&processIdentification=documentation",
            "recipient" => "",
        ];
        $recipient = $this->returnSoRecipient(explode(",", $order_ids));
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }

    //返回so单有关联的销售ID
    private function returnSoRecipient($order_ids)
    {
        $recipient = [];  //与该SO单有关联的销售
        #获取销售ID
        $order = Orders::getInstance()->getByIdsF1($order_ids);
        foreach ($order as $item){
            $recipient[] = $item->Sales_User_ID;
        }
        return $recipient;
    }

    //核对录入序列号消息
    public function checkSubmit(int $userId, array $args)
    {
        #发送消息提醒
        $tmp = [
            "user_id" => $userId,
            "title" => "您有一个采购订单正在等待验货，请及时处理！",
            "content" =>  $args["PI_name"]."销售订单提交了新的货物正在等待采购验货",
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$args["order_id"]."&piName=".$args["PI_name"],
            "recipient" => "",
        ];
        #该采购订单关联的采购
        $recipient = [$args["purchaser_id"]];
        $shareUserIds = PermissionShare::getInstance()->orderIdShareUser($recipient,$args["order_id"]);
        $recipient = array_unique(array_merge($recipient,$shareUserIds));
        Log::channel('sync')->info("--核对录入序列号消息-111".json_encode($recipient). "\n");

        #判断是否已存在未读，的验货消息
        foreach ($recipient as $key=>$id){
            $mess = Message::getInstance()->getMessageByArgs(["title"=>$tmp["title"],"content"=>$args["PI_name"],"recipient" => $id,"is_read" =>0]);
            if($mess){
                Message::getInstance()->updateMessage($mess->message_id,["updated_at"=>date("Y-m-d H:i:s")]);
                unset($recipient[$key]);
            }
        }

        Log::channel('sync')->info("--核对录入序列号消息-222-".json_encode($recipient). "\n");

        if(count($recipient)){
            Message::getInstance()->addMessage($tmp,$recipient);
        }
        $this->syncNotify($tmp,$recipient);
    }

    //核对验货消息:异常
    public function checkInspectMessage(int $userId,array $args)
    {
        #采购验货完成，如有异常情况，给相关销售发销售
        $recipient = [$args["Sales_User_ID"]];
        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$args["order_id"]."&piName=".$args["PI_name"],
            "title" => "您的销售订单有异常情况，请及时审核！",
            "content" => $args["PI_name"]."销售订单有图片异常/数量异常",
        ];
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }

    //核对验货消息:正常
    public function checkInspectMessageTwo(int $userId,array $args)
    {
        #采购提交验货，给销售发送消息。（当消息未读时，采购继续提交验货，不再次发送。）
        $recipient = [$args["Sales_User_ID"]];
        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$args["order_id"]."&piName=".$args["PI_name"],
            "title" => "您的销售订单有到货，请及时查看！",
            "content" => $args["PI_name"]."销售订单".implode("、", $args["model"])."有到货。",
        ];

        #判断是否已存在未读，的验货消息
        $mess = Message::getInstance()->getMessageByArgs([
            "title"=>$tmp["title"],
            "content"=>$args["PI_name"],
            "recipient" => $args["Sales_User_ID"],
            "is_read" =>0,
        ]);
        if($mess){
            Message::getInstance()->updateMessage($mess->message_id,["updated_at"=>date("Y-m-d H:i:s")]);
        }else{
            Message::getInstance()->addMessage($tmp,$recipient);
        }
        $this->syncNotify($tmp,$recipient);
    }

    //核对审核消息
    public function checkAudit(int $userId, array $args, array $filIds, string $str)
    {
        #货物异常，销售已审核，给所有跟单员和有提交异常的采购发送消息
        $recipient = [];
        $user = MissuUser::getInstance()->getUserByGroupId(98);  #98跟单员
        foreach ($user as $val){
            $recipient[] = $val->id;
        }

        #根据$filIds(gd_attachments表的id) 得到采购详单ID，拿到对应的采购Id
        $pod_ids = DB::table("gd_attachments")->select("pod_id")->whereIn("id",$filIds)->pluck("pod_id")->toArray();
        $pur_ids = DB::table("purchaseorder_detailed")->select("Purchaser_id")->whereIn("Purchaseorder_detailed_id", $pod_ids)->pluck("Purchaser_id")->toArray();
        $recipient = array_merge($recipient,$pur_ids);

        #被分享的用户ID也发消息
        $shareUserIds = PermissionShare::getInstance()->orderIdShareUser($pur_ids,$args["order_id"]);
        $recipient = array_merge($recipient,$shareUserIds);

        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/checkDetails/checkDetails?id=".$args["order_id"]."&piName=".$args["PI_name"],
            "title" => "有已审核的销售订单，点击查看",
            "content" => $args["PI_name"]."订单,销售已".$str,
        ];
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }


    //添加异常快递发送消息
    public function addAbnormalExpress(int $userId, array $args)
    {
        #点击跳转到核对异常列表
        $recipient = explode(",", $args["actual_purchaser_id"]);
        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/addEditAbnormalExpress/addEditAbnormalExpress?id=".$args["tracking_id"], ///checkModule/pages/fastMailRelatedInfor/fastMailRelatedInfor?id=快递id
            "title" => "您有未关联的快递，请及时处理！",
            "content" => "您的快递已到达仓库",
        ];

        #被分享的用户ID也发消息
        $shareUserIds = PermissionShare::getInstance()->expressIdShareUser($recipient,$args["tracking_id"]);
        $recipient = array_merge($recipient,$shareUserIds);

        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }

    //异常快递提交采购
    public function submitAbnormalExpress(int $userId, array $args)
    {
        #给快递单号关联的采购发送消息
        $recipient = [$args["recipient"]];
        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/expressRelatedInfor/expressRelatedInfor?id={$args["order_id"]}&expressId={$args["express_id"]}",  #点击跳转到编辑页面
            "title" => "您有需确认异常快递，请及时查看！",
            "content" => $args["express_number"]."待确认",
        ];

        #被分享的用户ID也发消息
        $shareUserIds = PermissionShare::getInstance()->orderIdShareUser($recipient,$args["order_id"]);
        $recipient = array_merge($recipient,$shareUserIds);

        $shareUserIds = PermissionShare::getInstance()->expressIdShareUser($recipient,$args["express_id"]);
        $recipient = array_merge($recipient,$shareUserIds);

        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }

    //异常快递型号数量入库失败：给创建这条异常快递信息的用户发送信息
    public function AbnormalExpressSyncPiQty(int $userId, array $args)
    {
        #点击跳转到核对异常列表
        $recipient = [];
        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "title" => "您的异常快递型号数量同步失败，点击查看",
            "content" => $args["tracking_number"]."(快递单号）".$args["model"]."数量同步失败",
        ];
        if($args["abnormal_source"]){ #新增
            $tmp["jump_url"] = "/checkModule/pages/addEditAbnormalExpress/addEditAbnormalExpress?id=".$args["id"];
        }else{  #编辑
            $tmp["jump_url"] = "/checkModule/pages/expressRelatedInfor/expressRelatedInfor?id={$args["order_id"]}&expressId={$args["id"]}";
        }
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }

    //异常快递切换为已处理之后，给所有的跟单员发送消息（变更为处理中、已处理都发送一条消息）
    public function changeAbnormalStatus(int $userId, array $args)
    {
        #点击跳转到核对异常列表
        $recipient = [];
        $user = MissuUser::getInstance()->getUserByGroupId(98);  #98跟单员
        foreach ($user as $val){
            $recipient[] = $val->id;
        }
        $tmp = [
            "user_id" => $userId,
            "source" => "APP内推送",
            "jump_url" => "/checkModule/pages/expressRelatedInfor/expressRelatedInfor?id=".$args["order_id"]."&expressId=".$args["express_id"],  // /checkModule/pages/addEditAbnormalExpress/addEditAbnormalExpress?id=快递id
            "title" => "有已处理的异常快递，请及时查看！",
            "content" => $args["tracking_number"]."(快递单号）已处理",
        ];
        Message::getInstance()->addMessage($tmp,$recipient);
        $this->syncNotify($tmp,$recipient);
    }


    //异步发送消息
    public function syncNotify($tmp,$recipient)
    {
        $key = self::REDIS_NOTIFY_KEY;
        foreach ($recipient as $userId){
            $listKey = sprintf("%s%s", $key, $userId);
            \Illuminate\Support\Facades\Redis::command("publish", [$listKey, json_encode([
                'syncMessage',
                [
                    'title' => $tmp["title"],
                    'content' => $tmp["content"],
                    'source' => $tmp["source"],
                ],
            ])]);
            \Illuminate\Support\Facades\Redis::command("expire", [$listKey, 3600 * 24]);
        }
    }


    //异步订阅消息
    public function syncSubscribe($userId): ?string
    {
        $prefix = config('database.redis.options.prefix');  //默认前缀laravel_database_
        //$key = self::REDIS_NOTIFY_KEY;


        $redis = new \Redis();
        $redis->connect(env("REDIS_HOST"), env("REDIS_PORT"), 2.5);
        $redis->setOption(\Redis::OPT_PREFIX, $prefix);

        $result = null;
        try {
            $key = sprintf("%s%s", self::REDIS_NOTIFY_KEY, $userId);  #key=laravel_database_gd.ws.notify.list.0001.***
            $redis->subscribe([$key], function (\Redis $redis, $channel, $msg) use ($prefix, $key, &$result) {
                if ($channel == $prefix . $key) {
                    [$type, $data] = json_decode($msg);
                    $result = 'json: ' . json_encode([
                            'notify_type' => $type,
                            'data' => $data,
                        ]);
                    $redis->close();
                }
            });
        } catch (\Exception $e) {
            echo "line: ", $e->getLine(), ", code: ", $e->getCode(), ", message: ", $e->getMessage(), "\n";
            //Log::channel('sync')->info("line: ". $e->getLine().", code: ". $e->getCode(). ", message: ". $e->getMessage(). "\n");
        }
        return $result;
    }

}
