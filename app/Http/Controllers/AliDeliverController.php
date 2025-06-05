<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AliDeliverController extends Controller
{

    //快递物流节点跟踪:对接阿里云接口
    public function showapiExpInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'com'=>'string', //com: 快递公司字母简称,可以从接口"快递公司查询" 中查到该信息 可以使用"auto"代替表示自动识别,不推荐大面积使用auto，建议尽量传入准确的公司编码。
                'nu'=>'required|string', //快递单号
                'receiver_phone'=>'string', // 收/寄件人手机号后四位，顺丰快递必须填写本字段
                'sender_phone'=>'string',
            ]);
            if (!isset($validated['com'])) $validated['com'] = "auto";
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        //验证手机号码
        if(!empty($validated["receiver_phone"]) && !preg_match('/^1[3456789]\d{9}$/', $validated["receiver_phone"]) ){
            return $this->renderErrorJson(21, "receiver_phone参数错误");
        }
        if(!empty($validated["sender_phone"]) && !preg_match('/^1[3456789]\d{9}$/', $validated["sender_phone"]) ){
            return $this->renderErrorJson(21, "sender_phone参数错误");
        }

        $data = \App\Services\AliDeliver::getInstance()->aliDeliverShowapi($validated);

        return $this->renderJson($data);
    }




}
?>
