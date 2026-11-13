<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\VideoKitStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoKitController extends Controller
{
    public function __construct(private readonly VideoKitStore $kits) {}

    /**
     * The video kit for the signed-in app. With `?revision=N` equal to the
     * published one, only `{revision, changed: false}` is returned.
     */
    public function show(Request $request): JsonResponse
    {
        $kit = $this->kits->current();
        if ($kit === null) {
            throw new ApiException('video_kit_missing', 'La configuración de vídeo no está disponible.', 404);
        }

        $known = $request->query('revision');
        $data = is_numeric($known) && (int) $known === $kit['revision']
            ? ['revision' => $kit['revision'], 'changed' => false]
            : [...$kit, 'changed' => true];

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->kits->status()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['kit' => ['required', 'string', 'max:1048576']]);

        return response()->json(['data' => $this->kits->publish($validated['kit'])]);
    }
}
