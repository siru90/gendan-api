<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WebSocket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket:9503';  # php artisan websocket:9503 守护进程执行

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Websocket 9503';

    private ?\Swoole\WebSocket\Server $ws = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        \Swoole\Runtime::enableCoroutine($flags = SWOOLE_HOOK_ALL);

        //创建WebSocket Server对象，监听0.0.0.0:9503端口。
        $this->ws = new \Swoole\WebSocket\Server('0.0.0.0', 9503);

        //监听WebSocket连接打开事件。
        $this->ws->on('Open', function ($ws, $request) {
            $ws->push($request->fd, "hello, welcome, fd: {$request->fd}\n");
        });

        //监听WebSocket消息事件。
        $this->ws->on('Message', function (\Swoole\WebSocket\Server $ws, $frame) {
            if (str_starts_with($frame->data, 'Login: ')) {
                \Swoole\Coroutine::create(function () use (&$frame, &$ws) {
                    [$result, $userId] = $this->wsLogin($frame);
                    $ws->push($frame->fd, $result);

                    if ($userId) {
                        while (true) {
                            #订阅redis消息
                            $data = \App\Services\MessageService::getInstance()->syncSubscribe($userId);

                            #需要先判断是否是正确的websocket连接，否则有可能会push失败
                            if ($ws->isEstablished($frame->fd) && $ws->exist($frame->fd)) {
                                $ws->push($frame->fd, $data);
                            } else {
                                break;
                            }

                        }
                    }
                });
            }else{
                $this->ws->push($frame->fd, "b server: {$frame->data}");
            }
            //
        });

        //监听WebSocket连接关闭事件。
        $this->ws->on('Close', function (\Swoole\WebSocket\Server $ws, $fd){
            echo date('Y-m-d H:i:s'), " client-{$fd} is closed\n";
            $ws->stop();
        });

        $this->ws->start();

    }


    //判断ws用户是否登录
    public function wsLogin($frame): array
    {
        $token = substr($frame->data, strlen('Login: '));
        if (!$token) {
            return ["JWT can't empty.", null];
        }
        try {
            $userId = \App\Ok\ExtUserId2UserId::getUserIdByExternalToken($token);
            return [sprintf("Login success. UserId: %s", $userId), $userId];
        } catch (\Throwable $e) {
            return ["JWT expired. " . $e->getMessage(), null];
        }
    }

}
