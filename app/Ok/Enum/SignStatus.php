<?php

namespace App\Ok\Enum;

enum SignStatus: int
{
    /** 未签收 */
    case UNSIGNED = 1;
    /** 已签收 */
    case SIGNED = 2;

    public static function map(): array
    {
        return [
            ['id' => self::UNSIGNED, 'name' => '未签收'],
            ['id' => self::SIGNED, 'name' => '已签收'],
        ];
    }
}
