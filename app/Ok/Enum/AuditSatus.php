<?php

namespace App\Ok\Enum;

enum AuditSatus: int
{
    /** 待审核 */
//    case AUDIT = 0;
    /** 审核同意 */
    case AGREE = 2;
    /** 整单驳回 */
    case REJECTION = 3;
    /** 等待货齐 */
    case WAITEING = 4;
    /** 部分先发货 */
    case PARTSEND = 5;

    public static function map(): array
    {
        //审核状态：0待审核,1无需审核,2审核同意,3整单驳回,4等待货齐,5部分先发货
        //无需审核：是没有异常自动无需审核
        return [
//            ['id' => self::AUDIT, 'name' => '待审核'],
            ['id' => self::AGREE, 'name' => '审核同意'],
            ['id' => self::REJECTION, 'name' => '整单驳回'],
            ['id' => self::WAITEING, 'name' => '等待货齐'],
            ['id' => self::PARTSEND, 'name' => '部分先发货'],
        ];
    }
}
