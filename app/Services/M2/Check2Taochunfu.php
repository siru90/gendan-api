<?php
namespace App\Services\M2;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class Check2Taochunfu extends \App\Services\BaseService
{
    //外部系统数据库表
    protected ?string $connection = 'mysql_external';

    protected string $table = 'check2_taochunfu';


    public function getShipTask(array $args):array
    {
        return $this->tb->where("table","shiptask")->where("type","insert")->limit($args["size"])->get()->toArray();
    }

    public function delete($check2_id):int|null
    {
        return $this->tb->where("check2_id",$check2_id)->delete();
    }
}
