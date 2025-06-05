<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SyncCrm extends BaseService
{
    protected string $table = 'gd_sync_crm';

    //添加
    public function addSyncContent(array $args):int|null
    {
        $args["sync_id"] = $this->get_unique_id();
        $affect = $this->tb->insert($args);

        return $affect;

        //Log::channel('sync')->info("Rabbit [addSyncContent] MESSAGE result: {$affect}");
    }

    public function updateSyncContent($id,$args)
    {
        return $this->tb->where("sync_id",$id)->update($args);
    }


    public function getsyncRes():array|null
    {
        return $this->tb->select("sync_id","sync_content")->where("sync_res",0)->get()->toArray();
    }





}
