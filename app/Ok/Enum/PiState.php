<?php
namespace App\Ok\Enum;

enum PiState: int
{
    /** 未分配订货 */
    case TOBEGIN = 1;
    /** 进行中 */
    case AFOOT = 2;
    /** 进行中-待分配订货 */
    case TOBERECEIVED = 3;
    /** 进行中-待核对到货 */
    case TOBEVERIFIED = 4;
    /** 进行中-待分配发货 */
    case TOBEALLOCATED = 5;
    /** 进行中-待总务发货 */
    case ALLOCATED = 6;
    /** 进行中-待物流跟单 */
    case TRACKING = 7;
    /** 已完成 */
    case COMPLETED = 8;


    #1未分配订货，2预定货，3待收货，4待分配发货，5部分分配发货，6已分配发货，7已签收，8部分订货，9部分到货、10全部到货
    public static function map(?int $id = null): string|array|null
    {
        $map = [
            ['id' => self::TOBEGIN, 'name' => '待开始'],  # 1未分配订货
            ['id' => self::AFOOT, 'name' => '进行中'],
            ['id' => self::TOBERECEIVED, 'name' => '待分配订货'],  #2预定货，3待收货
            ['id' => self::TOBEVERIFIED, 'name' => '待核对到货'],  #9部分到货，10全部到货
            ['id' => self::TOBEALLOCATED, 'name' => '待分配发货'], #4待分配发货，5部分分配发货
            ['id' => self::ALLOCATED, 'name' => '待总务发货'],  #6已分配发货
            ['id' => self::TRACKING, 'name' => '待物流跟单'], #PI的部分SO已发货
            ['id' => self::COMPLETED, 'name' => '已完成'], #PI的全部SO已发货
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
