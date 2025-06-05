<?php

namespace App\Services\M;

class IhuProductDescription extends \App\Services\BaseService
{
    #内部系统表
    protected ?string $connection = 'mysql_master';

    protected string $table = 'ihu_product_description';

    public function addProductDesc(array $args):int|bool
    {
        return $this->tb->insert($args);
    }

    public function getByProductId($productId):object|null
    {
        return $this->tb->where("product_id",$productId)->first();
    }

    public function getProductDesc($productId):object|null
    {
        return $this->tb->select("product_id","product_description_id")->where("product_id",$productId)->first();
    }

}
