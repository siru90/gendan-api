<?php

namespace App\Services;
use Illuminate\Support\Facades\DB;

class ExpressDeliveryChannels extends BaseService
{

    protected string $table = 'gd_express_delivery_channels';

    protected  array $casts = ['id' => 'string'];

    public function createChannel(array $values): int
    {
        $id = $this->get_unique_id();
        $values['id'] = $id;
        $values['status'] = 1;
        $this->tb->insert($values);
        return $id;
    }

    public function updateChannel($id, array $values): int
    {
        unset($values['id']);
        return $this->tb->where('id', $id)->update($values);
    }

    public function deleteChannel(int $id): int
    {
        return $this->tb->where('id', $id)->update([
            'status' => -1,
        ]);
    }

    public function getChannel($id): object|null
    {
        return $this->tb->select('id', 'name')->where('id', $id)->where('status', 1)->first();
    }

    public function getChannelName($id): string|null
    {
        return $this->tb->select('name')->where('id', $id)->where('status', 1)->value("name");
    }

    public function getChannelByName($name): object|null
    {
        return $this->tb->where('name', $name)->where('status', 1)->first();
    }

    public function getChannels(array $args): array
    {
        $page = $size = 0;
        extract($args);
        $tb = $this->tb->where('status', 1);

        $totalTb = clone $tb;

        $offset = max(0, ($page - 1) * $size);
        $list = $tb->select('id', 'name')->orderBy("id","desc")->offset($offset)->limit($size)->get()->toArray();

        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;

        return [$list, $total];
    }

}
