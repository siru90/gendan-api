<?php

namespace App\Services\M2;

use Illuminate\Support\Facades\DB;


class MqMessage extends \App\Services\BaseService
{
    //外部系统数据库表
    protected ?string $connection = 'mysql_external';

    protected string $table = 'mq_message'; #MQ消息推送记录


    public function addMQMessage($args): int|bool
    {
        $args["message_from"] = 1;
        $args["status"] = 0;  #状态(-1:未推送,0:未消费,1:已消费,2:消费异常)
        return $this->tb->insert($args);
    }

    public function editMqMessage($id, $args):int|bool
    {
        return $this->tb->where("id",$id)->update($args);
    }

    public function getStatusIsZero()
    {
        return $this->tb->where("status",0)->where("queue_name","crm_so_queue")->where("created_at",">","2024-09-28")->get()->toArray();
    }

}
