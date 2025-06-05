<?php

namespace App\Ok\Enum;

enum CheckInspectStatus: int
{
    /** 未提交 */
    case UNINSPECTED = 0;
    /** 部分验货 */
    case PARTIAL = 1;
    /** 已验货 */
    case INSPECTED = 2;


    #验货状态：0未验货，1部分验货，2已验货
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::UNINSPECTED, 'name' => '未验货'],
            ['id' => self::PARTIAL, 'name' => '部分验货'],
            ['id' => self::INSPECTED, 'name' => '已验货'],
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
