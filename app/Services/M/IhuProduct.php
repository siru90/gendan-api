<?php
namespace App\Services\M;
use Illuminate\Support\Facades\DB;

class IhuProduct extends \App\Services\BaseService
{
    //内部系统
    protected ?string $connection = 'mysql_master';

    protected string $table = 'ihu_product';

    public function getModel(array $param):array|null
    {
        $page = $size = 0;$model = "";
        extract($param);

        $offset = max(0, ($page - 1) * $size);
        $tb = $this->tb->from("ihu_product as p")
            ->select([
                "p.product_id",
                "p.model",
                "p.brand_id",
                "b.brand_name",
            ]);
        if(!empty($model)){
            $tb = $tb->where("model", "like", "$model%");
        }
        $tb = $tb->where("p.Enable","0")->leftjoin("brand as b", "b.brand_id","p.brand_id");

/*        $sql = str_replace('?','%s', $tb->toSql());
        $sql = sprintf($sql, ...$tb->getBindings());
        dump($sql);*/
        return $tb->offset($offset)->limit($size)->get()->toArray();
    }

    public function getById($id):object|null
    {
        return $this->tb->where("product_id",$id)->first();
    }

    public function getModelByProductId(int $productId):object|null
    {
        return $this->tb->from("ihu_product as p")
            ->select('product_id', 'belong_to_product_id', 'model')
            ->where("product_id", $productId)->first();
    }

    public function getByModel($model):array
    {
        return $this->tb->select("product_id","model","product_name")
            ->where("model","like","$model%")
            ->where("Enable", 0) # Enable 0正常, 1删除
            ->where("status", 1) # status 0未审核, 1已审核
            ->get()->toArray();
    }

    public function getModelByID(int $productId):object|null
    {
        return $this->tb->from("ihu_product as p")
            ->select([
                "p.product_id",
                "p.model",
                "p.brand_id",
                "b.brand_name",
            ])
            ->leftJoin("brand as b", "b.brand_id","p.brand_id")
            ->where("p.product_id", $productId)->where("p.Enable","0")->first();
    }

    public function returnKeywordsModelIds(array $productIds)
    {
        return $this->tb->select('keywords_model_id')->whereIn("product_id", $productIds)->pluck("keywords_model_id")->toArray();;
    }

    public function isExist($model)
    {
        return $this->tb->where("model",$model)->exists();
    }

    public function getFirstModel($model)
    {
        return $this->tb->where("model",$model)->where("Enable","0")->first();
    }

    //新增
    public function addProduct($model,$productId,$descId):int
    {
        $model = trim($model);
        //$id = $this->get_unique_id();
        //$descId = $this->get_unique_id();

        $pure_model = $this->getPureProductModel($model);

        $proArg = [
            "product_id"=>$productId,
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
            'pure_model'    => $pure_model,
        ];

        $this->db->beginTransaction();
        try {
            #先查询数据库有没有
            $product = $this->tb->select("product_id","status")->where("product_id",$productId)->where("Enable",0)->first();
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
                $affect = \App\Services\M\IhuProductDescription::getInstance()->addProductDesc($desArg);
            }
            else{
                $id = $product->product_id;
                $desc = \App\Services\M\IhuProductDescription::getInstance()->getProductDesc($product->product_id);
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
                    $affect = \App\Services\M\IhuProductDescription::getInstance()->addProductDesc($desArg);
                }
            }
            $this->db->commit();
            return $affect;
        }
        catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
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

    //es返回
    public function ok(array $args): array
    {
        $ids = []; $enable = $status=null;
        $language_id = 1;
        extract($args);

        if (empty($ids)) return [];
        $tb = $this->tb
            ->from('ihu_product', 'p')
            ->select([
                'p.weight',
                'p.market_price',
                'pd.description',
                'pd.name',
                'p.quantity',
                'p.model',
                'p.create_user_id',
                'p.price',
                'p.resell_price',
                'p.product_id',
                'p.image',
                //'p2c.category_id',
                'p.Cost',
                'p.date_added',
                'p.date_modified',
                'p.reviewstatus',
                'p.belong_to_product_id',
            ])
            ->leftJoin('ihu_product_description AS pd', 'p.product_id', '=', 'pd.product_id')
            //->leftJoin('ihu_product_to_category AS p2c', 'p.product_id', '=', 'p2c.product_id')
            //->leftJoin('keywords_model AS k', 'p.keywords_model_id', '=', 'k.keywords_model_id')
            ->whereIn('p.product_id', $ids)
            ->distinct('p.product_id')
            ->orderByRaw('LENGTH(p.model)');
        if (count($belong_to_product_id)) {
            $tb = $tb->whereIn('p.belong_to_product_id', $belong_to_product_id);
        }
        if (strlen($language_id)) {
            $tb = $tb->where('pd.language_id', $language_id);
        }
        if (strlen($status)) {
            $tb = $tb->where('p.status', $status);
        }
        if (strlen($enable)) {
            $tb = $tb->where('p.Enable', $enable);
        }

        /*        $sql = str_replace('?','%s', $tb->toSql());
                $sql = sprintf($sql, ...$tb->getBindings());
                dump($sql);*/

        /*
        select `p`.`weight`, `p`.`market_price`, `pd`.`description`, `pd`.`name`, `p`.`quantity`, `p`.`model`, `p`.`create_user_id`, `p`.`price`, `p`.`resell_price`, `p`.`product_id`, `p`.`image`, `p2c`.`category_id`, `p`.`Cost`, `p`.`date_added`, `p`.`date_modified`, `p`.`reviewstatus`, `p`.`belong_to_product_id`
        from `ihu_product` as `p`
        left join `ihu_product_description` as `pd` on `p`.`product_id` = `pd`.`product_id`
        left join `ihu_product_to_category` as `p2c` on `p`.`product_id` = `p2c`.`product_id`
        left join `keywords_model` as `k` on `p`.`keywords_model_id` = `k`.`keywords_model_id`
        where `p`.`product_id` in (184286, 670, 280484, 333482, 450090)
        and `p`.`belong_to_product_id` in (-1, -2) and `pd`.`language_id` = 1 and `p`.`status` = 1 and `p`.`Enable` = 0 order by LENGTH(p.model)

        */
        return $tb->get()->toArray();
    }


    //外部系统有：更新到内部系统
    public function addProductTwo($args,$descId):int
    {
        $model = trim($args->model);
        $productId = $args->product_id;
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
                $affect=\App\Services\M\IhuProductDescription::getInstance()->addProductDesc($desArg);
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
