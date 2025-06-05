<?php

namespace App\Ok\Enum;

enum OrderSourceStatus: int
{
    /** 个人的 */
    case MY = 1;
    /** 分享给我的 */
    case TOMY = 2;
    /** 分享给别人的 */
    case MYSHARE = 3;



    #订单来源：1个人的，2被分享的，3分享给别人的
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::MY, 'name' => '个人的'],
            ['id' => self::TOMY, 'name' => '被分享的'],
            ['id' => self::MYSHARE, 'name' => '分享给别人的'],
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
