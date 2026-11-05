<?php

namespace App\Providers;

use App\Support\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->tuneSqlite();
        $this->guardOutboundRequests();
    }

    /**
     * Every server-side HTTP request, including each redirect hop, must target
     * a public address. URLs come from admin settings and third-party
     * playlists, so they are never trusted.
     */
    private function guardOutboundRequests(): void
    {
        $assertAllowed = static function (string $url): void {
            if (! OutboundUrlGuard::isAllowed($url)) {
                throw new ConnectionException('Destino no permitido para solicitudes salientes.');
            }
        };

        Http::globalRequestMiddleware(
            static function (RequestInterface $request) use ($assertAllowed): RequestInterface {
                $assertAllowed((string) $request->getUri());

                return $request;
            },
        );

        Http::globalOptions([
            'allow_redirects' => [
                'max' => 3,
                'protocols' => ['http', 'https'],
                'on_redirect' => static function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri,
                ) use ($assertAllowed): void {
                    $assertAllowed((string) $uri);
                },
            ],
        ]);
    }

    /**
     * WAL lets readers and one writer work concurrently, and the busy timeout
     * turns "database is locked" errors into short waits. Both are no-ops on
     * in-memory databases, so the test-suite is unaffected.
     */
    private function tuneSqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        try {
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA synchronous=NORMAL');
            DB::statement('PRAGMA busy_timeout=5000');
        } catch (Throwable) {
            // Best effort: a read-only or exotic database file must not boot-fail the app.
        }
    }
}
