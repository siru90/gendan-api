<?php

namespace App\Services;

class OplogApi extends BaseService
{

    protected string $table = 'gd_oplog_api';

    public function addLog(int $userId, string $title, string $note): int
    {
        $values['user_id'] = $userId;
        $values['title'] = $title;
        $values['note'] = $note;
        $values['status'] = 1;
        $values['id'] = $this->get_unique_id();
        $this->tb->insert($values);
        return $values['id'];
    }
}
