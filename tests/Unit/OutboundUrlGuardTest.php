<?php

namespace Tests\Unit;

use App\Support\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutboundUrlGuardTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function test_blocks_internal_destinations(string $url): void
    {
        $this->assertFalse(OutboundUrlGuard::isAllowed($url), $url);
    }

    /** @return array<string, array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback v4' => ['http://127.0.0.1/admin'],
            'loopback name' => ['http://localhost:6379/'],
            'localhost subdomain' => ['http://api.localhost/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 172.16/12' => ['http://172.17.0.1:5432/'],
            'private 192.168/16' => ['http://192.168.1.10/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'carrier-grade nat' => ['http://100.64.0.1/'],
            'unspecified' => ['http://0.0.0.0/'],
            'loopback v6' => ['http://[::1]/'],
            'unique local v6' => ['http://[fd00::1]/'],
            'v4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'internal suffix' => ['http://postgres.internal/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:6379/_PING'],
            'no host' => ['http:///path'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function test_allows_public_destinations(string $url): void
    {
        $this->assertTrue(OutboundUrlGuard::isAllowed($url), $url);
    }

    /** @return array<string, array{0: string}> */
    public static function allowedUrls(): array
    {
        return [
            'public ip' => ['https://8.8.8.8/manifest.json'],
            'public ipv6' => ['https://[2606:4700:4700::1111]/'],
            'reserved test tld never resolves' => ['https://catalog.test/manifest.json'],
            'reserved example tld' => ['https://cdn.example/movie.m3u8'],
        ];
    }

    public function test_private_destinations_can_be_allowed_for_local_development(): void
    {
        $this->assertTrue(OutboundUrlGuard::isAllowed('http://127.0.0.1:8001/', allowPrivate: true));
        $this->assertFalse(OutboundUrlGuard::isAllowed('file:///etc/passwd', allowPrivate: true));
    }
}
