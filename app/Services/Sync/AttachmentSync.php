<?php

namespace App\Services\Sync;


use Illuminate\Support\Facades\Log;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;


class AttachmentSync extends \App\Services\BaseService
{
    //附件同步：添加SO图片,视频
    public function addAttach(array $args):bool|int
    {
        //{"method":"addAttachment","params":{"crm_files_id":"1714098678114703665","path":"http:\/\/files-1306719578.cos.ap-guangzhou.myqcloud.com\/662b11f6083ce.jpg","disk":"tencent","name":"http:\/\/files-1306719578.cos.ap-guangzhou.myqcloud.com\/662b11f6083ce.jpg","user_id":1000004686,"crm_shiptask_id":1714017673158418546}}
        $params = $args["params"];

        # 判断用户是否存在,是内部用户才需要同步  crm_files_id是外部运输任务ID
        $user = MissuUser::getInstance()->getUserInfo($params["user_id"]);
        if (!$user){
            //加个日志
            return true;
        }

        #添加到gd_files表和gd_attachments
        $fileParm = [
            "id"=> \App\Services\Files::getInstance()->get_unique_id(),
            "user_id"=>$params["user_id"],
            "disk"=>$params["disk"],
            "path"=>$params["path"],
            "name"=>$params["name"],
            "status"=>1,
            "size"=>0,
            "crm_files_id"=>$params["crm_files_id"],
        ];

        $so = \App\Services\M\ShipTask::getInstance()->getIdByCrmShipTaskId($params["crm_shiptask_id"]);

        $type = \App\Ok\FileType::getType($params["path"]);
        $attachParam = [
            "id"=> \App\Services\Files::getInstance()->get_unique_id(),
            "user_id"=>$params["user_id"],
            "file_id"=>$fileParm["id"],
            "correlate_type"=>4,
            "correlate_id"=>isset($so->Shiptask_id)?$so->Shiptask_id:0,  //有可能为0，先存着，再补这个ID
            "type"=>$type, //
            "status"=>1,
            "flag"=>1,
            "crm_files_id"=>$params["crm_files_id"],
            "crm_shiptask_id"=>$params["crm_shiptask_id"],
        ];
        $res = \App\Services\Attachments::getInstance()->AttachmentSync($fileParm,$attachParam);

        Log::channel('sync')->info("Rabbit [addAttach] res={$res}");
        return $res;
    }

    //附件同步：删除附件
    public function removeAttach(array $args):bool|int
    {
        //{"method":"removeAttachment","params":{"crm_files_id":1714102506768566757}}
        $params = $args["params"];

/*        # 判断用户是否存在,是内部用户才需要同步  crm_files_id是外部运输任务ID
        $user = MissuUser::getInstance()->getUserInfo($params["user_id"]);
        if (!$user){
            //加个日志
            return true;
        }*/

        $res = \App\Services\Attachments::getInstance()->removeAttachment(["crm_files_id"=>$params["crm_files_id"]]);
        Log::channel('sync')->info("Rabbit [removeAttach] res={$res}");
        return $res;
    }
}
