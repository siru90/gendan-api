<?php

namespace App\Services;

class Message extends BaseService
{
    protected string $table = 'gd_message';


    //添加消息
    public function addMessage(array $args,$recipient):int|bool
    {
        $recipient = array_unique($recipient);  #去重
        $this->db->beginTransaction();
        try {
            $data = [];
            foreach ($recipient as $obj){
                $args["message_id"] = $this->get_unique_id();
                $args["recipient"] = $obj;
                $data[] = $args;
            }
            $affected = $this->tb->insert($data);
            $this->db->commit();
            return $affected;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // 返回最新的一条
    public function getMessageOne(?int $userId=null):array
    {
        $tb = $this->tb;
        if(!empty($userId)){
            $tb = $tb->where("recipient", $userId);
        }
        $tb = $tb->where("is_read",0)->where("status",1);
        $totalTb = clone $tb;
        $data = $tb->orderBy('message_id','desc')->first();
        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT message_id) count')->first();
        $total = $totalResult->count ?? 0;
        $data = !empty($data)? $data : [];
        return [$data,$total];
    }

    public function getMessageById(int $id):object|null
    {
        return $this->tb->where("message_id",$id)->where("status",1)->first();
    }

    public function getMessageByArgs(array $args):object|null
    {
        $tb = $this->tb;
        if(!empty($args["title"])){
            $tb = $tb->where("title", $args["title"]);
        }
        if(!empty($args["content"])){
            $tb = $tb->where("content", "like","{$args["content"]}%");
        }
        if(!empty($args["recipient"])){
            $tb = $tb->where("recipient", $args["recipient"]);
        }
        if(isset($args["is_read"])){
            $tb = $tb->where("is_read", $args["is_read"]);
        }
        return $tb->where("status",1)->first();
    }

    //分页获取消息
    public function getMessageList(array $args):array
    {
        $offset = max(0, ($args["page"] - 1) * $args["size"]);
        $tb = $this->tb;
        if(!empty($args["userId"])){
            $tb = $tb->where("recipient", $args["userId"]);
        }
        $tb = $tb->where('status', 1);
        $totalTb = clone $tb;

        $tb = $tb->orderBy('is_read',"asc")->orderBy('message_id','desc')->offset($offset)->limit($args["size"]);

/*        $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->get()->toArray();
        $totalResult = $totalTb->selectRaw('COUNT(DISTINCT message_id) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    //删除已读
    public function deleteRead(int $userId):int
    {
        return $this->tb->where('recipient', $userId)->where("is_read",1)->where('status', 1)->update([
        'status' => -1,
        ]);
    }

    //删除，根据Id
    public function deleteMessage(int $id,$userId):int
    {
        return $this->tb->where('recipient', $userId)->where('message_id', $id)->where("status",1)->update([
            'status' => -1,
        ]);
    }

    //更新未读为已读
    public function updateIsRead(int $userId):int
    {
        return $this->tb->where('recipient', $userId)->where("is_read",0)->where('status', 1)->update([
            'is_read' => 1,
        ]);
    }

    //更新消息
    public function updateMessage(int $Id,array $args):int
    {
        return $this->tb->where('message_id', $Id)->where("status",1)->update($args);
    }



}
