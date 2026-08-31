<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Throwable;

class StremioAddonVerifier
{
    public function verify(string $url, int $timeoutSeconds = 10): array
    {
        $manifestUrl = $this->manifestUrl($url);

        try {
            $response = Http::acceptJson()
                ->timeout(max(1, min(60, $timeoutSeconds)))
                ->get($manifestUrl);
        } catch (Throwable $error) {
            return $this->failure($manifestUrl, 'No fue posible conectar con el manifest.', $error->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure(
                $manifestUrl,
                "El manifest respondió con HTTP {$response->status()}.",
            );
        }

        $manifest = $response->json();

        if (! is_array($manifest)) {
            return $this->failure($manifestUrl, 'El manifest no contiene JSON válido.');
        }

        $errors = [];
        $warnings = [];
        foreach (['id', 'name', 'version'] as $field) {
            if (! is_string($manifest[$field] ?? null) || trim($manifest[$field]) === '') {
                $errors[] = "Falta el campo requerido {$field}.";
            }
        }

        if (! is_array($manifest['resources'] ?? null)) {
            $errors[] = 'Falta resources o no es una lista.';
        }

        if (! is_array($manifest['types'] ?? null)) {
            $errors[] = 'Falta types o no es una lista.';
        }

        $resources = $this->resourceNames($manifest['resources'] ?? []);
        $types = $this->stringValues($manifest['types'] ?? []);
        $catalogs = is_array($manifest['catalogs'] ?? null) ? $manifest['catalogs'] : [];
        $catalogTypes = collect($catalogs)
            ->filter(fn ($catalog): bool => is_array($catalog))
            ->map(fn (array $catalog): string => trim((string) ($catalog['type'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $supportedTypes = array_values(array_unique([...$types, ...$catalogTypes]));
        $hasStream = in_array('stream', $resources, true);
        $hasCatalog = in_array('catalog', $resources, true) || $catalogs !== [];
        $contentTypes = array_values(array_intersect($supportedTypes, ['movie', 'series', 'channel', 'tv']));
        $spanishSignal = $this->hasSpanishSignal($manifest, $catalogs);

        if (! $hasStream) {
            $warnings[] = 'No declara el recurso stream; no podrá resolver reproducción.';
        }

        if (! $hasCatalog) {
            $warnings[] = 'No declara catálogos; solo se podrá usar si recibe IDs externos compatibles.';
        }

        if ($contentTypes === []) {
            $warnings[] = 'No declara tipos de contenido compatibles con Pixflix.';
        }

        return [
            'valid' => $errors === [],
            'compatible' => $errors === [] && $hasStream && $contentTypes !== [],
            'reachable' => true,
            'manifest_url' => $manifestUrl,
            'manifest' => [
                'id' => $manifest['id'] ?? null,
                'name' => $manifest['name'] ?? null,
                'version' => $manifest['version'] ?? null,
            ],
            'resources' => $resources,
            'types' => $supportedTypes,
            'content_types' => $contentTypes,
            'catalogs' => count($catalogs),
            'spanish_signal' => $spanishSignal,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function manifestUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            if (preg_match('#/manifest(?:\.json)?$#i', $url)) {
                return preg_replace('#/manifest$#i', '/manifest.json', $url) ?: $url;
            }

            return rtrim($url, '/').'/manifest.json';
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/manifest(?:\.json)?$#i', $path)) {
            $path = preg_replace('#/manifest$#i', '/manifest.json', $path) ?: $path;
        } else {
            $path = rtrim($path, '/').'/manifest.json';
        }

        return $this->composeUrl($parts, $path);
    }

    /** @param array<string, mixed> $parts */
    private function composeUrl(array $parts, string $path): string
    {
        $authority = (string) $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $parts['scheme'].'://'.$authority.$path
            .(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    private function resourceNames(array $resources): array
    {
        return collect($resources)
            ->map(fn ($resource): string => is_string($resource)
                ? trim($resource)
                : (is_array($resource) ? trim((string) ($resource['name'] ?? '')) : ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function stringValues(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value): string => is_string($value) ? trim($value) : '',
            $values,
        ))));
    }

    private function hasSpanishSignal(array $manifest, array $catalogs): bool
    {
        $serialized = json_encode([
            'manifest' => $manifest,
            'catalogs' => $catalogs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return preg_match('/latino|español|espanol|spanish|castellano|es-419/i', $serialized) === 1;
    }

    private function failure(string $manifestUrl, string $message, ?string $technicalError = null): array
    {
        return [
            'valid' => false,
            'compatible' => false,
            'reachable' => false,
            'manifest_url' => $manifestUrl,
            'manifest' => null,
            'resources' => [],
            'types' => [],
            'content_types' => [],
            'catalogs' => 0,
            'spanish_signal' => false,
            'errors' => [$message],
            'warnings' => [],
            'technical_error' => $technicalError,
        ];
    }
}
