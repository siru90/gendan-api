<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->header('Accept-Language');
        if ($lang) {
            $lang = explode(',', $lang)[0];
            if (str_starts_with(strtolower($lang), 'en')) {
                \Illuminate\Support\Facades\App::setLocale('en');
            }
        }
        return $next($request);
    }
}
