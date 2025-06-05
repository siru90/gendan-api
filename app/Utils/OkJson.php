<?php

namespace App\Utils;

use Illuminate\Http\JsonResponse;
use stdClass;

trait OkJson
{

    public final function renderErrorJson($code, string $message = null, $result = null, array $replace = []): JsonResponse
    {
        if (is_array($code)) [$code, $message] = $code;
        return $this->renderJson($result, $code, __($message, $replace));
    }

    public final function renderJson($result, $code = 0, string $message = null): JsonResponse
    {
        $data = new stdClass();
        $data->code = $code;
        if ($result !== null) {
            $data->data = $result;
        }
        if ($message !== null) {
            $data->message = $message;
        }
        $data->time = round(microtime(true) - LARAVEL_START, 3);
        return response()->json($data)->withCallback(\request()->input('callback'));
    }
}
