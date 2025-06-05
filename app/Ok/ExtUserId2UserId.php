<?php

namespace App\Ok;

class ExtUserId2UserId
{

    public static function getUserIdByExternalToken($token)
    {
        //延长有效期5分钟
        \Firebase\JWT\JWT::$leeway+=300;
        $user = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(env('JWT_PASSWORD'), 'HS256'));
        return $user->id;
    }
}
