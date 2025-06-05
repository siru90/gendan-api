<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

use \Illuminate\Support\Facades\Redis;

class CrmShipTaskSync extends Command
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel=null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crm-shiptask-queue';  // php artisan crm-shiptask-queue  执行命令

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
        #以前的
        # php artisan app:crm-shiptask-queue crm_so_queue_test
        # php artisan app:crm-shiptask-queue outside_shiptask_test

        $crm_so_queue = env("EXTERNAL_CRM_SO_MQ_QUEUE_NAME");
        $outside_shiptask = env("EXTERNALSO_MQ_QUEUE_NAME");

        $outExchange = $outRouteKey = $crmExchange = $crmRouteKey = "";
        if($outside_shiptask == "outside_shiptask" || $outside_shiptask == "outside_shiptask_test"){
            $outExchange = $outRouteKey = $outside_shiptask;
        }
        if($crm_so_queue == "crm_so_queue" || $crm_so_queue == "crm_so_queue_test"){
            $crmExchange = "CRMShipTask";
            $crmRouteKey = ($crm_so_queue == "crm_so_queue") ? "crm.so.key" : "crm.so.key.test";
        }

        # 连接
        $this->getConnection();
        $this->channel = $this->connection->channel();

        # 同样是创建路由和队列，以及绑定路由队列，注意要跟producer（生产者）的一致
        $this->channel->exchange_declare($outExchange, 'direct', false, true, false);
        $this->channel->queue_declare($outside_shiptask, false, true, false, false);
        $this->channel->queue_bind($outside_shiptask, $outExchange, $outRouteKey);


        $this->channel->exchange_declare($crmExchange, 'direct', false, true, false);
        $this->channel->queue_declare($crm_so_queue, false, true, false, false);
        $this->channel->queue_bind($crm_so_queue, $crmExchange, $crmRouteKey);

        # 公平分发，消费者端要把自动确认autoAck设置为false，basic_qos才有效
        //$channel->basic_qos(0, 1, false);

        # 推模式，通过持续订阅的方式来消费消息
        $this->channel->basic_consume($outside_shiptask, '', false, false, false, false, [$this, 'so_process_init']);
        $this->channel->basic_consume($crm_so_queue, '', false, false, false, false, [$this, 'so_process_edit']);

        //while ($this->channel->is_consuming()) {
        while (count($this->channel->callbacks)) {
            $this->channel->wait();
        }


        $this->channel->close();
        $this->connection->close();
    }


    public function getConnection(): AMQPStreamConnection
    {
        if (!$this->connection) {
            $this->connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST'),
                env('RABBITMQ_PORT'),
                env('RABBITMQ_USER'),
                env('RABBITMQ_PASS'),
                '/',
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                3.0,
                null,
                true,
                30,
            );
        }
        return $this->connection;
    }

    //
    public function so_process_init($message)
    {
        Log::channel('sync')->info('消息处理 Rabbit MESSAGE: '.json_encode($message));
        $body = json_decode($message->body,true);

        if($body != 'quit') {
            $messageID = 0;
            if($message->has('message_id')){
                $messageID = $message->get('message_id');
            }
            # 记录下来到数据库中
            $syncId = \App\Services\SyncCrm::getInstance()->addSyncContent(["sync_content"=>json_encode($body),"sync_res"=>0]);

            $soIniturl = env("APP_URL")."/api/tracking_order/crmsync/so_init";
            $result = \App\Ok\Curl::getInstance()->curlPost($soIniturl, $body);
            \App\Services\SyncCrm::getInstance()->updateSyncContent($syncId,["sync_res"=>$result["data"]["res"]??0]);  #更改同步状态
            if($messageID){
                $this->editMqMessage($messageID,$result["data"]["res"]??1);
            }
        }
        # 手动确认ack，确保消息已经处理
        $message->delivery_info['channel']->basic_ack($message->getDeliveryTag());
        if($body === 'quit') {
            # basic_cancel 取消消费者对队列的订阅关系
            $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);

            #抛个异常重启守护进程，抛出一个运行时异常
            throw new \RuntimeException('Something went wrong.');
        }
    }


    public function so_process_edit($message)
    {
        Log::channel('sync')->info('消息处理 Rabbit MESSAGE: '.json_encode($message));
        $body = json_decode($message->body,true);
        if($body != 'quit') {
            $messageID = 0;
            if($message->has('message_id')){
                $messageID = $message->get('message_id');
            }
            # 记录下来到数据库中
            $syncId = \App\Services\SyncCrm::getInstance()->addSyncContent(["sync_content"=>json_encode($body),"sync_res"=>0]);

            $soEditurl = env("APP_URL")."/api/tracking_order/crmsync/so_edit";
            $result = \App\Ok\Curl::getInstance()->curlPost($soEditurl, $body);
            \App\Services\SyncCrm::getInstance()->updateSyncContent($syncId,["sync_res"=>$result["data"]["res"]??0]); #更改同步状态
            if($messageID){
                $this->editMqMessage($messageID,$result["data"]["res"]??1);
            }
        }

        # 手动确认ack，确保消息已经处理
        $message->delivery_info['channel']->basic_ack($message->getDeliveryTag());
        if($body === 'quit') {
            # basic_cancel 取消消费者对队列的订阅关系
            $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);

            #抛个异常重启守护进程，抛出一个运行时异常
            throw new \RuntimeException('Something went wrong.');
        }
    }


    public function editMqMessage($messageID,$result)
    {
        $param = ["status"=>1,"consumed_at"=>date("Y-m-d H:i:s")];
        if(empty($result)){
            $param["status"]=2;
        }
        \App\Services\M2\MqMessage::getInstance()->editMqMessage($messageID,$param);
    }




}

?>
