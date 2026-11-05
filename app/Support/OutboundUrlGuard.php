<?php

namespace App\Support;

/**
 * Blocks server-side requests to internal destinations (SSRF).
 *
 * Admins and agents configure playlist, proxy and addon URLs that the backend
 * downloads. Without this guard those URLs could target the database, the
 * cache, other containers or the cloud metadata service.
 */
final class OutboundUrlGuard
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128',
        '::1/128',
        '64:ff9b::/96',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /** Names that never resolve publicly, so DNS is not queried for them. */
    private const NEVER_RESOLVING_SUFFIXES = ['.test', '.example', '.invalid'];

    private const INTERNAL_SUFFIXES = ['.localhost', '.internal', '.local', '.lan', '.home.arpa'];

    /** @var array<string, array<int, string>> */
    private static array $resolved = [];

    public static function isAllowed(string $url, ?bool $allowPrivate = null): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            return false;
        }

        if ($allowPrivate ?? (bool) config('pixflix.security.allow_private_urls', false)) {
            return true;
        }

        if (self::isIpLiteral($host)) {
            return self::isPublicIp($host);
        }

        if ($host === 'localhost' || self::endsWithAny($host, self::INTERNAL_SUFFIXES)) {
            return false;
        }

        if (self::endsWithAny($host, self::NEVER_RESOLVING_SUFFIXES)) {
            return true;
        }

        $addresses = self::resolve($host);

        // A name that does not resolve cannot be reached, so the request fails
        // on its own. Every address it resolves to must be public.
        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $ranges = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($ranges as $range) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** @param array<int, string> $suffixes */
    private static function endsWithAny(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        if (isset(self::$resolved[$host])) {
            return self::$resolved[$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return self::$resolved[$host] = $addresses;
    }

    private static function inRange(string $packedIp, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $packedNetwork = inet_pton($network);

        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (substr($packedIp, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;

        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }
}
