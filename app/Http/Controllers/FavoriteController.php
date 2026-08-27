<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\TitleResource;
use App\Models\Favorite;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\Title;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request, int $profileId): JsonResponse
    {
        $profile = $this->ownedProfile($request, $profileId);
        $titles = $profile->favorites()
            ->with('title')
            ->latest('favorites.id')
            ->get()
            ->map(fn (Favorite $favorite) => TitleResource::make($favorite->title)->resolve())
            ->values();

        return response()->json(['data' => $titles]);
    }

    public function store(Request $request, int $profileId): JsonResponse
    {
        $profile = $this->ownedProfile($request, $profileId);
        $validated = $request->validate([
            'title_id' => ['required', 'integer', 'exists:titles,id'],
        ]);
        $title = Title::query()->findOrFail($validated['title_id']);

        $favorite = Favorite::query()->firstOrCreate([
            'profile_id' => $profile->id,
            'title_id' => $title->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $favorite->id,
                'profile_id' => $profile->id,
                'title_id' => $title->id,
                'is_favorite' => true,
            ],
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $profileId, int $titleId): JsonResponse
    {
        $profile = $this->ownedProfile($request, $profileId);
        $deleted = Favorite::query()
            ->where('profile_id', $profile->id)
            ->where('title_id', $titleId)
            ->delete();

        return response()->json(['data' => [
            'deleted' => $deleted > 0,
            'profile_id' => $profile->id,
            'title_id' => $titleId,
        ]]);
    }

    private function ownedProfile(Request $request, int $profileId): Profile
    {
        $subscription = $request->user()?->currentSubscription();
        $profile = $subscription?->profiles()->whereKey($profileId)->first();

        if ($profile === null) {
            throw new ApiException('not_found', 'El perfil solicitado no existe.', 404);
        }

        return $profile;
    }
}
