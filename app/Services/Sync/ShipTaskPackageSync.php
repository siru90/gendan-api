<?php

namespace App\Services\Sync;

use \App\Services\M\Orders;
use \App\Services\M\OrdersItemInfo;
use \App\Services\M\CustomerInfo;
use \App\Services\M\ShipTask;
use \App\Services\Oplog;
use Illuminate\Support\Facades\Log;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;



class ShipTaskPackageSync extends \App\Services\BaseService
{
    //同步数据,保存到数据库
    public function saveShipTask(array $args):bool|int
    {
        if(!isset($args["shiptask"]) || !isset($args["shiotask_item"]))
            return true;

        return true;
    }
}
