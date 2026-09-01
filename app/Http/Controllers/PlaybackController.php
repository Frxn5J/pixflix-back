<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\PlaybackResolveRequest;
use App\Http\Requests\ProgressRequest;
use App\Models\Episode;
use App\Models\PlaybackLog;
use App\Models\Title;
use App\Models\WatchProgress;
use App\Services\Catalog\StreamResolver;
use App\Support\UrlSafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackController extends Controller
{
    public function __construct(private readonly StreamResolver $streams) {}

    public function titleStreams(Request $request, string $slug): JsonResponse
    {
        $title = Title::query()->where('slug', $slug)->first();

        if ($title === null) {
            throw new ApiException('not_found', 'El contenido solicitado no existe.', 404);
        }

        if ($title->type !== 'movie') {
            throw new ApiException('validation_error', 'El titulo no es una pelicula.', 422);
        }

        $this->logPlayback($request, 'play', $title, null);

        $streams = $this->streams->titleStreams($title, $request->string('language')->toString() ?: null);

        return response()->json(['data' => $streams]);
    }

    public function episodeStreams(Request $request, int $id): JsonResponse
    {
        $episode = Episode::query()->with('season.title')->find($id);

        if ($episode === null) {
            throw new ApiException('not_found', 'El episodio solicitado no existe.', 404);
        }

        $title = $episode->season?->title()->first();
        $this->logPlayback($request, 'play', $title, $episode);

        $streams = $this->streams->episodeStreams($episode, $request->string('language')->toString() ?: null);

        return response()->json(['data' => $streams]);
    }

    public function resolve(PlaybackResolveRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $title = isset($payload['slug'])
            ? Title::query()->where('slug', (string) $payload['slug'])->first()
            : null;
        $episode = isset($payload['episode_id'])
            ? Episode::query()->with('season.title')->find($payload['episode_id'])
            : null;
        $streams = $this->streams->resolve($payload);

        if ($streams === []) {
            throw new ApiException('not_found', 'No fue posible resolver el contenido.', 404);
        }

        $this->logPlayback($request, 'resolve', $title, $episode);

        return response()->json(['data' => $streams]);
    }

    public function continueWatching(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $items = WatchProgress::query()
            ->where('profile_id', $profile->id)
            ->where('completed', false)
            ->where('percent', '<', 90)
            ->where(function ($q) {
                $q->where('position_sec', '>', 10)->orWhere('percent', '>', 5);
            })
            ->orderByDesc('updated_at')
            ->limit(20)
            ->with(['title', 'episode.season.title'])
            ->get()
            ->map(fn (WatchProgress $p) => $this->progressData($p));

        return response()->json(['data' => $items->values()]);
    }

    public function updateProgress(ProgressRequest $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validated();

        $titleId = $data['title_id'] ?? null;
        $episodeId = $data['episode_id'] ?? null;

        if ($titleId !== null) {
            $title = Title::query()->find($titleId);
            if ($title === null) {
                throw new ApiException('not_found', 'El titulo no existe.', 404);
            }
        }

        if ($episodeId !== null) {
            $episode = Episode::query()->find($episodeId);
            if ($episode === null) {
                throw new ApiException('not_found', 'El episodio no existe.', 404);
            }
            $titleId = $episode->season?->title_id ?? $titleId;
        }

        $position = (int) $data['position_sec'];
        $duration = (int) $data['duration_sec'];
        $percent = $duration > 0 ? min(100, ($position / $duration) * 100) : 0;
        $completed = $percent >= 90 || ($duration > 0 && $position >= $duration - 10);

        $attributes = [
            'position_sec' => $position,
            'duration_sec' => $duration,
            'percent' => round($percent, 2),
            'completed' => $completed,
        ];

        if ($episodeId !== null) {
            $progress = WatchProgress::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'episode_id' => $episodeId],
                [...$attributes, 'title_id' => $titleId, 'season_id' => $episode?->season_id],
            );
        } else {
            $progress = WatchProgress::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'title_id' => $titleId, 'episode_id' => null],
                [...$attributes],
            );
        }

        return response()->json(['data' => $this->progressData($progress->refresh()->load(['title', 'episode.season.title']))]);
    }

    private function profile(Request $request)
    {
        $profileId = $request->header('X-Profile-Id') ?? $request->input('profile_id');
        $subscription = $request->user()?->currentSubscription();

        if ($profileId === null || $subscription === null) {
            throw new ApiException('validation_error', 'Selecciona un perfil para continuar.', 422, ['profile_id' => ['El perfil es requerido.']]);
        }

        $exists = \App\Models\Profile::query()->whereKey((int) $profileId)->exists();

        if (! $exists) {
            throw new ApiException('not_found', 'El perfil no existe.', 404);
        }

        $profile = $subscription->profiles()->whereKey((int) $profileId)->first();

        if ($profile === null) {
            throw new ApiException('forbidden', 'El perfil no pertenece a tu suscripcion.', 403);
        }

        return $profile;
    }

    private function progressData(WatchProgress $p): array
    {
        return [
            'id' => $p->id,
            'profile_id' => $p->profile_id,
            'title_id' => $p->title_id,
            'episode_id' => $p->episode_id,
            'season_id' => $p->season_id,
            'position_sec' => $p->position_sec,
            'duration_sec' => $p->duration_sec,
            'percent' => $p->percent,
            'completed' => $p->completed,
            'title' => $p->title ? ['id' => $p->title->id, 'slug' => $p->title->slug, 'title' => $p->title->title, 'poster' => UrlSafety::http($p->title->poster), 'type' => $p->title->type] : null,
            'episode' => $p->episode ? ['id' => $p->episode->id, 'title' => $p->episode->title, 'number' => $p->episode->number] : null,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function logPlayback(Request $request, string $event, ?Title $title, ?Episode $episode): void
    {
        $profileId = $request->header('X-Profile-Id');
        $subscription = $request->user()?->currentSubscription();
        $profile = $profileId && $subscription ? $subscription->profiles()->whereKey((int) $profileId)->first() : null;

        try {
            PlaybackLog::query()->create([
                'profile_id' => $profile?->id,
                'user_id' => $request->user()?->id,
                'source' => 'principal',
                'event' => $event,
                'title_id' => $title?->id,
                'episode_id' => $episode?->id,
                'quality' => $title?->quality,
                'request_id' => $request->header('X-Request-Id'),
            ]);
        } catch (\Throwable) {
        }
    }
}
