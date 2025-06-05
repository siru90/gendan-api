<?php

namespace App\Services\M;

class Country extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'country';

    public function getCountries(): array
    {
        return $this->tb->select('country_id', 'name', 'iso_code_2', 'iso_code_3')
            ->where('status', 1)->orderBy('sort')->get()->toArray();
    }

    public function getNameById($countryId)
    {
        return $this->tb->where('country_id', $countryId)->value('name');
    }
}
