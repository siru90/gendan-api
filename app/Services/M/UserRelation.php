<?php

namespace App\Services\M;

class UserRelation extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'user_relation';

    //递归查下级数据
    public function getSubordinates($extId, &$subordinateUserIds = []): array
    {
        $ids = [$extId];
        $list = $this->tb->select('follower_user_id as ext_user_id')->where('leader_user_id', $extId)->get()->toArray();
        foreach ($list as $item) {
            $ids[] = $item->ext_user_id;
/*
            $subordinateUserIds[] =
            $item->user_id = $item->ext_user_id;
            $item->user = User::getInstance()->getUserInfo($item->user_id);
            $item->name = $item->user->name ?? '';
            [$item->subordinates] = $this->getSubordinates($item->ext_user_id, $subordinateUserIds);
            */
            unset($item->ext_user_id);
        }
        return [$ids,$list, $subordinateUserIds];
    }

    //递归查上级数据
    public function getSupordinates($extId, &$subordinateUserIds = []): array
    {
        $ids = [$extId];
        $list = $this->tb->select('leader_user_id as ext_user_id')->where('follower_user_id', $extId)->get()->toArray();
        foreach ($list as $item) {
            $ids[] = $item->ext_user_id;
            unset($item->ext_user_id);
        }
        return $ids;
    }


    public function checkUser(int $userId, int $otherUserId): ?bool
    {
        if (!$userId || !$otherUserId) return null;
        $extUserId = $userId;
        $otherExtUserId = $otherUserId;
        $checkUserId = $otherExtUserId;
        while (true) {
            if ($checkUserId == $extUserId) return true;
            $checkUserId = $this->getSuperior($checkUserId);
            if (!$checkUserId) break;
        }
        return false;
    }

    public function getSuperior($userId)
    {
        $ids = [];
        $res = $this->tb->where('follower_user_id', $userId)->get()->toArray();
        foreach ($res as $obj){
            $ids[] = $obj->leader_user_id;
        }
        return $ids;
    }

    public function getSuperiors($userId): array
    {
        $tUserId = $userId;
        $result = [];
        while (true) {
            $id = $this->tb->where('follower_user_id', $tUserId)->value('leader_user_id');
            if ($id) {
                $result[] = $id;
                $tUserId = $id;
            } else {
                break;
            }
        }
        return $result;
    }

}
