<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetDatabase
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $region = $request->header('region');
        if ($region === "US" && env('DB_HOST_US')) {
            config( ['database.connections.mysql.host' => env('DB_HOST_US')] );
            config( ['database.connections.mysql_master.host' => env('DB_HOST_US')] );
            config( ['database.connections.mysql_external.host' => env('DB_EXTERNAL_HOST_US')] );
        }
        elseif ($region ===  "DE" && env('DB_HOST_DE')) {
            config( ['database.connections.mysql.host' => env('DB_HOST_DE')] );
            config( ['database.connections.mysql_master.host' => env('DB_HOST_DE')] );
            config( ['database.connections.mysql_external.host' => env('DB_EXTERNAL_HOST_DE')] );
        }
        elseif ($region ===  "SG" && env('DB_HOST_SG')) {
            config( ['database.connections.mysql.host' => env('DB_HOST_SG')] );
            config( ['database.connections.mysql_master.host' => env('DB_HOST_SG')] );
            config( ['database.connections.mysql_external.host' => env('DB_EXTERNAL_HOST_SG')] );
        }
        elseif ($region ===  "BR" && env('DB_HOST_BR')) {
            config( ['database.connections.mysql.host' => env('DB_HOST_BR')] );
            config( ['database.connections.mysql_master.host' => env('DB_HOST_BR')] );
            config( ['database.connections.mysql_external.host' => env('DB_EXTERNAL_HOST_BR')] );
        }
        else{
            config( ['database.connections.mysql.host' => env('DB_HOST')] );
            config( ['database.connections.mysql_master.host' => env('DB_HOST')] );
            config( ['database.connections.mysql_external.host' => env('DB_EXTERNAL_HOST')] );
        }
        //var_dump(config("database"));

        return $next($request);
    }
}
