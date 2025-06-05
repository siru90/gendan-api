<?php

namespace App\Services\M;

class CustomerInfo extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'customer_info';

    public function getCountryIdById($id)
    {
        return $this->tb->select('country_id')->where('customer_info_id', $id)->value('country_id');
    }


    public function fillCountry($objs, $from,): void
    {
        $ids = [];
        foreach ($objs as $obj) {
            $ids[] = $obj->$from;
        }
        if (!count($ids)) return;
        $ids = array_values(array_unique($ids));

        $tb = $this->tb->select(['customer_info.customer_info_id', 'country.country_id', 'country.name as country_name'])
            ->join("country", "country.country_id", "customer_info.country_id")
            ->whereIn('customer_info.customer_info_id', $ids)->distinct();
        $country = $tb->get()->toArray();

        foreach ($country as $item) {
            $countyMap[$item->customer_info_id][] = $item;
        }
        foreach ($objs as $obj) {
            $obj->country_id = $obj->country_name = [];
            if (isset($countyMap[$obj->$from])) {
                foreach ($countyMap[$obj->$from] as $item){
                    $obj->country_id[] = $item->country_id;
                    $obj->country_name[] = $item->country_name;
                }
            }
        }
    }

    public function getCustomerInfoByCountryId($country_id, $size=100)
    {
        return $this->tb->select('customer_info_id')
            ->where('country_id', $country_id)
            ->orderBy("customer_info_id","desc")
            ->limit($size)
            ->get()->toArray();
    }

    public function getInfoByCrmCustomerInfoId($id):object|null
    {
        return $this->tb->select("customer_info_id")->where("crm_customer_info_id",$id)->first();
    }
}
