<?php

namespace App\Http\Middleware;

use App\Ok\SysError;
use App\Utils\OkJson;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequireLogin
{
    use OkJson;

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return Response|JsonResponse|RedirectResponse|BinaryFileResponse|StreamedResponse
     */
    public function handle(Request $request, Closure $next): Response|JsonResponse|RedirectResponse|BinaryFileResponse|StreamedResponse
    {
        $userId = UserId::$user_id;
        if (!$userId) {
            if (UserId::$e instanceof \Firebase\JWT\ExpiredException) {
                return $this->renderErrorJson(SysError::TOKEN_EXPIRED);
            } elseif (UserId::$e instanceof \Illuminate\Database\QueryException) {
                return $this->renderErrorJson(SysError::SYSTEM_ERROR);
            }
            return $this->renderErrorJson(SysError::PERMISSION_ERROR);
        }
        return $next($request);
    }
}
