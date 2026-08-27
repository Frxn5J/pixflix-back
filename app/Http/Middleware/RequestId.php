<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->header('X-Request-Id');

        if ($requestId === '' || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->headers->set('X-Request-Id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
