<?php

namespace App\Services\M;
use Illuminate\Support\Facades\DB;

class ShipTaskPackage extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'shiptask_package';

    public function getPacks(int $shipTaskId): array
    {
        return $this->tb->where('shiptask_id', $shipTaskId)->where("delete",0)->orderBy("id","asc")->get()->toArray();
    }

    public function getPackById(int $id): ?object
    {
        return $this->tb->where('id', $id)->where("delete",0)->first();
    }

    public function addPack(array $args): int
    {
        $args["id"] = $this->get_unique_id();
        $this->tb->insert($args);
        return $args["id"];
    }

    public function rmPack(int $id): int
    {
        //return $this->tb->where('id', $id)->delete();
        return $this->tb->where('id', $id)->update([
            "delete"=>1,
        ]);
    }

    public function updatePack(int $id, array $values):int
    {
        if($values["package_id"]) unset($values["package_id"]);
        return $this->tb->where('id', $id)->where("delete",0)->update($values);
    }

    //填充已打包数量
    public function fillUserQuantity($shiptask_id,$objs, $from, $to = 'useQuantity')
    {

        $userMap = [];
        $package = $this->tb->select("shiptask_item_id")->where("shiptask_id",$shiptask_id)->where("delete",0)->get()->toArray();
        foreach ($package as $val){
            $val->shiptask_item_id = json_decode($val->shiptask_item_id);
            if(empty($val->shiptask_item_id)) continue;
            foreach ($val->shiptask_item_id as $k){
                $userMap[$k->id] = isset($userMap[$k->id])? $userMap[$k->id]: 0;
                $userMap[$k->id] = $userMap[$k->id] + $k->num;
            }
        }

        foreach ($objs as $obj){
            if (isset($userMap[$obj->$from])) {
                $obj->$to = $userMap[$obj->$from];
            }
            else {
                $obj->$to = 0;
            }
        }
    }
}
