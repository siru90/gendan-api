<?php
namespace App\Ok\Enum;

enum SoState: int
{

    /** 待发货 */
    case TOBESHIP = 1;
    /** 已发货 */
    case SHIPPED = 2;
    /** 到货中 */
    case INTRANSIT = 3;
    /** 到货完毕 */
    case COMPLETE = 4;

    #发货状态  1待发货  2已发货  3 到货中  4到货完毕
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::TOBESHIP, 'name' => '待发货'],
            ['id' => self::SHIPPED, 'name' => '已发货'],
            ['id' => self::INTRANSIT, 'name' => '到货中'],
            ['id' => self::COMPLETE, 'name' => '到货完毕'],
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
