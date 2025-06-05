<?php

namespace App\Services\M;

class UserGroup extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'user_group';

    public function getGroupInfo($id): object|null
    {
        return $this->tb->where('user_group_id', $id)->first();
    }
}
