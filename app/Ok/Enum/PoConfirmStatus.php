<?php

namespace App\Ok\Enum;

enum PoConfirmStatus: int
{
    /** 未提交 */
    case UNCONFIRMED = 0;
    /** 部分验货 */
    case PARTIAL = 1;
    /** 已验货 */
    case CONFIRMED = 2;


    #确认状态：0未确认，1部分确认，2已确认
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::UNCONFIRMED, 'name' => '未确认'],
            ['id' => self::PARTIAL, 'name' => '部分确认'],
            ['id' => self::CONFIRMED, 'name' => '已确认'],
        ];
        if ($id !== null) {
            foreach ($map as $item) {
                if ($item['id']->value == $id) {
                    return $item['name'];
                }
            }
            return null;
        }
        return $map;
    }
}
