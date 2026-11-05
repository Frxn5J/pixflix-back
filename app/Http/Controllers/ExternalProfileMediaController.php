<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalProfileMediaController extends Controller
{
    public function favorites(Request $request, int $profileId): JsonResponse
    {
        $this->profile($request, $profileId);
        $rows = DB::table('external_favorites')->where('profile_id', $profileId)->orderByDesc('updated_at')->get();

        return response()->json(['data' => $rows->map(fn ($row) => $this->favoriteData($row))->values()]);
    }

    public function saveFavorite(Request $request, int $profileId): JsonResponse
    {
        $this->profile($request, $profileId);
        $data = $request->validate($this->contentRules());
        $identity = ['profile_id' => $profileId, 'provider' => $data['provider'], 'content_id' => $data['content_id']];
        DB::table('external_favorites')->updateOrInsert($identity, [
            'content' => json_encode($data['content'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $row = DB::table('external_favorites')->where($identity)->first();

        return response()->json(['data' => $this->favoriteData($row)]);
    }

    public function deleteFavorite(Request $request, int $profileId): JsonResponse
    {
        $this->profile($request, $profileId);
        $data = $request->validate(['provider' => ['required', 'string', 'max:40'], 'content_id' => ['required', 'string', 'max:191']]);
        $deleted = DB::table('external_favorites')->where('profile_id', $profileId)->where($data)->delete();

        return response()->json(['data' => ['deleted' => $deleted > 0]]);
    }

    public function progress(Request $request, int $profileId): JsonResponse
    {
        $this->profile($request, $profileId);
        $rows = DB::table('external_watch_progress')->where('profile_id', $profileId)
            ->where('completed', false)->where('percent', '<', 90)
            ->where(fn ($query) => $query->where('position_sec', '>', 10)->orWhere('percent', '>', 5))
            ->orderByDesc('updated_at')->limit(20)->get();

        return response()->json(['data' => $rows->map(fn ($row) => $this->progressData($row))->values()]);
    }

    public function saveProgress(Request $request, int $profileId): JsonResponse
    {
        $this->profile($request, $profileId);
        $data = $request->validate([...$this->contentRules(),
            'episode_id' => ['nullable', 'string', 'max:191'],
            'position_sec' => ['required', 'integer', 'min:0'],
            'duration_sec' => ['required', 'integer', 'min:1'],
        ]);
        $identity = [
            'profile_id' => $profileId, 'provider' => $data['provider'],
            'content_id' => $data['content_id'], 'episode_id' => $data['episode_id'] ?? '',
        ];
        $position = $data['position_sec'];
        $duration = $data['duration_sec'];
        $percent = min(100, ($position / $duration) * 100);
        DB::table('external_watch_progress')->updateOrInsert($identity, [
            'content' => json_encode($data['content'], JSON_THROW_ON_ERROR),
            'position_sec' => $position, 'duration_sec' => $duration,
            'percent' => round($percent, 2),
            'completed' => $percent >= 90 || $position >= $duration - 10,
            'updated_at' => now(),
        ]);
        $row = DB::table('external_watch_progress')->where($identity)->first();

        return response()->json(['data' => $this->progressData($row)]);
    }

    private function contentRules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:40'],
            'content_id' => ['required', 'string', 'max:191'],
            'content' => ['required', 'array:column_id,type,title,poster,episode_title,episode_number'],
            'content.column_id' => ['required', 'string', 'max:191'],
            'content.type' => ['required', 'in:movie,tvshow'],
            'content.title' => ['required', 'string', 'max:255'],
            'content.poster' => ['nullable', 'string', 'max:2048', 'regex:~^(https?://|/api/video/image/(?:[a-f0-9]{48}|v1_[A-Za-z0-9_-]{40,1700})$)~i'],
            'content.episode_title' => ['nullable', 'string', 'max:255'],
            'content.episode_number' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function profile(Request $request, int $profileId): void
    {
        if (! $request->user()?->currentSubscription()?->profiles()->whereKey($profileId)->exists()) {
            throw new ApiException('not_found', 'El perfil solicitado no existe.', 404);
        }
    }

    private function favoriteData(object $row): array
    {
        return ['provider' => $row->provider, 'content_id' => $row->content_id, 'content' => json_decode($row->content, true)];
    }

    private function progressData(object $row): array
    {
        return [...$this->favoriteData($row), 'episode_id' => $row->episode_id ?: null,
            'position_sec' => (int) $row->position_sec, 'duration_sec' => (int) $row->duration_sec,
            'percent' => (float) $row->percent, 'completed' => (bool) $row->completed, 'updated_at' => $row->updated_at];
    }
}
