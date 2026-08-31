<?php

namespace App\Services\IptvOrg;

use App\Support\UrlSafety;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IptvOrgClient
{
    /**
     * Descarga y normaliza las entradas de una playlist M3U extendida.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(?string $playlistUrl = null): array
    {
        $body = $this->downloadPlaylist($playlistUrl);
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $entries = [];
        $pending = null;
        $headers = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXTINF:')) {
                $pending = $this->parseExtInf(substr($line, 8));
                $headers = [];
                foreach (['http-user-agent' => 'User-Agent', 'http-referrer' => 'Referer'] as $attribute => $header) {
                    if (isset($pending[$attribute]) && $pending[$attribute] !== '') {
                        $headers[$header] = $pending[$attribute];
                    }
                }

                continue;
            }

            if (str_starts_with($line, '#EXTVLCOPT:') && $pending !== null) {
                [$option, $value] = array_pad(explode('=', substr($line, 11), 2), 2, '');
                if (in_array($option, ['http-user-agent', 'http-referrer'], true) && $value !== '') {
                    $headers[$option === 'http-user-agent' ? 'User-Agent' : 'Referer'] = trim($value);
                }

                continue;
            }

            if ($pending === null || str_starts_with($line, '#')) {
                continue;
            }

            if (! filter_var($line, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($line, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $pending = null;
                $headers = [];

                continue;
            }

            $entries[] = [
                'name' => $pending['name'],
                'external_id' => $pending['external_id'],
                'logo' => $this->validUrl($pending['tvg-logo'] ?? null),
                'category' => $this->firstGroup($pending['group-title'] ?? null),
                'country' => $this->country($pending),
                'language' => $this->clean($pending['tvg-language'] ?? null),
                'stream_url' => $line,
                'stream_headers' => $headers,
            ];
            $pending = null;
            $headers = [];
        }

        return $entries;
    }

    private function downloadPlaylist(?string $playlistUrl = null): string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Pixflix/1.0 M3U playlist sync'])
                ->withOptions(['verify' => (bool) config('pixflix.iptv.verify_ssl', true)])
                ->timeout((int) config('pixflix.iptv.timeout_seconds', 30))
                ->accept('*/*')
                ->get($playlistUrl ?: (string) config('pixflix.iptv.playlist_url'));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('No fue posible descargar la lista M3U configurada.', 0, $exception);
        }

        $body = $response->body();
        if (! $response->successful() || ! str_contains($body, '#EXTINF:')) {
            throw new RuntimeException('La lista M3U configurada es invalida o esta vacia.');
        }

        return $body;
    }

    /** @return array<string, string> */
    private function parseExtInf(string $value): array
    {
        $attributes = [];
        preg_match_all('/([a-zA-Z0-9_-]+)="((?:\\.|[^"])*)"/', $value, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attributes[$match[1]] = stripcslashes($match[2]);
        }

        $comma = strpos($value, ',');
        $name = $comma === false ? ($attributes['tvg-name'] ?? 'Canal sin nombre') : trim(substr($value, $comma + 1));
        $name = $name !== '' ? $name : ($attributes['tvg-name'] ?? 'Canal sin nombre');
        $id = $this->clean($attributes['tvg-id'] ?? null);
        $attributes['name'] = $name;
        $attributes['external_id'] = $id !== null ? $id : $this->slug($name, $value);

        return $attributes;
    }

    private function firstGroup(?string $value): string
    {
        foreach (explode(';', (string) $value) as $group) {
            $group = trim($group);
            if ($group !== '') {
                return $group;
            }
        }

        return 'General';
    }

    /** @param array<string, string> $entry */
    private function country(array $entry): ?string
    {
        $country = $this->clean($entry['tvg-country'] ?? null);
        if ($country !== null) {
            return strtoupper($country);
        }

        $id = $entry['tvg-id'] ?? '';

        return preg_match('/\.([a-z]{2})(?:@|$)/i', $id, $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function validUrl(?string $value): ?string
    {
        return UrlSafety::http($value);
    }

    private function slug(string $name, string $source): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', trim($name));
        $slug = trim((string) $slug, '-');

        return strtolower($slug ?: 'channel').'-'.substr(sha1($source), 0, 10);
    }
}
