<?php

namespace App\Ok\Enum;

enum PoSubmitStatus: int
{
    /** 未提交 */
    case UNSUBMITTED = 0;
    /** 已提交 */
    case SUBMITTED = 1;


    #提交状态：0未提交，1已提交
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::UNSUBMITTED, 'name' => '待收'],
            ['id' => self::SUBMITTED, 'name' => '妥收'],
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
