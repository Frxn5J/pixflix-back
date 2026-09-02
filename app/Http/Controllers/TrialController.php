<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            throw ValidationException::withMessages([
                'plan_id' => 'El plan de prueba configurado no existe. Configura un plan válido o deja la suscripción de prueba sin plan.',
            ]);
        }
        // If trial plan is not configured, fall back to first active plan or null
        if ($planId === null) {
            $planId = Plan::query()->where('is_active', true)->orderBy('id')->value('id');
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
        } while (User::query()
            ->where(function ($query) use ($username): void {
                $query->where('email', $username)
                    ->orWhere('phone', $username)
                    ->orWhere('username', $username);
            })
            ->exists());

        return $username;
    }
}
