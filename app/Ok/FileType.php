<?php

namespace App\Ok;

class FileType
{

    public static function getType($path): int
    {
        $type = 0;
        if (str_ends_with($path, '.mp4')) {
            $type = 1;
        }
        return $type;
    }
}
