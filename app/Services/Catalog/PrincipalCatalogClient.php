<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogUpstreamException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PrincipalCatalogClient
{
    public function list(string $type, int $page): array
    {
        return $this->get('/list', [
            'type' => $type,
            'page' => $page,
        ]);
    }

    public function extract(string $url): array
    {
        return $this->get('/extract', ['url' => $this->extractTarget($url)]);
    }

    private function get(string $path, array $query): array
    {
        $errors = [];

        foreach ($this->bases() as $base) {
            if ($this->circuitOpen($base)) {
                $errors[] = "{$base}: circuit open";

                continue;
            }

            try {
                $response = $this->requestBase($base, $path, $query);
                $this->resetCircuit($base);

                return $response;
            } catch (Throwable $error) {
                $this->recordFailure($base);
                \Log::warning('Catalog upstream failed', [
                    'base' => $base,
                    'path' => $path,
                    'error' => $error->getMessage(),
                ]);
                $errors[] = "{$base}: {$error->getMessage()}";
            }
        }

        throw new CatalogUpstreamException(
            'No fue posible sincronizar el catalogo: '.implode('; ', $errors),
        );
    }

    private function requestBase(string $base, string $path, array $query): array
    {
        $attempts = max(1, (int) config('pixflix.catalog.retry_attempts', 3));
        $delays = config('pixflix.catalog.retry_delays_ms', [2000, 8000, 30000]);
        $lastStatus = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                \Log::debug('Catalog upstream request', [
                    'base' => $base,
                    'path' => $path,
                    'attempt' => $attempt + 1,
                ]);

                $response = Http::baseUrl($base)
                    ->withOptions(['verify' => (bool) config('pixflix.catalog.verify_ssl', true)])
                    ->timeout(config('pixflix.catalog.timeout_ms', 8000) / 1000)
                    ->acceptJson()
                    ->get($path, $query);

                if ($response->successful()) {
                    $payload = $response->json();

                    if (! is_array($payload)) {
                        throw new CatalogUpstreamException('El proveedor devolvio un JSON invalido.');
                    }

                    \Log::debug('Catalog upstream response', [
                        'base' => $base,
                        'path' => $path,
                        'status' => $response->status(),
                        'keys' => array_keys($payload),
                    ]);

                    return $payload;
                }

                $lastStatus = $response->status();

                if (! $this->retryable($lastStatus)) {
                    break;
                }
            } catch (ConnectionException $error) {
                if ($attempt === $attempts - 1) {
                    throw $error;
                }
            }

            $delay = (int) ($delays[$attempt] ?? end($delays) ?: 0);
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        throw new CatalogUpstreamException(
            "El proveedor devolvio un error para {$path}.",
            $lastStatus,
        );
    }

    private function bases(): array
    {
        return array_values(array_unique(array_filter([
            config('pixflix.catalog.primary_url'),
            config('pixflix.catalog.fallback_url'),
        ])));
    }

    private function retryable(?int $status): bool
    {
        return $status === 429 || ($status !== null && $status >= 500);
    }

    private function circuitOpen(string $base): bool
    {
        return Cache::has($this->circuitKey($base).':open');
    }

    private function recordFailure(string $base): void
    {
        $key = $this->circuitKey($base).':failures';
        $failures = (int) Cache::increment($key);

        if ($failures >= max(1, (int) config('pixflix.catalog.circuit_threshold', 3))) {
            Cache::put(
                $this->circuitKey($base).':open',
                true,
                max(1, (int) config('pixflix.catalog.circuit_cooldown_seconds', 300)),
            );
            Cache::forget($key);
        }
    }

    private function resetCircuit(string $base): void
    {
        Cache::forget($this->circuitKey($base).':failures');
        Cache::forget($this->circuitKey($base).':open');
    }

    private function circuitKey(string $base): string
    {
        return 'pixflix:catalog:circuit:'.sha1($base);
    }

    private function extractTarget(string $url): string
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (is_string($path) && basename(trim($path, '/')) === 'extract') {
            parse_str((string) ($parts['query'] ?? ''), $query);

            if (isset($query['url']) && is_string($query['url']) && $query['url'] !== '') {
                return $query['url'];
            }
        }

        return $url;
    }
}
