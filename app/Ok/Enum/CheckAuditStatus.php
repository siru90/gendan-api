<?php

namespace App\Ok\Enum;

enum CheckAuditStatus: int
{
    /** 待审核 */
    case PENDING = 0;
    /** 已审核 */
    case NONEED = 1;
    /** 审核同意 */
    //case AGREE = 2;
    /** 整单驳回 */
    //case WHOLE = 3;
    /** 部分驳回 */
    //case PARTIAL = 4;


    #审核状态：0待审核,1无需审核,2审核同意,3整单驳回,4等待货齐,5部分先发货
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::PENDING, 'name' => '待审核'],
            ['id' => self::NONEED, 'name' => '已审核'],
            //['id' => self::AGREE, 'name' => '审核同意'],
            //['id' => self::WHOLE, 'name' => '整单驳回'],
            //['id' => self::PARTIAL, 'name' => '部分驳回'],
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
