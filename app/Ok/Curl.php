<?php

namespace App\Ok;
use Illuminate\Support\Facades\Log;

class Curl
{
    use \App\Utils\GetInstances;

    public function curlPost($url, $data)
    {
        Log::channel('sync')->info('[curlPost] $url: '.$url);
        Log::channel('sync')->info('[curlPost] $data: '.json_encode($data));

        $token = \Illuminate\Support\Facades\Redis::command("get", ["token"]);
        $headers = [
            "Content-Type:application/json;charset=utf-8",
            "Authorization:".$token,
        ];
        if(is_array($data) || is_object($data)){
            $data = json_encode($data);
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl,CURLOPT_POST,true);//Post请求方式
        curl_setopt($curl,CURLOPT_POSTFIELDS,$data);//Post变量
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_HEADER, false); //用false，不返回响应头信息，只返回json数据
        $output= json_decode(curl_exec($curl),true);
        curl_close($curl);//释放cURL句柄

        Log::channel('sync')->info('[curlPost] $output: '.json_encode($output));
        return $output;
    }

    public function curlGet($url)
    {
        //Log::channel('sync')->info('[curlGet] $url: '.$url);
        $headers = [
            "Content-Type:application/json;charset=utf-8",
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_HEADER, false); //用false，不返回响应头信息，只返回json数据
        $output= json_decode(curl_exec($curl),true);
        curl_close($curl);//释放cURL句柄

        //Log::channel('sync')->info('[curlGet] $output: '.json_encode($output));
        return $output;
    }

}
?>
