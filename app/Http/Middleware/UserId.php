<?php

namespace App\Http\Middleware;

use App\Utils\OkJson;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserId
{
    use OkJson;

    public static int $user_id = 0;
    public static ?\Throwable $e = null;

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return Response|RedirectResponse|JsonResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('Authorization');
        if (!$token) {
            $token = $request->header('token');
        }
        if (!$token) {
            $token = $request->cookie('token');
        }
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, strlen('Bearer '));
        }
        if (!$token) {
            return $next($request);
        }
        try {
            self::$user_id = \App\Ok\ExtUserId2UserId::getUserIdByExternalToken($token);
        } catch (\Throwable $e) {
            self::$e = $e;
        }
        return $next($request);
    }
}
