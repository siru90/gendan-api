<?php

namespace App\Services;

class Oplog extends BaseService
{
    protected string $table = 'gd_oplog';

    public function addLog(array $values):int
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $values['status'] = 1;
        $this->tb->insert($values);
        return $id;
    }

    //添加发货日志
    public function addSoLog(int $userId, int $so_id, string $title, string $note, array $args = []): int
    {
        $attachment_ids = $f9 = $f10 = $flag = null;
        extract($args);
        $values['user_id'] = $userId;
        $values['title'] = $title;
        $values['note'] = $note;
        $values['so_id'] = $so_id;
        $values["correlate_type"] = 0;
        if ($attachment_ids) $values['attachment_ids'] = $attachment_ids;
        if (!empty($f9)) $values['f9'] = $f9;
        if (!empty($f10)) $values['f10'] = $f10;
        if ($flag) $values['flag'] = $flag;

        return $this->addLog($values);
    }

    //添加快递日志
    public function addExpLog(int $userId, int $express_id, string $title, string $note="", array $args = []): int
    {
        $attachment_ids = $f9 = $flag = null;
        extract($args);
        $values = [];
        $values['user_id'] = $userId;
        $values['title'] = $title;
        $values['note'] = $note;
        $values['correlate_type'] = 3;
        $values['correlate_id'] = $express_id;
        if ($attachment_ids) $values['attachment_ids'] = $attachment_ids;
        if (!empty($f9)) $values['f9'] = $f9;
        if ($flag) $values['flag'] = $flag;

        if ($flag) {
            $info = $this->getExpLog($express_id, $flag);
            if ($info) {
                return $this->tb->where('id', $info->id)->update($values);
            }
        }
        return $this->addLog($values);
    }

    //添加核对日志
    public function addCheckLog(int $userId, int $id, string $title, string $note="", array $args = []): int
    {
        #`correlate_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '关联的类型:1采购单; 2销售订单;3快递单',

        $attachment_ids = $f10 = $f9 = $flag = null;
        extract($args);
        $values = [];
        $values['user_id'] = $userId;
        $values['title'] = $title;
        $values['note'] = $note;
        $values['correlate_id'] = $id;
        $values['correlate_type'] = 2;
        if ($attachment_ids) $values['attachment_ids'] = $attachment_ids;
        if (!empty($f9)) $values['f9'] = $f9;
        if (!empty($f10)) $values['f10'] = $f10;
        if ($flag) $values['flag'] = $flag;

        return $this->addLog($values);
    }

    //获取单条快递日志
    public function getExpLog(int $express_id, string $flag): ?object
    {
        return $this->tb->where('correlate_id', $express_id)->where("correlate_type",3)->where('flag', $flag)->where('status', 1)->first();
    }

    //分页获取发货日志
    public function getSoLogs(array $args): array
    {
        $page = $size = 0;
        $so_id = 0;
        extract($args);
        $tb = $this->tb->where('status', 1)->where('so_id', $so_id);

        $totalTb = clone $tb;

        $offset = max(0, ($page - 1) * $size);
        $list = $tb->select([
            "id","user_id","title","note","so_id","status","flag","created_at","updated_at","f9","f10"
        ]) ->orderByDesc("id")->offset($offset)->limit($size)->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    //分页获取快递日志
    public function getExpLogs(array $args): array
    {
        $page = $size = 0;
        $express_id = 0;
        extract($args);
        $tb = $this->tb->where('status', 1)->where('correlate_id', $express_id)->where("correlate_type",3);

        return $this->ok($tb, $page, $size);
    }

    //分页获取核对日志
    public function getCheckLogs(array $args): array
    {
        $page = $size = $order_id = 0;
        extract($args);
        $offset = max(0, ($page - 1) * $size);

        $tb = $this->tb->where('status', 1)->where("correlate_type",2)->where('correlate_id', $order_id);
        $totalTb = clone $tb;
        $list = $tb->select([
                "id","user_id","title","note","correlate_type","correlate_id","status","flag","created_at","updated_at","f9","f10"
            ])
            ->orderByDesc("id")->offset($offset)->limit($size)->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;
        return [$list, $total];
    }

    //分页查询
    private function ok(\Illuminate\Database\Query\Builder $tb, int $page, int $size): array
    {
        $totalTb = clone $tb;

        $offset = max(0, ($page - 1) * $size);
        $list = $tb->orderByDesc("id")->offset($offset)->limit($size)->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;

        return [$list, $total];
    }
}
