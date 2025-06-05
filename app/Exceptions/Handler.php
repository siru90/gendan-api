<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    use \App\Utils\OkJson;

    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
        });
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            [$code,] = \App\Ok\SysError::METHOD_NOT_ALLOWED;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            [$code,] = \App\Ok\SysError::HTTP_NOT_FOUND;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
            [$code,] = \App\Ok\SysError::HTTP_TOO_MANY_REQUESTS;
            $message = $e->getMessage();
            return $this->renderErrorJson($code, $message);
        }
        return parent::render($request, $e);
    }
}
