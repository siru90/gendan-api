<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \App\Ok\SysError;
//use \App\Services\ExpressDeliveryChannels;
use \App\Services\M\ExpressCompany;
use \App\Services\ExpressDelivery;
use \App\Services\OplogApi;

class ExpressChannelController extends Controller
{

    //渠道列表
    public function channelList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'integer|gt:0',
                'size' => 'integer|gt:0|lte:1000',
                'keyword' => 'string',
            ]);
            if (!isset($validated['page'])) $validated['page'] = 1;
            if (!isset($validated['size'])) $validated['size'] = 20;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = (object)$validated;
        [$data->list, $data->total] = ExpressCompany::getInstance()->getChannelList($validated);
        return $this->renderJson($data);
    }

    //渠道详细
    public function getChannel(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $data = ExpressCompany::getInstance()->getChannel($validated['id']);
        if (!$data) {
            return $this->renderErrorJson(SysError::COMPANY_NO_FOUND_ERROR);
        }
        return $this->renderJson($data);
    }



}
