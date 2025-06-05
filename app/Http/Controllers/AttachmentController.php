<?php

namespace App\Http\Controllers;

use App\Ok\SysError;
use Illuminate\Http\Request;
use stdClass;

use \App\Services\OplogApi;
use \App\Services\Attachments;
use \App\Services\Files;
use \App\Http\Middleware\UserId;
use  \App\Services\M\ShipTask;

class AttachmentController extends Controller
{

    #配置RabbitMq的队列，交换机，路由key
    protected string $queue;
    protected string $exchange;
    protected string $routeKey;

    public function __construct(){
        $so_queue = \Illuminate\Support\Facades\Config::get('app.so_rabbitmq');
        $this->queue = $so_queue["queue"];
        $this->exchange = $so_queue["exchange"];
        $this->routeKey = $so_queue["routeKey"];
    }

    //移除附件关联
    public function removeAttachment(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = new stdClass();
        try {
            $validated = $request->validate([
                'id' => 'integer|required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }

        $file = Attachments::getInstance()->getById($validated['id']);
        if (!$file) {
            [$code, $message] = SysError::PARAMETER_ERROR;
            return $this->renderErrorJson($code, $message);
        }

        $data->affected = Attachments::getInstance()->removeAttachment(["id"=>$validated['id']]);
/*        if($data->affected && ($file->correlate_type == 3)){
            #判断当前采购详单是否存在异常图片
            $flag = Attachments::getInstance()->getExceptional($file->correlate_id);
            $purchaseItem = \App\Services\M\PurchaseOrderDetailed::getInstance()->getInfo($file->correlate_id);
            if(!$flag){
                $tmp= ["attach_exceptional"=>0,];
                if($purchaseItem->audit_quantity>0){
                    $tmp["audit_status"] =1;
                    $tmp["is_audit"] =1;
                }
                \App\Services\M\PurchaseOrderDetailed::getInstance()->updateModel($file->correlate_id,$tmp);
            }
        }*/


        #特殊业务处理,pi
        if($data->affected && $file->correlate_type==8 && $file->flag==0){
            $flag = Attachments::getInstance()->getExceptional($file->correlate_id);
            if(!$flag){
                $tmp= ["attach_exceptional"=>0,];
                $orderItem = \App\Services\CheckDetail::getInstance()->getCheckPi($file->correlate_id);
                if($orderItem->audit_quantity){
                    $tmp["audit_status"] =1;
                    $tmp["is_audit"] =1;
                }
                \App\Services\CheckDetail::getInstance()->updateCheck("order_info_id",$file->correlate_id,$tmp);
            }
        }

        if($data->affected && ($file->correlate_type == 4) ){
            #同步so的图片数据
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,[
                "method"=>"removeAttachment",
                "params"=>[
                    "gd_attachments_id"=>$validated["id"]
                ]
            ]);
        }
        //OplogApi::getInstance()->addLog(\App\Http\Middleware\UserId::$user_id, '删除一个关联附件',sprintf("affected:%s, id：%s", +$data->affected, $validated['id']));
        return $this->renderJson($data);
    }

    //关联附件
    public function addAttachment(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'file_id' => 'integer|required',
                'correlate_id' => 'required|integer|gt:0',
                'correlate_type' => 'required|integer|gt:0',  //关联的类型:1快递的图片; 2快递产品图片 3采购详单; 4发货单(即打包图片); 5发货详单,6采购单图片,7销售订单图片，8销售详单图片
                'flag'  => 'integer|gte:0',  //1正常,0异常
                'pod_id' => 'integer|gte:0',  //特殊业务组合查询(销售详单，采购详单)
            ]);
            $validated["flag"] = isset($validated["flag"]) ? $validated["flag"]: 1;
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = \App\Ok\SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        $file = \App\Services\Files::getInstance()->getById($validated['file_id']);
        if (!$file) {
            return $this->renderErrorJson(\App\Ok\SysError::ATTACHMENT_DOES_NOT_EXIST_ERROR);
        }
        $type = \App\Ok\FileType::getType($file->path);
        $userId = UserId::$user_id;
        $data = new stdClass();
        $data->id = \App\Services\Attachments::getInstance()->addAttachment($userId, $file->id, [
            'correlate_id' => $validated["correlate_id"],
            'correlate_type' => $validated["correlate_type"],
            'type' => $type,
            'flag' => $validated['flag'],
            'pod_id' => !empty($validated['pod_id']) ? $validated['pod_id'] : 0,
        ]);


        # 特殊业务流程 上传异常图片，采购单重新审核
        /*if($data->id && $validated['correlate_type'] == 3 && $validated['flag']==0){
            \App\Services\M\PurchaseOrderDetailed::getInstance()->updateModel($validated['correlate_id'],["attach_exceptional"=>1,"audit_status"=>0,"is_audit"=>0]);
        }*/

        # 发货单的打包图片，同步到外部系统
        if($data->id && $validated['correlate_type'] == 4)
        {
            $shipTask = ShipTask::getInstance()->getByIdF1($validated['correlate_id']);

            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,[
                "method"=>"addAttachment",
                "params"=>[
                    "gd_files_id"=>$file->id,
                    "user_id" =>$file->user_id,
                    "disk"=>"tencent",
                    "path"=>$file->path,
                    "name"=>$file->name,
                    "size"=>$file->size,
                    "shiptask_id"=>$validated['correlate_id'],
                    "crm_shiptask_id"=>$shipTask->crm_shiptask_id,
                    "gd_attachments_id"=>$data->id,
                    "flag" => $validated['flag']
                ]
            ]);
        }

        //\App\Services\OplogApi::getInstance()->addLog(\App\Http\Middleware\UserId::$user_id, '添加一个关联附件', sprintf("%s, %s", +$data->id, json_encode($validated)));
        return $this->renderJson($data);
    }

    //上传文件到腾讯云
    public function uploadAttachment(Request $request): \Illuminate\Http\JsonResponse
    {
        # 上传文件
        $file = $request->file('file');
        if (!$file) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }

        # 支持的文件扩展名
        if (!in_array($file->extension(), [
            'txt',
            'gif','jpg', 'jpeg', 'png', 'webp',
            'mp4',
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'ppt','pptx',
        ])) {
            return $this->renderErrorJson(SysError::EXTENSION_ERROR);
        }
        $userId = \App\Http\Middleware\UserId::$user_id;

        $data = new stdClass();
        # 存储到腾讯云
        $fileName = call_user_func('uniqid') . '.' . $file->extension();
        $filestr = file_get_contents($file->path());
        [$fileInfo, $e] = \App\Ok\TencentStorage::getInstance()->uploadFile($fileName, $filestr);

        if($e){
            return $this->renderErrorJson(1,$e);
        }

        $data->path = strpos($fileInfo['Location'], "https://") ? $fileInfo['Location'] : "https://".$fileInfo['Location'];
        $data->name = $file->getClientOriginalName();
        $data->id = \App\Services\Files::getInstance()->addFile($userId, [
            'disk' => "tencent",
            'path' => $data->path,
            'name' => $data->name,
            'size' => $file->getSize(),
        ]);
        $data->url = sprintf("/api/tracking_order/show?id=%s", $data->id);

        return $this->renderJson($data);
    }

    //获取附件内容
    public function getAttachment(Request $request): \Symfony\Component\HttpFoundation\Response
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

        $file = \App\Services\Files::getInstance()->getById($validated['id']);
        if (!$file) {
            [$code, $message] = SysError::PARAMETER_ERROR;
            return $this->renderErrorJson($code, $message);
        }
        if ($file->disk === 'pictures') {
            return response()->file(base_path() . '/storage/pictures/' . $file->path);
        }
        return response()->streamDownload(function () use ($file) {
            echo \Illuminate\Support\Facades\Storage::disk($file->disk)->get($file->path);
        });
    }

    //设置附件标记
    public function setAttachmentFlag(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $validated = $request->validate([
                'id' => 'integer|required',
                'flag'=>'integer|required',  //标记,1正常,0异常
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            [$code,] = SysError::PARAMETER_ERROR;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        if(!in_array($validated["flag"], [0,1])){
            [$code, $message] = SysError::PARAMETER_ERROR;
            return $this->renderErrorJson($code, $message);
        }

        $file = Attachments::getInstance()->getById($validated['id']);
        if (!$file) {
            [$code, $message] = SysError::PARAMETER_ERROR;
            return $this->renderErrorJson($code, $message);
        }

        $data = new stdClass();
        $data->affected = Attachments::getInstance()->updateByIds([$validated["id"]],["flag"=>$validated["flag"]]);

        #特殊业务处理,pi
        if($file->correlate_type==8){
            $order = \App\Services\M\OrdersItemInfo::getInstance()->getByIdAS($file->correlate_id);
            $tmp = [
                "order_info_id"=>$file->correlate_id,
                "user_id"=> \App\Http\Middleware\UserId::$user_id,
                "order_id" =>$order->order_id,
                "audit_status"=>0,
                "is_audit"=>0,
                ];
            $flag = Attachments::getInstance()->getExceptional($file->correlate_id);
            $tmp["attach_exceptional"] = $flag? 1: 0;
            $orderItem = \App\Services\CheckDetail::getInstance()->getCheckPi($file->correlate_id);
            if(!empty($orderItem) && $orderItem->audit_quantity>0){
                $tmp["audit_status"] =1;
                $tmp["is_audit"] =1;
            }
            \App\Services\CheckDetail::getInstance()->setCheckByInfoId($file->correlate_id,$tmp);
        }

        if($data->affected && ($file->correlate_type == 4)){
            #同步so的图片数据
            \App\Ok\RabbitmqConnection::getInstance()->push($this->queue,$this->exchange,$this->routeKey,[
                "method"=>"setAttachmentFlag",
                "params"=>[
                    "flag"=>$validated["flag"],
                    "gd_attachments_id"=>$validated["id"],
                ]
            ]);
        }

        //OplogApi::getInstance()->addLog(UserId::$user_id, '设置关联附件flag',sprintf("affected:%s, id:%s", +$data->affected, $validated['id']));

        return $this->renderJson($data);
    }
}
