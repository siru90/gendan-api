<?php

namespace App\Services;

class SoStatus extends BaseService
{

    protected string $table = 'gd_so_status';

    // 添加或更新so操作状态记录
    public function setSoStatus($soId, array $values): array
    {
        $values['status'] = 1;
        $this->db->beginTransaction();
        try {
            $info = $this->getSoStatusLock($soId);
            if ($info) {
                $affected = $this->tb->where('id', $info->id)->update($values);
                $id = $info->id;
            } else {
                $id = $this->get_unique_id();
                $sets['id'] = $id;
                $sets['so_id'] = $soId;
                $sets['status'] = 1;
                $affected = $this->tb->insert($sets);
            }
            $this->db->commit();
            return [$id, $affected];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return [false, 0];
        }
    }

    public function updateSoStatus($id,$values)
    {
        return $this->tb->where('id', $id)->update($values);
    }

    private function getSoStatusLock($soId): ?object
    {
        //return $this->mtb->where('so_id', $soId)->lockForUpdate()->first();  //排他锁
        return $this->tb->where('so_id', $soId)->first();
    }

    public function getSoStatus($soId): ?object
    {
        return $this->tb->where('so_id', $soId)->first();
    }

    public function getStatus($soId): ?object
    {
        return $this->tb->select("id","so_id","zw_gd_status","zw_kd_status","db_status",)->where('so_id', $soId)->first();
    }
}
