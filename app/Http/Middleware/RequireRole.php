<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            throw new ApiException(
                'forbidden',
                'No tienes permiso para realizar esta accion.',
                403,
            );
        }

        return $next($request);
    }
}
