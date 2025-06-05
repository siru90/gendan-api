<?php

namespace App\Ok\Enum;

enum CheckConfirmed: int
{
    /** 未确认 */
    case unconfirmed = 0;
    /** 部分确认 */
    case partConfirmed = 1;
    /** 已确认 */
    case confirmed = 2;

    //0未确认，1部分确认，2已确认
    public static function map(): array
    {
        return [
            ['id' => self::unconfirmed, 'name' => '未确认'],
            ['id' => self::partConfirmed, 'name' => '部分确认'],
            ['id' => self::confirmed, 'name' => '已确认'],
        ];
    }
}
