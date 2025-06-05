<?php

namespace App\Services\M2;

use Illuminate\Support\Facades\DB;
use \App\Services\SoStatus;
use \App\Services\ExpressOrders;

class ShipTaskItem extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_external';

    protected string $table = 'shiotask_item';

    public function getItems(int $id): array
    {
        return $this->tb->where('Shiptask_id', $id)->get()->toArray();
    }

}
