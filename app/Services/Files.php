<?php

namespace App\Services;

class Files extends BaseService
{

    protected string $table = 'gd_files'; #附件表

    //上传文件
    public function addFile(int $userId, array $values): int
    {
        $values["id"] = $this->get_unique_id();
        $values['user_id'] = $userId;
        $values['status'] = 1;
        $affected = $this->tb->insert($values);
        return $values["id"];
    }

    public function getById(int $id): object|null
    {
        if (!$id) return null;
        return $this->tb->where('id', $id)->where('status', 1)->first();
    }

    //上传缩略图
    public function updateThumbnail(int $fileId, int $thumbnailId): int
    {
        return $this->tb->where('id', $fileId)->where('status', 1)->update([
            'thumbnail_id' => $thumbnailId,
        ]);
    }


}
