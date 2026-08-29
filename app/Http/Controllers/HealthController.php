<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    public function liveness(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function readiness(): JsonResponse
    {
        $checks = [
            'database' => 'ok',
            'cache' => 'ok',
        ];

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (Throwable) {
            $checks['database'] = 'failed';
        }

        try {
            $probe = 'pixflix:health:'.bin2hex(random_bytes(8));
            Cache::put($probe, 'ok', 5);
            if (Cache::get($probe) !== 'ok') {
                $checks['cache'] = 'failed';
            }
            Cache::forget($probe);
        } catch (Throwable) {
            $checks['cache'] = 'failed';
        }

        $ready = ! in_array('failed', $checks, true);

        return response()->json([
            'data' => [
                'status' => $ready ? 'ok' : 'unavailable',
                'service' => config('pixflix.service', 'pixflix-api'),
                'version' => config('pixflix.api_version', 'v1'),
                'checks' => $checks,
            ],
            'meta' => [],
            'links' => [],
        ], $ready ? 200 : 503);
    }
}
