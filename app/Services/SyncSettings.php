<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("pixflix:setting:{$key}", 300, function () use ($key, $default): mixed {
            try {
                $setting = Setting::query()->where('key', $key)->value('value');
            } catch (Throwable) {
                return config("pixflix.{$key}", $default);
            }

            if ($setting === null) {
                return config("pixflix.{$key}", $default);
            }

            $decoded = json_decode($setting, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting;
        });
    }

    public function put(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)],
        );
        Cache::forget("pixflix:setting:{$key}");
    }
}
