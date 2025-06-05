<?php
namespace App\Http\Controllers;

//use \App\Services\M\MissuUser as MissuUser;
use App\Services\M\User as MissuUser;

class UserController extends Controller
{
    //获取用户信息
    public function getUserInfo(): \Illuminate\Http\JsonResponse
    {
        $userID = \App\Http\Middleware\UserId::$user_id;
        $data = MissuUser::getInstance()->getUserInfo($userID);
        return $this->renderJson($data);
    }

    //获取分享用户常用，采购列表
    public function getPurchaserCommon(): \Illuminate\Http\JsonResponse
    {
        $userID = \App\Http\Middleware\UserId::$user_id;
        $data = new \stdClass();

        $data->purchaser = $this->getPurchasersOrSales(3,"gd_purchasers");
        $data->common = \App\Services\PermissionShare::getInstance()->commonUser($userID);
        return $this->renderJson($data);
    }

    //获取采购列表
    public function getPurchasers(): \Illuminate\Http\JsonResponse
    {
        $data=$this->getPurchasersOrSales(3,"gd_purchasers");
        return $this->renderJson($data);
    }

    //获取销售列表
    public function getSales(): \Illuminate\Http\JsonResponse
    {
        $data=$this->getPurchasersOrSales(1,"gd_sales");
        return $this->renderJson($data);
    }

    //获取打包列表
    public function getPacks(): \Illuminate\Http\JsonResponse
    {
        $data=$this->getPurchasersOrSales(99,"gd_packs");
        return $this->renderJson($data);
    }

    private function getPurchasersOrSales(int $sales,$name):array
    {
        $data = \Illuminate\Support\Facades\Redis::command("get", [$name]);
        $data = json_decode($data);
        if(empty($data)){
            $data = MissuUser::getInstance()->getUserByGroupId($sales);
            \Illuminate\Support\Facades\Redis::command("set", [$name, json_encode($data), ['EX' => 3600 * 24]]);
        }
        return $data;
    }

}
