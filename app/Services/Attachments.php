<?php
namespace App\Services;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class Attachments extends BaseService
{

    protected string $table = 'gd_attachments';

    public function addAttachment($userId, $fileId, $args = []): int
    {
        $values = [
            'id' => $this->get_unique_id(),
            'user_id' => $userId,
            'file_id' => $fileId,
            'status' => 1,
        ];
        $values = array_merge($values, $args);
        $this->tb->insert($values);
        return $values["id"];
    }

    public function getById(int $id): object|null
    {
        if (!$id) return null;
        return $this->tb->where('id', $id)->where('status', 1)->first();
    }

    public function getAttachmentByIds(array $ids): array
    {
        $list = $this->mtb
            ->select('id', 'correlate_id','correlate_type','file_id', 'user_id', 'type','flag')
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->get()->toArray();
        return $this->fill($list);
    }

    //判断是否存在异常图片
    public function getExceptional($correlate_id)
    {
        //`flag` COMMENT '标记: 1正常,0异常',
        return $this->tb->where('correlate_id', $correlate_id)->where("flag",0)->where('status', 1)->exists();
    }


    public function getAttachments($id,$type): array
    {
        $list = $this->mtb
            ->select('id','correlate_id','correlate_type', 'file_id', 'user_id', 'type','flag')
            ->where('correlate_id', $id)
            ->where("correlate_type",$type) //关联的类型:1快递的图片; 2快递产品图片 3采购详单; 4发货单; 5发货详单,6采购单,7销售订单图片，8销售详单图片
            ->where('status', 1)
            ->orderBy("flag") //标记: 1正常,0异常
            ->get()->toArray();
        return $this->fill($list);
    }

    //获取采购详单附件
    public function getAttachmentsByPodId(array $podIds,?int $flag=null): array
    {
        if (!$podIds) return [];
        $tb = $this->tb->select('id','correlate_id','correlate_type', 'file_id', 'user_id', 'type','flag');
        if(isset($flag)){
            $tb->where('flag', $flag);
        }
        $tb = $tb->whereIn('correlate_id', $podIds)
            ->where("correlate_type",3)
            ->where('status', 1)
            ->orderBy("flag","asc");  //标记: 1正常,0异常

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->get()->toArray();
        return $this->fill($list);
    }

    //获取销售详单附件
    public function getAttachByInfoId(array $orderInfoIds,?int $flag=null): array
    {
        if (!$orderInfoIds) return [];
        $tb = $this->tb->select('id','correlate_id','correlate_type', 'file_id', 'user_id', 'type','flag','pod_id');
        if(isset($flag)){
            $tb->where('flag', $flag); //标记: 1正常,0异常
        }
        $tb = $tb->whereIn('correlate_id', $orderInfoIds)
            ->where("correlate_type",8)
            ->where('status', 1)
            ->orderBy("correlate_id","asc");

        /*$sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/

        $list = $tb->get()->toArray();
        return $this->fill($list);
    }


    //获取发货详单附件
    public function getAttachmentsBySOId(array $soItemIds): array
    {
        if (!$soItemIds) return [];
        $tb = $this->tb->select('id','correlate_id','correlate_type', 'file_id', 'user_id', 'type','flag')
            ->whereIn('correlate_id', $soItemIds)
            ->where("correlate_type",5)
            ->where('status', 1);  //标记: 1正常,0异常
        $list = $tb->get()->toArray();
        return $this->fill($list);
    }

    //获取打包图片
    public function getSoAttachments($so_id): array
    {
        $list = $this->mtb
            ->select('id', 'file_id', 'user_id', 'type','flag')
            ->where('correlate_id', $so_id)
            //->where('so_id', $so_id)
            ->where('status', 1)
            ->get()->toArray();
        return $this->fill($list);
    }

//----------


    private function fill(array $list): array
    {
        foreach ($list as $item) {
            $fileInfo = Files::getInstance()->getById($item->file_id);
            $item->disk = $fileInfo->disk;
            if($fileInfo->disk == "pictures"){
                $item->url = sprintf("/api/tracking_order/show?id=%s", $item->file_id);
            }
            if($fileInfo->disk == "tencent"){
                $item->path = $fileInfo->path;
            }
            if ($fileInfo && $fileInfo->thumbnail_id) {
                $item->thumbnail = sprintf("/api/tracking_order/show?id=%s", $fileInfo->thumbnail_id);
            }
        }
        //MissuUser::getInstance()->fillUsers($list, 'user_id');
        return $list;
    }


    public function removeAttachment($args): int
    {
        $id = $crm_files_id = null;
        extract($args);
        $tb = $this->tb->where('status', 1);
        if(!empty($id)){
            $tb = $tb->where('id', $id);
        }
        if(!empty($crm_files_id)){
            $tb = $tb->where('crm_files_id', $crm_files_id);
        }
        $isExist = $tb->exists();
        if(!$isExist) return true;

        return $tb->update(['status' => -1]);
        //return $this->tb->where('id', $id)->where('status', 1)->delete();
    }



    public function updateByIds(array $ids, array $values): int
    {
        return $this->tb->whereIn('id', $ids)->where('status', 1)->update($values);
    }

    //数据同步:打包图片
    public function AttachmentSync(array $fileParm,$attachParam):int|bool
    {
        $this->db->beginTransaction();
        try {
            \Illuminate\Support\Facades\DB::table("gd_files")->insert($fileParm);
            $res = $this->tb->insert($attachParam);
            $this->db->commit();
            return $res;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    //数据同步：获取同步过来的发货单图片，correlate_id为0的
    public function getSoIdZero($args)
    {
        $tb = $this->tb->select("id","crm_shiptask_id","correlate_id")->where("status",1)->where("crm_shiptask_id","!=",0);
        if(isset($args["correlate_id"])){
            $tb = $tb->where("correlate_id",$args["correlate_id"]);
        }
        return $tb->get()->toArray();
    }

    public function returnIds(array $args):array|null
    {
        $tb = $this->tb->select($args["return_id"]);
        if(!empty($args["correlate_id"])){
            $tb = $tb->where("correlate_id",$args["correlate_id"]);
        }
        if(!empty($args["correlate_type"])){
            $tb = $tb->where("correlate_type",$args["correlate_type"]);
        }

        return $tb->where("status",1)->distinct()->pluck($args["return_id"])->toArray();
    }

    public function batchDelete(array $ids,$correlate_id,$correlate_type):int
    {
        return $this->tb->whereIn('id', $ids)->where("correlate_id",$correlate_id)->where("correlate_type",$correlate_type)->where('status', 1)->update([
            'status' => -1,
        ]);
    }

    public function updateByFlag(int $file_id, int $correlate_id, int $correlate_type, int $flag):int
    {
        return $this->tb->where('file_id', $file_id)->where("correlate_id",$correlate_id)->where("correlate_type",$correlate_type)->where('status', 1)->update([
            'flag' => $flag,
        ]);
    }
}
