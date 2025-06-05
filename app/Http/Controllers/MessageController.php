<?php
namespace App\Http\Controllers;

use stdClass;
use Illuminate\Http\Request;
use \App\Http\Middleware\UserId;
use \App\Services\Message;
use App\Services\M\User as MissuUser;

class MessageController extends Controller
{
    //消息列表
    public function list(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        #判断用户是否是管理员
        $userInfo = MissuUser::getInstance()->getUserInfo(\App\Http\Middleware\UserId::$user_id);
        //if($userInfo->user_group_id != 9){   #9总经理
            $validated["userId"] = UserId::$user_id;
        //}
        $data = new stdClass();
        [$data->list, $data->total] = Message::getInstance()->getMessageList($validated);

        # 补齐purchaser_id对应的信息，放入$data->list['purchaser']字段里
        MissuUser::getInstance()->fillUsers($data->list, 'user_id', 'user');

        [, $data->unreadCount] = Message::getInstance()->getMessageOne(UserId::$user_id);
        return $this->renderJson($data);
    }

    // 默认显示最新一条
    public function getOne(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = null;
        #判断用户是否是管理员,目前总经理可以看全部
        $userInfo = MissuUser::getInstance()->getUserInfo(\App\Http\Middleware\UserId::$user_id);
        //if($userInfo->user_group_id != 9){   #9总经理
            $userId = UserId::$user_id;
        //}


        //$data = \Illuminate\Support\Facades\Redis::command("get", ["gd_message_getOne_".$userId]);
        //$data = json_decode($data);
        if(empty($data)){
            $data = new stdClass();
            [$data->list, $data->unreadCount] = Message::getInstance()->getMessageOne($userId);
            //\Illuminate\Support\Facades\Redis::command("set", ["gd_message_getOne_".$userId, json_encode($data), ['EX' => 3600 * 1]]);
        }
        return $this->renderJson($data);
    }

    //删除全部已读
    public function deleteAllRead(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = UserId::$user_id;
        $data = new stdClass();
        $data->affect = Message::getInstance()->deleteRead($userId);
        return $this->renderJson($data);
    }

    //删除一条
    public function deleteOne(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'message_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userId = UserId::$user_id;
        $res = Message::getInstance()->getMessageById($validated['message_id'],$userId);
        if (!$res) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }

        $data = (object)$validated;
        $data->affect = Message::getInstance()->deleteMessage($validated['message_id'],$userId);
        return $this->renderJson($data);
    }

    //清空全部未读消息，置为已读
    public function clearUnread(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = UserId::$user_id;
        $data = new stdClass();
        $data->affect = Message::getInstance()->updateIsRead($userId);
        return $this->renderJson($data);
    }

    //消息置为已读
    public function setRead(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'message_id' => 'required|integer|gt:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $userId = UserId::$user_id;
        $res = Message::getInstance()->getMessageById($validated['message_id'],$userId);
        if (!$res) {
            return $this->renderErrorJson(\App\Ok\SysError::PARAMETER_ERROR);
        }
        $data = (object)$validated;
        $data->affect = Message::getInstance()->updateMessage($validated["message_id"], ["is_read"=>1]);
        return $this->renderJson($data);
    }
}
