<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class SyncProgressService
{
    private const TTL_SECONDS = 86400;

    /**
     * @return array<string, mixed>
     */
    public function start(string $type, string $label): array
    {
        $activeId = Cache::get($this->activeKey($type));
        if (is_string($activeId)) {
            $active = $this->get($activeId);
            if ($active !== null && in_array($active['status'] ?? null, ['queued', 'running'], true)) {
                return [...$active, 'already_running' => true];
            }
        }

        $state = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'label' => $label,
            'status' => 'queued',
            'current' => 0,
            'total' => null,
            'percentage' => 0,
            'message' => 'Esperando al trabajador de sincronización.',
            'eta_seconds' => null,
            'eta_label' => null,
            'elapsed_seconds' => 0,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => now()->toIso8601String(),
            'result' => null,
            'error' => null,
        ];

        $this->persist($state);
        Cache::put($this->activeKey($type), $state['id'], self::TTL_SECONDS);

        return [...$state, 'already_running' => false];
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $state = Cache::get($this->key($id));

        return is_array($state) ? $state : null;
    }

    /** @return array<string, mixed>|null */
    public function latest(string $type): ?array
    {
        $id = Cache::get($this->lastKey($type));

        return is_string($id) ? $this->get($id) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function dashboard(?string $focusId = null): array
    {
        $states = [];
        if ($focusId !== null && ($focus = $this->get($focusId)) !== null) {
            $states[$focus['id']] = $focus;
        }

        foreach (['iptv', 'iptv_vod', 'stremio'] as $type) {
            if (($state = $this->latest($type)) !== null) {
                $states[$state['id']] = $state;
            }
        }

        return array_values($states);
    }

    public function running(string $id, ?int $total = null, string $message = ''): void
    {
        $this->mutate($id, function (array $state) use ($total, $message): array {
            $state['status'] = 'running';
            $state['started_at'] ??= now()->toIso8601String();
            if ($total !== null) {
                $state['total'] = max(0, $total);
            }
            if ($message !== '') {
                $state['message'] = $message;
            }

            return $this->recalculate($state);
        });
    }

    public function update(string $id, int $current, ?int $total = null, string $message = ''): void
    {
        $this->mutate($id, function (array $state) use ($current, $total, $message): array {
            $state['status'] = 'running';
            $state['started_at'] ??= now()->toIso8601String();
            $state['current'] = max(0, $current);
            if ($total !== null) {
                $state['total'] = max($state['current'], $total);
            }
            if ($message !== '') {
                $state['message'] = $message;
            }

            return $this->recalculate($state);
        });
    }

    /** @param array<string, mixed> $result */
    public function complete(string $id, array $result = [], string $message = 'Sincronización completada.'): void
    {
        $this->mutate($id, function (array $state) use ($result, $message): array {
            $state['status'] = 'completed';
            $state['started_at'] ??= now()->toIso8601String();
            $state['total'] = max((int) ($state['current'] ?? 0), (int) ($state['total'] ?? 0));
            $state['current'] = $state['total'];
            $state['percentage'] = 100;
            $state['eta_seconds'] = 0;
            $state['eta_label'] = 'Listo';
            $state['message'] = $message;
            $state['finished_at'] = now()->toIso8601String();
            $state['result'] = $result;
            $state['error'] = null;

            return $this->recalculate($state);
        });
    }

    public function fail(string $id, Throwable|string $error): void
    {
        $this->mutate($id, function (array $state) use ($error): array {
            $state['status'] = 'failed';
            $state['finished_at'] = now()->toIso8601String();
            $state['message'] = 'La sincronización terminó con error.';
            $state['error'] = $error instanceof Throwable ? $error->getMessage() : $error;
            $state['eta_seconds'] = null;
            $state['eta_label'] = null;

            return $this->recalculate($state);
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $callback */
    private function mutate(string $id, callable $callback): void
    {
        $state = $this->get($id);
        if ($state === null) {
            return;
        }

        $this->persist($callback($state));
    }

    /** @param array<string, mixed> $state */
    private function recalculate(array $state): array
    {
        $current = max(0, (int) ($state['current'] ?? 0));
        $total = isset($state['total']) && $state['total'] !== null
            ? max($current, (int) $state['total'])
            : null;
        $startedTimestamp = isset($state['started_at'])
            ? CarbonImmutable::parse((string) $state['started_at'])->getTimestamp()
            : microtime(true);
        $elapsed = max(0, (int) round(microtime(true) - $startedTimestamp));

        $state['current'] = $current;
        $state['total'] = $total;
        $state['percentage'] = $total !== null && $total > 0
            ? min(100, (int) round(($current / $total) * 100))
            : ($state['status'] === 'completed' ? 100 : null);
        $state['elapsed_seconds'] = $elapsed;

        if ($total !== null && $current > 0 && $current < $total && $elapsed > 0) {
            $eta = max(0, (int) round(($elapsed / $current) * ($total - $current)));
            $state['eta_seconds'] = $eta;
            $state['eta_label'] = $this->formatDuration($eta);
        } elseif ($state['status'] === 'completed') {
            $state['eta_seconds'] = 0;
            $state['eta_label'] = 'Listo';
        } else {
            $state['eta_seconds'] = null;
            $state['eta_label'] = null;
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function persist(array $state): void
    {
        $state['updated_at'] = now()->toIso8601String();
        Cache::put($this->key((string) $state['id']), $state, self::TTL_SECONDS);
        Cache::put($this->lastKey((string) $state['type']), $state['id'], self::TTL_SECONDS);

        if (in_array($state['status'] ?? null, ['completed', 'failed'], true)) {
            $activeId = Cache::get($this->activeKey((string) $state['type']));
            if ($activeId === $state['id']) {
                Cache::forget($this->activeKey((string) $state['type']));
            }
        }
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return '~'.$seconds.' s';
        }

        if ($seconds < 3600) {
            return '~'.(int) ceil($seconds / 60).' min';
        }

        return '~'.number_format($seconds / 3600, 1, ',', '').' h';
    }

    private function key(string $id): string
    {
        return 'pixflix:sync:progress:'.$id;
    }

    private function activeKey(string $type): string
    {
        return 'pixflix:sync:active:'.$type;
    }

    private function lastKey(string $type): string
    {
        return 'pixflix:sync:last:'.$type;
    }
}
