<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TrialController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);
        $plainPassword = Str::random(12);
        $username = $this->uniqueUsername();
        $expiresAt = now()->addHour();
        $creator = $request->user();
        $planId = config('pixflix.trial.plan_id');

        if ($planId !== null && ! Plan::query()->whereKey($planId)->exists()) {
            throw new ApiException('validation_error', 'El plan de prueba no existe.', 422);
        }

        $user = User::query()->create([
            'name' => $validated['name'] ?? 'Cuenta de prueba',
            'username' => $username,
            'password' => Hash::make($plainPassword),
            'role' => 'subscriber',
        ]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $planId,
            'status' => 'trial',
            'is_trial' => true,
            'trial_expires_at' => $expiresAt,
            'starts_at' => now(),
            'ends_at' => $expiresAt,
            'group_number' => 1,
            'created_by' => $creator?->id,
            'whatsapp_ref' => $validated['label'] ?? null,
        ]);

        return response()->json(['data' => [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'username' => $username,
            'password' => $plainPassword,
            'expires_at' => $expiresAt->toIso8601String(),
        ]], 201);
    }

    private function uniqueUsername(): string
    {
        do {
            $username = 'trial_'.Str::lower(Str::random(8));
        } while (User::query()->where('username', $username)->exists());

        return $username;
    }
}
