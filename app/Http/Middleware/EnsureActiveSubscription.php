<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isStaff()) {
            return $next($request);
        }

        $subscription = $user?->currentSubscription();

        if ($subscription?->isTrialExpired()) {
            throw new ApiException(
                'trial_expired',
                'El periodo de prueba ha terminado.',
                403,
            );
        }

        if ($subscription === null || ! $subscription->allowsAccess()) {
            throw new ApiException(
                'subscription_inactive',
                'La suscripcion no esta activa.',
                403,
            );
        }

        return $next($request);
    }
}
