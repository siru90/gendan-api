<?php

namespace App\Ok\Enum;

enum SerialType: int
{
    /** 单一序列号 */
    case SIGNED = 1;
    /** 共用序列号 */
    case SHARE = 2;
    /** 无序列号 */
    case NO = 3;

    public static function map(): array
    {
        return [
            ['id' => self::SIGNED, 'name' => '单一序列号'],
            ['id' => self::SHARE, 'name' => '共用序列号'],
            ['id' => self::NO, 'name' => '无序列号'],
        ];
    }
}
