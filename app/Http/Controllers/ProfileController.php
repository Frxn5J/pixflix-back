<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\ProfileRequest;
use App\Models\Profile;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscription = $this->subscription($request);

        return response()->json([
            'data' => $subscription->profiles()->latest('id')->get()->map(
                fn (Profile $profile) => $this->profileData($profile),
            )->values(),
        ]);
    }

    public function store(ProfileRequest $request): JsonResponse
    {
        $subscription = $this->subscription($request);
        $planLimit = $subscription->plan?->max_profiles ?? 1;

        if ($subscription->profiles()->count() >= $planLimit) {
            throw new ApiException(
                'profile_limit_reached',
                'Alcanzaste el limite de perfiles de tu plan.',
                422,
                ['limit' => $planLimit],
            );
        }

        $this->ensureNameIsAvailable($subscription, $request->string('name')->trim()->toString());
        $profile = $subscription->profiles()->create($this->profileAttributes($request));

        return response()->json(['data' => $this->profileData($profile)], 201);
    }

    public function update(ProfileRequest $request, int $id): JsonResponse
    {
        $subscription = $this->subscription($request);
        $profile = $this->ownedProfile($subscription, $id);

        if ($request->filled('name')) {
            $this->ensureNameIsAvailable(
                $subscription,
                $request->string('name')->trim()->toString(),
                $profile->id,
            );
        }

        $profile->update($this->profileAttributes($request, true));

        return response()->json(['data' => $this->profileData($profile->refresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $profile = $this->ownedProfile($this->subscription($request), $id);
        $profile->delete();

        return response()->json(['data' => ['deleted' => true, 'id' => $id]]);
    }

    private function subscription(Request $request): Subscription
    {
        $subscription = $request->user()?->currentSubscription();

        if ($subscription === null) {
            throw new ApiException(
                'subscription_inactive',
                'La suscripcion no esta activa.',
                403,
            );
        }

        return $subscription;
    }

    private function ownedProfile(Subscription $subscription, int $id): Profile
    {
        $profile = $subscription->profiles()->whereKey($id)->first();

        if ($profile === null) {
            throw new ApiException(
                'not_found',
                'El perfil solicitado no existe.',
                404,
            );
        }

        return $profile;
    }

    private function ensureNameIsAvailable(
        Subscription $subscription,
        string $name,
        ?int $ignoreId = null,
    ): void {
        $query = $subscription->profiles()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->where('profiles.id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw new ApiException(
                'validation_error',
                'La solicitud no es valida.',
                422,
                ['name' => ['Ya existe un perfil con ese nombre.']],
            );
        }
    }

    private function profileAttributes(ProfileRequest $request, bool $isUpdate = false): array
    {
        $attributes = [];

        if (! $isUpdate || $request->has('name')) {
            $attributes['name'] = $request->string('name')->trim()->toString();
        }

        if ($request->has('avatar_url')) {
            $attributes['avatar_url'] = $request->input('avatar_url');
        }

        if ($request->has('is_kids')) {
            $attributes['is_kids'] = $request->boolean('is_kids');
        }

        if ($request->has('pin')) {
            $attributes['pin_hash'] = $request->filled('pin')
                ? Hash::make($request->string('pin')->toString())
                : null;
        }

        return $attributes;
    }

    private function profileData(Profile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'avatar_url' => $profile->avatar_url,
            'is_kids' => $profile->is_kids,
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }
}
