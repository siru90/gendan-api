<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Ok\SysError;


//同步外部系统发货
class CrmShipTaskController extends Controller
{

    //同步外部系统新建SO- 到内部系统
    public function soSyncInt(Request $request): \Illuminate\Http\JsonResponse
    {
        $body = $request->post();
        Log::channel('sync')->info('-8888---CrmShipTask---$body: '.json_encode($body));
        $data = new \stdClass();
        $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->saveShipTask($body);

        # 记录下来到数据库中
        //\App\Services\SyncCrm::getInstance()->addSyncContent(["sync_content"=>json_encode($body),"sync_res"=>$data->res]);
        return $this->renderJson($data);
    }

    //同步外部系统编辑SO- 到内部系统
    public function soSyncEdit(Request $request): \Illuminate\Http\JsonResponse
    {
        $body = $request->post();

        $data = new \stdClass();
        Log::channel('sync')->info('CrmShipTask---$body: '.json_encode($body));
        switch ($body["method"]) {
            case "addAttachment":
                //Log::channel('sync')->info('444 \n');
                $data->res = \App\Services\Sync\AttachmentSync::getInstance()->addAttach($body);
                break;
            case "removeAttachment":
                //Log::channel('sync')->info('555 \n');
                $data->res = \App\Services\Sync\AttachmentSync::getInstance()->removeAttach($body);
                break;
            case "editShiptask":
                //Log::channel('sync')->info('666 \n');
                $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->editShiptask($body);
                break;
            case "syncKey":
                //Log::channel('sync')->info('777 \n');
                $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->editShiptaskIds($body);
                break;
            case "sendShiptask":
                Log::channel('sync')->info('888 \n');
                $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->sendShiptask($body);
                break;
            default:
                $data->res = true;
                break;
        }

        return $this->renderJson($data);
    }


    public function soSyncEditFill(Request $request): \Illuminate\Http\JsonResponse
    {
        $list = \App\Services\M2\MqMessage::getInstance()->getStatusIsZero();
        $data = new \stdClass();
        foreach ($list as $obj){
            $body = json_decode($obj->message_body,true);
            switch ($body["method"]) {
                case "addAttachment":
                    //Log::channel('sync')->info('444 \n');
                    $data->res = \App\Services\Sync\AttachmentSync::getInstance()->addAttach($body);
                    break;
                case "removeAttachment":
                    //Log::channel('sync')->info('555 \n');
                    $data->res = \App\Services\Sync\AttachmentSync::getInstance()->removeAttach($body);
                    break;
                case "editShiptask":
                    //Log::channel('sync')->info('666 \n');
                    $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->editShiptask($body);
                    break;
                case "syncKey":
                    //Log::channel('sync')->info('777 \n');
                    $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->editShiptaskIds($body);
                    break;
                case "sendShiptask":
                    Log::channel('sync')->info('888 \n');
                    $data->res = \App\Services\Sync\ShipTaskSync::getInstance()->sendShiptask($body);
                    break;
                default:
                    $data->res = true;
                    break;
            }
            $param = ["status"=>1,"consumed_at"=>date("Y-m-d H:i:s")];
            \App\Services\M2\MqMessage::getInstance()->editMqMessage($obj->id,$param);
        }
        return $this->renderJson($data);
    }

    //发送队列失败后补发: gd_order_queue
    public function gdOrderQueue(Request $request): \Illuminate\Http\JsonResponse
    {
        $messageBody = $request->post();
        if(empty($messageBody)){
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }

        #获取message_id
        $mId = $messageBody["message_id"] ?? 0;
        unset($messageBody["message_id"]);

        #配置RabbitMq的队列，交换机，路由key
        $order_rabbitmq = \Illuminate\Support\Facades\Config::get('app.order_rabbitmq');
        \App\Ok\RabbitmqConnection::getInstance()->push($order_rabbitmq["queue"],$order_rabbitmq["exchange"],$order_rabbitmq["routeKey"],$messageBody);

        if($mId){
            \App\Services\M2\MqMessage::getInstance()->editMqMessage($mId,["status"=>0]);
        }

        $data = new \stdClass();
        return $this->renderJson($data);
    }


    //发送队列失败后补发: gd_so_queue
    public function gdSoQueue(Request $request): \Illuminate\Http\JsonResponse
    {
        $messageBody = $request->post();
        if(empty($messageBody)){
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }

        #获取message_id
        $mId = $messageBody["message_id"] ?? 0;

        $so_queue = \Illuminate\Support\Facades\Config::get('app.so_rabbitmq');
        \App\Ok\RabbitmqConnection::getInstance()->push($so_queue["queue"],$so_queue["exchange"],$so_queue["routeKey"],$messageBody);
        if($mId){
            \App\Services\M2\MqMessage::getInstance()->editMqMessage($mId,["status"=>0]);
        }

        $data = new \stdClass();
        return $this->renderJson($data);
    }



    //同步外部系统产品到内部系统
    public function productToInternal(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->post();
        $list = new \stdClass();
        $list->notSync = $list->sync = [];
        foreach ($data as $id){
            $product = \App\Services\M2\IhuProduct::getInstance()->getById($id);
            $desc = \App\Services\M2\IhuProductDescription::getInstance()->getByProductId($id);
            if($product){
                $res = \App\Services\M\IhuProduct::getInstance()->addProductTwo($product,$desc->product_description_id);
                if($res){
                    $list->sync[] = $id;
                    unset($data[$id]);
                }
            }
        }
        $list->notSync = $data;
        return $this->renderJson($list);
    }

    //同步内部系统产品到外部系统
    public function productToCrm(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->post();
        $list = new \stdClass();
        $list->notSync = $list->sync = [];
        foreach ($data as $id){
            $product = \App\Services\M\IhuProduct::getInstance()->getById($id);
            $desc = \App\Services\M\IhuProductDescription::getInstance()->getByProductId($id);
            if($product){
                $res = \App\Services\M2\IhuProduct::getInstance()->addProductTwo($product,$desc->product_description_id);
                if($res){
                    $list->sync[] = $id;
                    unset($data[$id]);
                }
            }
        }
        $list->notSync = $data;
        return $this->renderJson($list);
    }
}
?>
