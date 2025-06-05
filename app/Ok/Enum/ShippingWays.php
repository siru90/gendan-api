<?php

namespace App\Ok\Enum;

enum ShippingWays: int
{

    public static function map(): array
    {
        return [
            ['name' => '国内快递'],
            ['name' => '自提'],
            ['name' => '指定代理'],
            ['name' => 'SHIP'],
            ['name' => 'AIR'],
            ['name' => '俄罗斯双清关'],
            ['name' => 'EMS'],
            ['name' => 'TNT'],
            ['name' => 'FEDEX'],
            ['name' => 'UPS'],
            ['name' => 'DHL加急'],
            ['name' => 'DHL特价'],
            ['name' => '空运'],
            ['name' => '保险'],
            ['name' => '海运'],
            ['name' => '专线'],
            ['name' => '京东'],
            ['name' => '顺丰'],
            //'国内快递','自提','指定代理','SHIP','AIR','俄罗斯双清关','EMS','TNT','UPS','DHL加急','DHL','FEDEX','DHL特价','空运','保险','海运','专线','京东','顺丰'
        ];
    }
}
