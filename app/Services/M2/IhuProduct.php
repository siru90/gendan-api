<?php
namespace App\Services\M2;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class IhuProduct extends \App\Services\BaseService
{
    //外部系统数据库表
    protected ?string $connection = 'mysql_external';

    protected string $table = 'ihu_product';


    public function getModel():object
    {
        return $this->tb->limit(1)->first();
    }

    public function getById($id):object|null
    {
        return $this->tb->where("product_id",$id)->first();
    }

    public function getByModel($model):array
    {
         return $this->tb->select("product_id","model","product_name")
            ->where("model","like","$model%")
            ->where("Enable", 0) # Enable 0正常, 1删除
            ->where("status", 1) # status 0未审核, 1已审核
            ->get()->toArray();
    }

    public function ok(array $args): array
    {
        $ids = [];
        $status = 1; $enable = 0;
        extract($args);

        if (empty($ids)) return [];
        $tb = $this->tb
            ->from('ihu_product', 'p')
            ->select([
                'p.model',
                'p.product_id',
                'p.Enable',
                'p.status',
                'p.brand_id',
                'b.brand_name',
                'p.date_modified',
                'p.date_added',
                'p.belong_to_product_id',
                'p.product_name',
            ])
            ->leftJoin("brand as b", "b.brand_id","p.brand_id")
            ->whereIn('p.product_id', $ids)
            ->where("p.Enable", 0) # Enable 0正常, 1删除
            ->where("p.status", 1) # status 0未审核, 1已审核
            ->orderByRaw('LENGTH(p.model)');

        return $tb->get()->toArray();
    }



    //新增到外部系统
    public function addProduct($model):array
    {
        $model = trim($model);
        $pure_model = $this->getPureProductModel($model);

        $id = $this->get_unique_id();
        $descId = $this->get_unique_id();

        $proArg = [
            "product_id"=>$id,
            "model"=>$model,
            "quantity"=>0,
            "stock_status_id"=>7,
            "image"=>"",
            "manufacturer_id"=>0,
            "market_price"=>0,
            "price"=>0,
            "resell_price"=>0,
            "weight"=>0,
            "create_user_id"=>1,
            "status"=>1,
            "Enable"=>0,
            "belong_to_product_id"=>"-2",
            "date_added"=>date("Y-m-d H:i:s"),
            "date_modified"=>date("Y-m-d H:i:s"),
            "org_id"=>"1111111",
            "fulltext_name"=>$model.",{$pure_model}",
            "source"=>"Old RFQ",
            'pure_model'    => $pure_model,
        ];

        $this->db->beginTransaction();
        try {
            #先查询数据库有没有
            $product = $this->tb->select("product_id","status")->where("pure_model",$pure_model)->where("Enable",0)->first();
            if(!$product){
                $this->tb->insert($proArg);
                $desArg=[
                    "product_description_id"=>$descId,
                    "product_id"=>$id,
                    "language_id"=>1,
                    "name"=>$model,
                    "description"=>$model,
                    "user_id"=>1,
                ];
                $affect=\App\Services\M2\IhuProductDescription::getInstance()->addProductDesc($desArg);

                #添加产品规格
                \App\Services\M2\IhuProductSpecs::getInstance()->addProductSpecs([
                    'specs_id'   => $this->get_unique_id(),
                    'product_id' => $id,
                    'sku'        => $id+1,
                    'condition'  => 1,
                    'state'      => 1,
                    'production' => 1,
                    'org_id'     => "1111111",
                    'sort'       => 1,
                    'brand_id'   => 0
                ]);
            }
            else{
                $id = $product->product_id;
                $desc = \App\Services\M2\IhuProductDescription::getInstance()->getProductDesc($product->product_id);
                #更新产品状态，添加产品描述
                if($product->status==0){
                    $this->tb->where("product_id",$id)->update(["status"=>1]);
                }
                if(!$desc){
                    $desArg=[
                        "product_description_id"=>$descId,
                        "product_id"=>$id,
                        "language_id"=>1,
                        "name"=>$model,
                        "description"=>$model,
                        "user_id"=>1,
                    ];
                    $affect = \App\Services\M2\IhuProductDescription::getInstance()->addProductDesc($desArg);
                }else{
                    $descId = $desc->product_description_id;
                }
            }
            $this->db->commit();
            return [$id,$descId];
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            Log::channel('search')->info("\n OpenController insert(){$id} e = ". $e);
            return [];
        }
    }


    /**
     * 提取型号中的数字字母
     * @param $model
     * @return string
     */
    function getPureProductModel($model): string
    {
        if(empty($model)) return '';
        preg_match_all('/[0-9A-Za-z+.]+/',$model,$arr);
        $res = implode('',$arr[0]);
        return trim($res);
    }


    //内部系统有：更新到外部系统
    public function addProductTwo($args,$descId):int
    {
        $model = trim($args->model);
        $productId = $args->product_id;
        $pure_model = $this->getPureProductModel($model);

        $proArg = [
            "product_id"=>$productId,
            "model"=>$model,
            "quantity"=>$args->quantity,
            "stock_status_id"=>$args->stock_status_id,
            "image"=>"",
            "manufacturer_id"=>$args->manufacturer_id,
            "market_price"=>$args->market_price,
            "price"=>$args->price,
            "resell_price"=>$args->resell_price,
            "weight"=>$args->weight,
            "create_user_id"=>$args->create_user_id,
            "status"=>$args->status,
            "Enable"=>$args->Enable,
            "belong_to_product_id"=>$args->belong_to_product_id,
            "date_added"=>$args->date_added,
            "date_modified"=>$args->date_modified,
            "org_id"=>"1111111",
            "fulltext_name"=>$model.', '.$pure_model,
            "source"=>"Old RFQ"
        ];

        $this->db->beginTransaction();
        try {
            #先查询数据库有没有
            $product = $this->tb->select("product_id")->where("product_id",$productId)->where("status",1)->first();
            $affect = 0;
            if(!$product){
                $this->tb->insert($proArg);
                $desArg=[
                    "product_description_id"=>$descId,
                    "product_id"=>$productId,
                    "language_id"=>1,
                    "name"=>$model,
                    "description"=>$model,
                    "user_id"=>1,
                ];
                $affect=\App\Services\M2\IhuProductDescription::getInstance()->addProductDesc($desArg);
            }
            $this->db->commit();
            return $affect;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

}
?>
