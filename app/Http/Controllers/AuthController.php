<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();
        $user = $this->findUserByLogin($login);

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw new ApiException(
                'unauthenticated',
                'Las credenciales no son validas.',
                401,
            );
        }

        $this->ensureAccountCanLogin($user);

        $shouldLoginSession = $request->hasSession()
            && EnsureFrontendRequestsAreStateful::fromFrontend($request);

        if ($shouldLoginSession) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $maxTokens = 5;
        $tokenCount = $user->tokens()->count();

        if ($tokenCount >= $maxTokens) {
            $tokensToDelete = $user->tokens()
                ->oldest('created_at')
                ->oldest('id')
                ->limit($tokenCount - $maxTokens + 1)
                ->pluck('id');

            if ($tokensToDelete->isNotEmpty()) {
                $user->tokens()->whereIn('id', $tokensToDelete)->delete();
            }
        }

        $token = $user->createToken(
            config('pixflix.auth_token_name', 'pwa'),
            ['*'],
            now()->addMinutes((int) config('pixflix.auth_token_lifetime_minutes', 43200)),
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => $this->accountData($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->accountData($request->user())]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw new ApiException(
                'validation_error',
                'La solicitud no es valida.',
                422,
                ['current_password' => ['La contraseña actual no es correcta.']],
            );
        }

        $user->update(['password' => $request->string('password')->toString()]);
        $user->tokens()->delete();

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['updated' => true]]);
    }

    private function ensureAccountCanLogin(User $user): void
    {
        if ($user->isStaff()) {
            return;
        }

        $subscription = $user->currentSubscription();

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
    }

    private function findUserByLogin(string $login): ?User
    {
        $query = User::query();

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $user = $query->where('email', $login)->first();
        } elseif (preg_match('/^\+?[0-9]{7,15}$/', $login) === 1) {
            $user = $query->where('phone', $login)->first();
        } else {
            $user = $query->where('username', $login)->first();
        }

        return $user ?? User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->orWhere('username', $login)
            ->first();
    }

    private function accountData(User $user): array
    {
        $subscription = $user->currentSubscription();
        $plan = $subscription?->plan;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'username' => $user->username,
            'role' => $user->role,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'is_trial' => $subscription->is_trial,
                'access_allowed' => $subscription->allowsAccess(),
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'trial_expires_at' => $subscription->trial_expires_at?->toIso8601String(),
                'group_number' => $subscription->group_number,
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'max_profiles' => $plan->max_profiles,
                    'max_devices' => $plan->max_devices,
                    'max_quality' => $plan->max_quality,
                ] : null,
            ] : null,
            'profiles' => $subscription?->profiles()->latest('id')->get()->map(
                fn (Profile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'avatar_url' => $profile->avatar_url,
                    'is_kids' => $profile->is_kids,
                    'created_at' => $profile->created_at?->toIso8601String(),
                ],
            )->values()->all() ?? [],
            'group_number' => $subscription?->group_number,
            'pwa' => [
                'force_update' => (bool) config('pixflix.pwa.force_update', false),
            ],
        ];
    }
}
