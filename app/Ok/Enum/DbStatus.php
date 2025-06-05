<?php

namespace App\Ok\Enum;

enum DbStatus: int
{
    /** 待打包 */
    case UNPACK = 0;
    /** 打包中 */
    case PACKING = 1;
    /** 打包完毕 */
    case COMPLETE = 2;
    /** 装箱完成 */
    case ALL = 3;

    public static function map(): array
    {
        return [
            ['id' => self::UNPACK, 'name' => '打包中'],
            ['id' => self::PACKING, 'name' => '打包中'],
            ['id' => self::COMPLETE, 'name' => '打包完毕'],
            ['id' => self::ALL, 'name' => '装箱完成'],
        ];
    }
}
