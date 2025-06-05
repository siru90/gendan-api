<?php
namespace App\Ok;

class TencentStorage {

    use \App\Utils\GetInstances;


    //上传文件到腾讯云
    // 执行：composer require  qcloud/cos-sdk-v5 先安装扩展
    public function uploadFile($fileName,$body)
    {
        $secretId = env('STORAGE_SECRETID'); //用户的 SecretId
        $secretKey = env('STORAGE_SECREKEY'); //用户的 SecretKey
        $bucket = env('STORAGE_BUCKET');     //存储桶名称 格式：BucketName-APPID

        $region = "accelerate"; //用户的 region，已创建桶归属的 region地区:    ap-guangzhou
        $cosClient = new \Qcloud\Cos\Client([
            'region' => $region,
            'schema' => 'http', //协议头部，默认为 http
            'credentials' => ['secretId' => $secretId, 'secretKey' => $secretKey],
        ]);

        # 上传文件
        ## putObject(上传接口，最大支持上传5G文件)
        ### 上传内存中的字符串
        try {
            $key = $fileName;
            $result = $cosClient->putObject(array(
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $body,
            ));
            return [$result, 0];
        }
        catch (\Exception $e) {
            //echo "$e\n";
            return [null,$e];
        }
    }

}
