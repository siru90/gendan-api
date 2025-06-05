<?php

namespace App\Ok\Enum;

enum CheckStatus: int
{
    /** 缺货 */
    case LACK = 0;
    /** 货齐 */
    case COMPLETE = 1;

    public static function map(): array
    {
        return [
            ['id' => self::LACK, 'name' => '缺货'],
            ['id' => self::COMPLETE, 'name' => '货齐'],
        ];
    }
}
