<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'service' => config('pixflix.service', 'pixflix-api'),
                'version' => config('pixflix.api_version', 'v1'),
            ],
            'meta' => [],
            'links' => [],
        ]);
    }
}
