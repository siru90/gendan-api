<?php
namespace App\Services\M;
use Illuminate\Support\Facades\DB;

class IhuProductSpecs extends \App\Services\BaseService
{
    protected ?string $connection = 'mysql_master';

    protected string $table = 'ihu_product_specs';


    public function getById(int $specsId): object|null
    {
        return $this->tb->where('specs_id', $specsId)->first();
    }

    public function getByIdF1($specsIds):array|null
    {
        return $this->tb->whereIn("specs_id",$specsIds)->get()->toArray();
    }

    public function getByProductId(int $productId):array|null
    {
        /*
            condition 货况
            1=QB全新保内(New Sealed Under Guarantee),
            2=QG全新过保(New Sealed Beyond Guarantee),
            3=QK全新开封保内(New Unsealed Under Guarantee),
            4=QKG全新开封过保(New Unsealed Beyond Guarantee),
            5=F翻新(Refurbish),
            6=E二手(Used),
            7=J兼容(Substitute),
            8=G高仿无牌(High Copy Blank Brand),
            9=GD高仿带牌(High Copy With Brand),
            10=S升级(Upgrade),
            11=W无货(Discontinued)
        */
        $res = \Illuminate\Support\Facades\Redis::command("get", ["gd_ihuproductspecs_".$productId]);
        $res = json_decode($res);
        if(empty($data)){
            $res = $this->tb->select([
                "specs_id",
                "product_id",
                "specs_name",
                "condition",
                "sku",
            ])->where("product_id",$productId)->where("condition",1)->get()->toArray();

            if(!$res){
                $data = $this->DefaultSpecs($productId);
                $res = [$data];
            }
            \Illuminate\Support\Facades\Redis::command("set", ["gd_ihuproductspecs_".$productId, json_encode($res), ['EX' => 3600 * 24]]);
        }
        return $res;
    }

    public function DefaultSpecs($productId)
    {
        # 如果没有数据就生成默认一条，单规格，全新的sku：100000000000 +$product_id 是相加，再拼接货况号01
        $data = new \stdClass();
        $data->specs_id = 0;
        $data->product_id = $productId;
        $data->specs_name = "";
        $data->condition = 1;
        $data->sku = (100000000000 + $productId ) ."01";
        return $data;
    }





}
?>
