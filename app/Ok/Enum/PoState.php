<?php
namespace App\Ok\Enum;

enum PoState: int
{

    /** 待付款 */
    case UNPAYMENT = 1;
    /** 已付款 */
    case PAYMENT = 2;
    /** 到货中 */
    case INTRANSIT = 3;
    /** 采购完毕 */
    case COMPLETE = 4;
    /** 部分付款 */
    case PARTIAL = 6;
    /** 草稿 */
    //case DRAFT = 9;

    #1待付款  2已付款 3 到货中 4采购完毕, 6部分付款, 9草稿
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::UNPAYMENT, 'name' => '代付款'],
            ['id' => self::PAYMENT, 'name' => '已付款'],
            ['id' => self::INTRANSIT, 'name' => '到货中'],
            ['id' => self::COMPLETE, 'name' => '采购完毕'],
            ['id' => self::PARTIAL, 'name' => '部分付款'],
            //['id' => self::DRAFT, 'name' => '草稿'],
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
