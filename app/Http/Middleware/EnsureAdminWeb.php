<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'agent'], true), 403, 'Solo personal autorizado puede acceder a este panel.');

        return $next($request);
    }
}
