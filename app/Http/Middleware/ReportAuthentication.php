<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('reports.pajak_daerah.auth') && ! $request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
