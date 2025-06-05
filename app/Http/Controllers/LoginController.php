<?php

namespace App\Http\Controllers;

use stdClass;
//use App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class LoginController extends Controller
{

    // 登录接口
    public function login(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'username' => 'required|max:128',
                'password' => 'required|max:128',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $request->cookie('token');
        try {
            $user = MissuUser::getInstance()->login($validated['username'], $validated['password']);
        } catch (\Throwable $e) {
            return $this->renderErrorJson(\App\Ok\SysError::LOGIN_ERROR);
        }
        if (!$user) {
            return $this->renderErrorJson(\App\Ok\SysError::LOGIN_ERROR);
        }

        $payload = new stdClass();
        $payload->id = $user->id;
        $payload->name = $user->name;
        $payload->exp = time() + 3600 * 24 * 7;
        $payload->nbf = time();
        $payload->iat = time();
        $key = \env('JWT_PASSWORD');
        $jwt = \Firebase\JWT\JWT::encode((array)$payload, $key, 'HS256');

        \Illuminate\Support\Facades\Cookie::queue('token', $jwt, 3600 * 24 * 7);
        \Illuminate\Support\Facades\Redis::command("set", ['token', $jwt, ['EX' => 3600 * 24 * 7]]);

        \App\Services\OplogApi::getInstance()->addLog(\App\Http\Middleware\UserId::$user_id, 'Login',
            sprintf("jwt: %s %s", $jwt, json_encode($validated)));

        $data = new stdClass();
        $data->token = $jwt;
        //        $data->user_group_id = $user->user_group_id;
        //        $data->groupInfo = \App\Services\M\UserGroup::getInstance()->getGroupInfo($user->user_group_id);
        //        $data->role = 1;
        return $this->renderJson($data);
    }
}
