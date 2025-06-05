<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Products extends BaseService
{

    protected string $table = 'gd_products';

    //添加型号
    public function addModel(array $values):int
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $values['status'] = 1;
        $this->tb->insert($values);
        return $id;
    }

    public function getChannel($id): object|null
    {
        return $this->tb->select('id', 'name')->where('id', $id)->where('status', 1)->first();
    }

    public function getProductsPage(array $args): array
    {
        $page = $size = $express_id = 0;
        $model = null;
        extract($args);
        $offset = max(0, ($page - 1) * $size);

        $tb = $this->tb->where('status', 1)->where('express_id', $express_id);
        if(!empty($model)){
            $tb = $tb->where("model","like","%{$model}%");
        }

        $totalTb = clone $tb;
        $list = $tb->offset($offset)->limit($size)->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    public function getProduct($id): object|null
    {
        return $this->tb->select(
            'id',
            'user_id',
            'express_id',
            'model',
            'actual_model',
            'quantity',
            'actual_quantity',
            'ihu_product_id',
            'note',
            'purchase_note',
            "abnormal_status",
            "purchaser_id",
            "is_confirmed"
        )->where('id', $id)->where('status', 1)->first();
    }

    public function getProducts(int $express_id,string $model=""): array
    {
        $tb = $this->tb
            ->select([
                'id',
                'user_id',
                'express_id',
                'model',
                'note',
                'quantity',
                'actual_quantity',
                'actual_model',
                'ihu_product_id',
                'submit_status',
                'is_confirmed',
                'abnormal_status',
            ])
            ->where('status', 1)
            ->where('express_id', $express_id);

        if(!empty($model)){
            $tb = $tb->where("model","like","{$model}%");
        }
        return $tb->get()->toArray();
    }

    public function getProductAndBrand($id): object|null
    {
        return $this->tb->from("gd_products as p")->select(
            'p.id',
            'p.model',
            'p.ihu_product_id',
            'i.brand_id',
            'b.brand_name'
        )
            ->join("ihu_product as i", "i.product_id", "p.ihu_product_id")
            ->leftjoin("brand as b", "b.brand_id", "i.brand_id")
            ->where('p.id', $id)->where('p.status', 1)
            ->first();
    }

    public function incrementModel(int $id, int $amount = 1): int
    {
        return $this->tb->where('id', $id)->increment('quantity', $amount);
    }

    // 创建型号,
    public function createModel(array $values): int
    {
        $arr = $values["serial_number_list"];
        unset($values["serial_number_list"]);
        $this->db->beginTransaction();
        try {
            $id = $this->addModel($values);

            foreach ($arr as $obj){
                $param = [
                    "user_id"=>$values['user_id'],
                    "express_id"=>$values["express_id"],
                    "quantity"=> $obj["quantity"],
                    "product_id"=>$id,
                    "serial_number"=>trim($obj["serial_number"]),
                    "note"=>htmlspecialchars($obj["note"]),
                ];
                $serid = SerialNumbers::getInstance()->addSerialNumber($param);
            }

            //更新
            $this->db->commit();
            return $id;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function updateModel(int $id, array $values): int
    {
        if(isset($values["product_id"])) unset($values["product_id"]);
        return $this->tb->where('id', $id)->update($values);
    }


    public function updateModelByExpress(int $expressID, array $values)
    {
        return $this->tb->where('express_id', $expressID)->where("status",1)->update($values);
    }


    public function deleteModel(int $id): int
    {
        return $this->tb->where('id', $id)->where('status', 1)->update([
            'status' => -1,
        ]);
        //return $this->tb->where('id', $id)->where('status', 1)->delete();
    }

    public function batchDelete(array $ids):int
    {
        return $this->tb->whereIn('id', $ids)->where('status', 1)->update([
            'status' => -1,
        ]);
    }

    public function getExpProduct(int $express_id, string $model): object|null
    {
        return $this->tb
            ->where('express_id', $express_id)
            ->where('model', $model)
            ->where('status', 1)
            ->first();
    }

    public function isExitsByExpressId(int $express_id)
    {
        return $this->tb
            ->where('express_id', $express_id)
            ->where('status', 1)
            ->exists();
    }

    //获取没有异常|有异常，且没有提交序列号的产品
    public function getProductNotConfirm(int $express_id):array|null
    {
        return $this->tb->where('express_id', $express_id)->where("status",1)->where("is_confirmed",0)
            ->get()->toArray();
    }

    public function returnProductIds(int $express_id):array|null
    {
        return $this->tb->select("id")->where("express_id",$express_id)->where("status",1)->pluck("id")->toArray();
    }

    //无法同步PI的产品，变为记录状态
    public function turnRecodeStatus(int $express_id, array $productIds)
    {
        if(empty($productIds)) return true;

        $this->db->beginTransaction();
        try {
            #批量更新
            $affect = $this->tb->whereIn("id",$productIds)->update(["abnormal_status"=>4,"is_confirmed"=>1]);
            #更新快递状态
            ExpressDelivery::getInstance()->updateExpressDelivery($express_id, ["abnormal_status"=>4]);
            $this->db->commit();
            return $affect;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }
}
