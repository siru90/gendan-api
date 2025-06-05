<?php

namespace App\Services\M2;

class IhuProductSpecs extends \App\Services\BaseService
{
    #外部系统表
    protected ?string $connection = 'mysql_external';

    protected string $table = 'ihu_product_specs';

    public function addProductSpecs(array $args):int
    {
        return $this->tb->insert($args);
    }
}
