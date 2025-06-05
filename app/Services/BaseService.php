<?php

namespace App\Services;

use App\Utils\GetInstances;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Class BaseService
 * @property Connection $db
 * @property Builder $tb
 * @property Builder $mtb
 */
abstract class BaseService
{
    use GetInstances;

    protected ?string $connection = null;
    protected string $table;

    public function __get($key)
    {
        if ($key == 'db') {
            return DB::connection($this->connection);
        } elseif ($key == 'tb') {
            return DB::connection($this->connection)->table($this->table);
        } elseif ($key == 'mtb') {
            return DB::connection($this->connection)->table($this->table)->useWritePdo();
        }
        return null;
    }

    /**
     * 获取全局唯一ID
     * @return int 当前时间戳10位 + 微秒（6位） +3位随机数 = 19位
    */
    public function get_unique_id():int
    {
        $sign=true;
        while($sign){
            list($uSecond,$second) = explode(" ", microtime());
            //$second = $second-700000000;  #为了id能从更小数值开始，减一个固定值
            $str = sprintf("%d%d".rand(100,999), $second,(int)($uSecond*1000000));
            if(strlen($str) === 19){
                $sign=false;
            }
        }
        return (int)$str;
    }
}
