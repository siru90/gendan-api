<?php

namespace App\Ok;

use App\Utils\GetInstances;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;


class RabbitmqConnection
{
    use GetInstances;

    private ?AMQPStreamConnection $connection = null;


    /**
     * @throws Exception
     */
    public function getConnection(): AMQPStreamConnection
    {
        if (!$this->connection) {
            $this->connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST'),
                env('RABBITMQ_PORT'),
                env('RABBITMQ_USER'),
                env('RABBITMQ_PASS')
            );
        }
        return $this->connection;
    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        //$this->connection->close();
    }


    //
    /**
     * 数据推送到mq(生产者)
     * @param $queue 队列名称
     * @param $exchange 交换机名称
     * @param $routeKey 路由Key名称
     * @param $messageBody 消息体
     */
    public function push($queue,$exchange,$routeKey,$messageBody):void
    {
        if(!empty($messageBody["message_id"])){
            $messageID = $messageBody["message_id"];
            unset($messageBody["message_id"]);
        }
        else{
            $messageID = \App\Services\M2\MqMessage::getInstance()->get_unique_id();
            $message_body2 = array_merge((array)$messageBody,["message_id"=>$messageID]);

            # 推送到外部系统表：mq_message
            $mId = \App\Services\M2\MqMessage::getInstance()->addMQMessage([
                "id"=>$messageID,
                "message_body"=>json_encode($message_body2),
                "message_type"=>$exchange,
                "queue_name"=>$queue,
                "producer_id"=>1,
                "status" => -1,  //status 状态(-1:未推送,0:未消费,1:已消费,2:消费异常)
            ]);
        }
        try {
            # 连接
            $connection = $this->getConnection();
            $channel = $connection->channel();

            # 开启发布确认
            $channel->confirm_select();
            # 成功到达交换机时执行
            $channel->set_ack_handler(function (AMQPMessage $msg) {
                //echo '入队成功逻辑~~~~' . PHP_EOL;
                Log::channel('sync')->info('Rabbit 入队成功: msg='. json_encode($msg));
            });
            # nack,rabbitMQ内部错误时触发
            $channel->set_nack_handler(function (AMQPMessage $msg) {
                //echo 'nack' . PHP_EOL;
                Log::channel('sync')->info('Rabbit set_nack_handler: msg ='.$msg);
            });

            # 创建交换机 durable：持久性，在 RabbitMQ 重启后还会存在
            $channel->exchange_declare($exchange, 'direct', false, true, false);

            # 定义队列
            $channel->queue_declare($queue, false, true, false, false);

            # 队列和交换机绑定
            $channel->queue_bind($queue, $exchange, $routeKey);

            # 定义消息并发送, DELIVERY_MODE_PERSISTENT消息持久化
            $message_body = json_encode($messageBody);
            $msg = new AMQPMessage($message_body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $messageID, // 设置消息 ID
            ]);
            Log::channel('sync')->info('Rabbit $messageID='. $messageID);
            $channel->basic_publish($msg, $exchange, $routeKey);

            # 更新外部系统mq_message表的状态
            \App\Services\M2\MqMessage::getInstance()->editMqMessage($mId,["status"=>0]);

            # 开启确认监听回调等待： wait_for_pending_acks 只能收到 ack 和 nack
            $channel->wait_for_pending_acks();

            $channel->close();
            $this->connection->close();
        }
        catch (\Throwable $e) {
            Log::channel('sync')->info('Rabbit error: '.json_encode( $e->getMessage().'file:'.$e->getFile().$e->getLine(),JSON_UNESCAPED_UNICODE));
            echo '链接失败'.$e->getMessage().'file:'.$e->getFile().$e->getLine();
            $channel->close();
            $this->connection->close();
        }
    }



    /**
     * 取出消息进行消费，并返回(消费者)
     * @param $queue 队列名称
     * @param $exchange 交换机名称
     * @param $routeKey 路由Key名称
     * @param $messageBody 消息体
     */
    public function consume($queue,$exchange,$routeKey):void
    {
        try {
            # 连接
            $connection = $this->getConnection();
            $channel = $connection->channel();

            # 同样是创建路由和队列，以及绑定路由队列，注意要跟producer（生产者）的一致
            //$channel->exchange_declare($exchange, 'direct', false, true, false);
            //$channel->queue_declare($queue, false, true, false, false);
            //$channel->queue_bind($queue, $exchange, $routeKey);

            # 公平分发，消费者端要把自动确认autoAck设置为false，basic_qos才有效
            $channel->basic_qos(0, 1, false);

            # 推模式，通过持续订阅的方式来消费消息
            $channel->basic_consume($queue, '', false, false, false, false, [$this, 'process_message']);

            register_shutdown_function(array($this, 'shutdown'), $channel, $connection);

            while ($channel->is_consuming()) {
            //while (count($channel->callbacks)) {
                $channel->wait();
            }
            $channel->close();
            $this->connection->close();
        }
        catch (\Throwable $e) {
            Log::channel('sync')->info('Rabbit error: '.json_encode( $e->getMessage().'file:'.$e->getFile().$e->getLine(),JSON_UNESCAPED_UNICODE));
            echo '链接失败'.$e->getMessage().'file:'.$e->getFile().$e->getLine();

            $channel->close();
            $this->connection->close();
        }
    }


    /*消息处理: 接口形式调用*/
    public function process_message($message){
        Log::channel('sync')->info('消息处理 Rabbit MESSAGE: '.json_encode($message));

        //$msg ='{"body":"{\"shiptask\":{\"order_id\":\"25791\",\"Shiptask_name\":\"SCN231123351201\",\"Sales_User_ID\":99,\"Customer_Seller_info_id\":40,\"address_customer_info_id\":56,\"Country_id\":0,\"State\":1,\"Sort\":77761,\"Enable\":0,\"Update_tiem\":\"2023-11-23 10:11:36\",\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"Weight\":31.700000000000003,\"weight_unit\":\"kg\",\"Shiptask_id\":8050},\"shiotask_item\":[{\"ShioTask_item_id\":19215,\"Shiptask_id\":8050,\"products_id\":66,\"products_Name\":\"CP5611-A2\",\"Leading_name\":\"2-3 weeks\",\"Model\":\"CP5611-A2\",\"Qtynumber\":10,\"Brand\":6,\"Brand_name\":\"WEINVIEW\",\"Weight\":0.35,\"weight_unit\":\"kg\",\"Purchaser_id\":401468,\"State\":1,\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"order_info_id\":64190},{\"ShioTask_item_id\":19216,\"Shiptask_id\":8050,\"products_id\":7,\"products_Name\":\"6AV2124-1DC01-0AX0\",\"Leading_name\":\"2-3 weeks\",\"Model\":\"6AV2124-1DC01-0AX0\",\"Qtynumber\":1,\"Brand\":1173,\"Brand_name\":\"ACS\",\"Weight\":31,\"weight_unit\":\"kg\",\"Purchaser_id\":401467,\"State\":1,\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"order_info_id\":64191},{\"ShioTask_item_id\":19217,\"Shiptask_id\":8050,\"products_id\":66,\"products_Name\":\"CP5611-A2\",\"Leading_name\":\"3-5 days\",\"Model\":\"CP5611-A2\",\"Qtynumber\":100,\"Brand\":6,\"Brand_name\":\"WEINVIEW\",\"Weight\":0.35,\"weight_unit\":\"kg\",\"Purchaser_id\":401468,\"State\":1,\"create_time\":\"2023-11-23 10:11:36\",\"create_user\":400362,\"order_info_id\":64192}]}","body_size":1305,"is_truncated":false,"content_encoding":null,"delivery_info":{"channel":{"callbacks":{"amq.ctag-QW0hz7acwvYk7v3iB_56bQ":[{},"process_message"]}},"delivery_tag":1,"redelivered":false,"exchange":"outside_shiptask","routing_key":"outside_shiptask","consumer_tag":"amq.ctag-QW0hz7acwvYk7v3iB_56bQ"}} ';
        $body = json_decode($message->body,true);
        Log::channel('sync')->info('body-: '.json_encode($body));

        if ($body === 'quit') {
            Log::channel('sync')->info("---quit--------\n");
            $message->delivery_info['channel']->basic_ack($message->getDeliveryTag());
            # basic_cancel 取消消费者对队列的订阅关系
            $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);
        }

        if($body !== 'quit'){
            $res= null; $exchage = $message->delivery_info["exchange"];
            try {
                if($exchage == "outside_shiptask" || $exchage == "outside_shiptask_test"){
                    $res = \App\Services\Sync\ShipTaskSync::getInstance()->saveShipTask($body);
                    Log::channel('sync')->info("Rabbit [{$exchage}] MESSAGE result: {$res}\n");
                }
                if($exchage == "CRMShipTask"){
                    Log::channel('sync')->info('3333 \n');
                    switch ($body["method"]) {
                        case "addAttachment":
                            //Log::channel('sync')->info('444 \n');
                            $res = \App\Services\Sync\AttachmentSync::getInstance()->addAttach($body);
                            break;
                        case "removeAttachment":
                            //Log::channel('sync')->info('555 \n');
                            $res = \App\Services\Sync\AttachmentSync::getInstance()->removeAttach($body);
                            break;
                        case "editShiptask":
                            //Log::channel('sync')->info('666 \n');
                            $res = \App\Services\Sync\ShipTaskSync::getInstance()->editShiptask($body);
                            break;
                        case "syncKey":
                            //Log::channel('sync')->info('777 \n');
                            $res = \App\Services\Sync\ShipTaskSync::getInstance()->editShiptaskIds($body);
                            break;
                        case "sendShiptask":
                            Log::channel('sync')->info('888 \n');
                            $res = \App\Services\Sync\ShipTaskSync::getInstance()->sendShiptask($body);
                            break;
                        default:
                            $res = true;
                            break;
                    }
                    Log::channel('sync')->info("Rabbit [CRMShipTask] MESSAGE result: {$res}");
                }

                if(!$res){
                    # 记录下来到数据库中
                    \App\Services\SyncCrm::getInstance()->addSyncContent(["sync_content"=>json_encode($body),"sync_res"=>$res]);
                }
                # 手动确认ack，确保消息已经处理
                $message->delivery_info['channel']->basic_ack($message->getDeliveryTag());
                //echo $res ? 'true' : 'false';
            }
            catch (\Throwable $e){
                # 记录下来到数据库中
                \App\Services\SyncCrm::getInstance()->addSyncContent(["sync_content"=>json_encode($body),"sync_res"=>$res]);

                # 手动确认ack，确保消息已经处理
                $message->delivery_info['channel']->basic_ack($message->getDeliveryTag());

                #发送重试信息给消息队列
                //$message->delivery_info['channel']->basic_reject($message->getDeliveryTag(),true);
            }
        }

    }


    /**
     * 关闭进程
     * @param $channel
     * @param $connection
     */
    public function shutdown($channel, $connection)
    {
        Log::channel('sync')->info("shutdown-----------\n");
        $channel->close();
        $connection->close();
    }

    // 简单模式，默认exchange为空
    public function demo()
    {
        try {
            # 连接
            $connection = $this->getConnection();
            $channel = $connection->channel();

            # 定义队列
            $channel->queue_declare('hello', false, true, false, false);

            # 开启发布确认
            $channel->confirm_select();
            # 成功到达交换机时执行
            $channel->set_ack_handler(function(AMQPMessage $msg){
                echo '入队成功逻辑---'.PHP_EOL;
            });
            # nack,rabbitMQ内部错误时触发
            $channel->set_nack_handler(function(AMQPMessage $msg){
                echo 'nack'.PHP_EOL;
            });
            # 交换机路由不到队列监听
            $channel->set_return_listener(function ($reply_code, $reply_text, $exchange, $routing_key, AMQPMessage $message) use ($channel, $connection) {
                echo '消息到达交换机，但是没有进入合适的队列' . PHP_EOL;
                $channel->close();
                $connection->close();
                exit();
            });

            # 定义消息并发送
            $msg = new AMQPMessage('Hello World!');
            $channel->basic_publish($msg, '', 'hello');

            # 开启确认监听回调等待
            # wait_for_pending_acks 只能收到 ack 和 nack，而 wait_for_pending_acks_returns 可以收到 ack、nack 还有交换机路由不到队列的确认回调。
            $channel->wait_for_pending_acks_returns();

            $channel->close();
        }
        catch (\Throwable $e) {
            echo "异常信息".$e->getMessage();
        }
    }


}
