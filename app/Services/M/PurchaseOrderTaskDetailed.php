<?php

namespace App\Services\M;

class PurchaseOrderTaskDetailed extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'purchaseordertesk_detailed';

    public function updateTaskDetail(int $orderInfoId,$values): int
    {
        return $this->tb->where('order_info_id', $orderInfoId)->update($values);
    }

    public function getByIdAS($id): object|null
    {
        return $this->tb->where('Purchaseordertesk_detailed_id', $id)->first();
    }

    //根据采购单ID，返回所有明细ID
    public function returnTaskIds($id):array
    {
        return $this->tb->select('Purchaseordertesk_detailed_id')->where('order_info_id', $id)->pluck("Purchaseordertesk_detailed_id")->toArray();
    }

    public function getItem($args): array
    {
        $tb = $this->tb->select([
            "Purchaseordertesk_id",
            "Purchaseordertesk_detailed_id",
            "order_info_id",
            "State",
        ]);
        if($args["order_info_id"]){
            $tb = $tb->where('order_info_id', $args["order_info_id"]);
        }
        if($args["purchaseordertesk_id"]){
            $tb = $tb->where('Purchaseordertesk_id', $args["purchaseordertesk_id"]);
        }
        if($args["state"]){
            $tb = $tb->whereIn('State', $args["state"]);
        }

        return $tb->get()->toArray();
    }

    public function getByOiIdF1($orderInfoId): array
    {
        return $this->tb
            ->select('Purchaseordertesk_detailed_id')
            ->where('order_info_id', $orderInfoId)->get()->toArray();
    }



}
