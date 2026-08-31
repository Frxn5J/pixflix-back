<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RefreshSanctumToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $now = now();
            $threshold = $now->copy()->addDays(7);
            if ($accessToken->expires_at === null || $accessToken->expires_at->lte($threshold)) {
                $accessToken->forceFill([
                    'expires_at' => $now->addMinutes(
                        (int) config('pixflix.auth_token_lifetime_minutes', 43200)
                    ),
                ])->saveQuietly();
            }
        }

        return $next($request);
    }
}
