<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ExpressChannelController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\OpLogController;
use App\Http\Controllers\PiController;
use App\Http\Controllers\PoController;
use App\Http\Controllers\ShipPackageController;
use App\Http\Controllers\SoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExpressController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\CheckPoController;
use App\Http\Controllers\CheckPiController;
use App\Http\Controllers\PermissionShareController;

use Illuminate\Support\Facades\DB;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

//Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//    return $request->user();
//});

Route::get('/ping', function (){
    $data = Db::select("show variables like 'query_cache%'");
    $result = ['code'=>0,'message'=>'','data'=>$data];
    return json_encode($result);
});
//外部系统ES型号搜索
Route::get('search_model', [App\Http\Controllers\OpenController::class, 'search']);
Route::get('add_model', [App\Http\Controllers\OpenController::class, 'addProduct']);
Route::get('del_model', [App\Http\Controllers\DeleteProductController::class, 'deleteEXTERNAL']);


//内部系统ES型号搜索
Route::get('internal/search_model', [App\Http\Controllers\OpenInternalModelController::class, 'search']);
Route::get('internal/add_model', [App\Http\Controllers\OpenInternalModelController::class, 'addProduct']);
Route::get('internal/del_model', [App\Http\Controllers\DeleteProductController::class, 'deleteInternal']);
Route::get('root_path', [App\Http\Controllers\OpenController::class, 'rootPath']);

Route::prefix('tracking_order')->middleware('set_database')->middleware('require_login')->group(function () {
    Route::post('login', [LoginController::class, 'login'])->withoutMiddleware('require_login');

    //Route::get('sign/status', [ExpressController::class, 'getSignStatus']);
    //Route::get('submit/status', [ExpressController::class, 'getSubmitStatus']);
    Route::get('shipping/ways', [ExpressController::class, 'getShippingWays']);
    Route::prefix('user')->group(function () {
        Route::get('sales', [UserController::class, 'getSales']);
        Route::get('purchasers', [UserController::class, 'getPurchasers']);
        Route::get('packs', [UserController::class, 'getPacks']);
        Route::get('info', [UserController::class, 'getUserInfo']);
        Route::get('purchaser_common', [UserController::class, 'getPurchaserCommon']);
    });
    Route::prefix('country')->group(function () {
        Route::get('list', [ExpressController::class, 'countryList']);
    });

    //Route::get('search_pi_orders', [ExpressController::class, 'searchPiOrdersByModel']);

    Route::prefix('express')->group(function () {
        //快递列表
        Route::get('logistics', [ExpressController::class, 'ExpressLogistics']); //快递物流
        Route::get('attachments', [ExpressController::class, 'getExpressAttachments']); //快递附件
        //Route::post('saveInfo', [ExpressController::class, 'saveExpressInfo']);
        //Route::get('list', [ExpressController::class, 'expresseList']);
        Route::get('info', [ExpressController::class, 'expressInfo']);
        //Route::post('submit', [ExpressController::class, 'submitExpress']);
        Route::post('update', [ExpressController::class, 'updateExpress']);
        //Route::post('create', [ExpressController::class, 'createExpress']);
        //Route::post('delete', [ExpressController::class, 'deleteExpress']);
        //Route::get('logs', [OpLogController::class, 'getExpressLogs']);

        Route::get('isexist', [ExpressController::class, 'getExpressByExpressName']);
        Route::post('abnormal_save', [ExpressController::class, 'saveAbnormalExpress']);
        Route::post('abnormal_status_edit', [ExpressController::class, 'editAbnormalStatus']);
        Route::get('abnormal_list', [ExpressController::class, 'abnormalList']);
        Route::get('abnormal_info', [ExpressController::class, 'getAbnormalExpressInfo']);
        Route::post('abnormal_product_edit', [ExpressController::class, 'batchAbnormalProduct']);
        Route::post('abnormal_turn_record', [ExpressController::class, 'turnAbnormalRecord']);
        Route::get('abnormal_express_search', [ExpressController::class, 'abnormalExpressSearch']);


        Route::post('express_on_purchaseorder', [ExpressController::class, 'expressOnPurchaseorder'])->withoutMiddleware('require_login');
        Route::get('get_express_purchaseorder', [ExpressController::class, 'getExpressPurchaseorder'])->withoutMiddleware('require_login');
        Route::post('remove_express_purchaseorder', [ExpressController::class, 'removeExpressPurchaseorder'])->withoutMiddleware('require_login');
        //Route::post('update_express_purchaseorder', [ExpressController::class, 'updateExpressPurchaseorder']);

        Route::post('sync_add_express', [ExpressController::class, 'syncAddExpress'])->withoutMiddleware('require_login');
        Route::post('sync_remove_express', [ExpressController::class, 'syncRemoveExpress'])->withoutMiddleware('require_login');
        Route::post('sync_fill_express', [ExpressController::class, 'syncFillIdExpress'])->withoutMiddleware('require_login');


        //快递渠道
        Route::get('channel', [ExpressChannelController::class, 'getChannel']);
        Route::prefix('channel')->group(function () {
            Route::get('list', [ExpressChannelController::class, 'channelList']);
        });

        //快递产品
        Route::prefix('model')->group(function () {
            //Route::get('search', [ModelController::class, 'modelSerach']);
            //Route::post('update', [ModelController::class, 'updateModel']);
            //Route::get('list', [ModelController::class, 'modelList']);
            //Route::post('create', [ModelController::class, 'createModel']);
            //Route::post('delete', [ModelController::class, 'deleteModel']);
            Route::get('info', [ModelController::class, 'modelInfo']);
            Route::post('edit', [ModelController::class, 'editModel']);
            Route::post('purchaser_process', [ModelController::class, 'purchaseProcess']);


            //Route::prefix('serial_number')->group(function () {
            //    Route::get('scan', [ModelController::class, 'scanSerialNumbers']);
            //});
        });
    });

    //附件：图片，视频上传添加删除
    Route::post('upload', [AttachmentController::class, 'uploadAttachment']);
    Route::get('show', [AttachmentController::class, 'getAttachment']);
    Route::prefix('attachment')->group(function () {
        Route::post('add', [AttachmentController::class, 'addAttachment']);
        Route::post('remove', [AttachmentController::class, 'removeAttachment']);
        Route::post('setFlag', [AttachmentController::class, 'setAttachmentFlag']);
    });

    Route::prefix('pi')->group(function () {
        Route::get('list', [PiController::class, 'piList']);
        Route::get('info', [PiController::class, 'piInfo']);
        Route::get('unConfirmed', [PiController::class, 'unConfirmed']);
        Route::get('initDelivery', [PiController::class, 'initDelivery']);
        Route::post('delivery', [PiController::class, 'delivery']);
    });

    Route::prefix('po')->group(function () {
        Route::get('list', [PoController::class, 'poList']);
        Route::get('info', [PoController::class, 'poInfo']);
        Route::get('unConfirmed', [PoController::class, 'unConfirmed']);
    });

    Route::prefix('so')->group(function () {
        Route::get('unConfirmed', [SoController::class, 'unConfirmed']);
        Route::get('info', [SoController::class, 'soInfo']);
        Route::get('list', [SoController::class, 'soList']);
        Route::get('attach', [SoController::class, 'soAttach']);
        Route::get('search', [SoController::class, 'productsSerach']);
        Route::post('update_express', [SoController::class, 'updateExpress']);
        Route::post('confirm_shipment', [SoController::class, 'confirmShipment']);
        Route::post('gd_op', [SoController::class, 'gd_op']);
        Route::post('kd_op', [SoController::class, 'kd_op']);
        Route::post('db_op', [SoController::class, 'db_op']);
        Route::get('logs', [OpLogController::class, 'getSoLogs']);
        Route::post('add_pack', [ShipPackageController::class, 'addPack']);
        Route::post('rm_pack', [ShipPackageController::class, 'rmPack']);
        Route::post('edit_pack', [ShipPackageController::class, 'editPack']);
        Route::get('export', [SoController::class, 'soExport'])->withoutMiddleware('require_login');
    });

    //消息
    Route::prefix('message')->group(function () {
        Route::get('list', [MessageController::class, 'list']);
        Route::get('one', [MessageController::class, 'getOne']);
        Route::get('delete/all', [MessageController::class, 'deleteAllRead']);
        Route::get('delete/one', [MessageController::class, 'deleteOne']);
        Route::get('clear', [MessageController::class, 'clearUnread']);
        Route::post('setRead', [MessageController::class, 'setRead']);
    });

    //核对po
/*    Route::prefix('check')->group(function () {
        Route::get('express_list', [CheckPoController::class, 'expressList']);
        Route::get('unConfirmed', [CheckPoController::class, 'unConfirmed']);
        Route::get('list', [CheckPoController::class, 'checkList']);
        Route::get('info', [CheckPoController::class, 'checkInfo']);
        Route::get('attach', [CheckPoController::class, 'checkAttach']);
        Route::post('submit', [CheckPoController::class, 'checkSubmit']);
        Route::post('inspection', [CheckPoController::class, 'checkInspection']);
        Route::get('product/info', [CheckPoController::class, 'productInfo']);
        Route::post('product/save', [CheckPoController::class, 'saveModel']);
        Route::get('product/search', [CheckPoController::class, 'modelSerach']);
        Route::get('audit/info', [CheckPoController::class, 'auditInfo']);
        Route::post('audit/submit', [CheckPoController::class, 'auditSubmit']);
        //Route::get('logs', [OpLogController::class, 'getCheckLogs']);
        //Route::get('scan/express', [CheckPoController::class, 'scanExpress']);
    });*/


    //核对Pi
    Route::prefix('check_pi/')->group(function () {
        Route::get('list', [CheckPiController::class, 'checkList']);
        Route::get('info', [CheckPiController::class, 'checkInfo']);
        Route::get('product/search', [CheckPiController::class, 'modelSerach']);
        Route::get('product/info', [CheckPiController::class, 'productInfo']);
        Route::post('product/save', [CheckPiController::class, 'saveModel']);
        Route::get('attach', [CheckPiController::class, 'checkAttach']);
        Route::post('comment', [CheckPiController::class, 'checkComment']);
        Route::post('inspect', [CheckPiController::class, 'checkInspect']);
        Route::get('audit/info', [CheckPiController::class, 'auditInfo']);
        Route::post('audit/submit', [CheckPiController::class, 'auditSubmit']);
        Route::get('express_list', [CheckPiController::class, 'expressList']);
        Route::get('express_order', [CheckPiController::class, 'initExpressOrderInfo']);
        Route::get('express_product', [CheckPiController::class, 'initExpressProduct']);
        Route::post('abnormal_submit', [CheckPiController::class, 'submitAbnormal']);
        Route::get('piname_search', [CheckPiController::class, 'piNameSearch']);

        Route::get('logs', [OpLogController::class, 'getCheckLogs']);
        Route::get('scan/express', [CheckPiController::class, 'scanExpress']);
    });

    //权限
    Route::prefix('permission/')->group(function () {
        Route::post('global_share', [PermissionShareController::class, 'globalShare']);
        Route::post('global_recovery', [PermissionShareController::class, 'globalRecovery']);
        Route::post('express_share', [PermissionShareController::class, 'expressShare']);
        Route::post('pi_share', [PermissionShareController::class, 'piShare']);
        Route::post('pi_recovery', [PermissionShareController::class, 'piRecovery']);
        Route::get('single_recovery', [PermissionShareController::class, 'singleRecovery']);
        Route::get('list', [PermissionShareController::class, 'shareList']);
        Route::get('share_users', [PermissionShareController::class, 'shareUsers']);
    });



    //阿里物流
    Route::post('alideliver', [\App\Http\Controllers\AliDeliverController::class, 'showapiExpInfo']);
    Route::get('export', [\App\Http\Controllers\ExcelController::class, 'export']); #导出

    //数据同步
    Route::prefix('crmsync/')->group(function () {
        Route::post('so_init', [\App\Http\Controllers\CrmShipTaskController::class, 'soSyncInt'])->withoutMiddleware('require_login');
        Route::post('so_edit', [\App\Http\Controllers\CrmShipTaskController::class, 'soSyncEdit'])->withoutMiddleware('require_login');
        Route::post('gd_order_queue', [\App\Http\Controllers\CrmShipTaskController::class, 'gdOrderQueue'])->withoutMiddleware('require_login');
        Route::post('gd_so_queue', [\App\Http\Controllers\CrmShipTaskController::class, 'gdSoQueue'])->withoutMiddleware('require_login');
        Route::get('so_edit_fill', [\App\Http\Controllers\CrmShipTaskController::class, 'soSyncEditFill'])->withoutMiddleware('require_login');

        Route::post('product_to_internal', [\App\Http\Controllers\CrmShipTaskController::class, 'productToInternal'])->withoutMiddleware('require_login');
        Route::post('product_to_crm', [\App\Http\Controllers\CrmShipTaskController::class, 'productToCrm'])->withoutMiddleware('require_login');
    });


    Route::get('test/create_mapping', [App\Http\Controllers\TestController::class, 'createInterMapping'])->withoutMiddleware('require_login');
    Route::get('test/product_toes', [App\Http\Controllers\TestController::class, 'productES'])->withoutMiddleware('require_login');

    Route::get('test/send', [App\Http\Controllers\TestController::class, 'sendMessage'])->withoutMiddleware('require_login');
    Route::get('test/consume', [App\Http\Controllers\TestController::class, 'consumeMessage'])->withoutMiddleware('require_login');
    Route::get('test/sync', [App\Http\Controllers\TestController::class, 'testSync'])->withoutMiddleware('require_login');
    Route::get('test/pi', [App\Http\Controllers\TestController::class, 'createPIMapping'])->withoutMiddleware('require_login');

    //Route::get('open/consume', [App\Http\Controllers\OpenController::class, 'consumeMessage'])->withoutMiddleware('require_login');

});
