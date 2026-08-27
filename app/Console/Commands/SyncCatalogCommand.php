<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogSyncService;
use Illuminate\Console\Command;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class SyncCatalogCommand extends Command
{
    protected $signature = 'pixflix:sync-catalog
                            {--force : Reanudar incluso si el snapshot parece activo}
                            {--restart : Ignorar el snapshot en curso y comenzar uno nuevo}
                            {--clear-lock : Liberar el lock antes de iniciar; usar solo si no hay otra sync activa}
                            {--debug : Mostrar los logs de la sincronizacion en consola}';

    protected $description = 'Sincroniza el catalogo con snapshot versionado y reanudable';

    public function handle(CatalogSyncService $sync): int
    {
        if ($this->option('clear-lock')) {
            Cache::lock('pixflix:sync:catalog')->forceRelease();
            $this->info('Lock de sincronizacion liberado.');
        }

        if ($this->option('debug')) {
            Event::listen(MessageLogged::class, function (MessageLogged $event): void {
                $context = $event->context === []
                    ? ''
                    : ' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->line(sprintf('[%s] %s%s', strtoupper($event->level), $event->message, $context));
            });
        }

        $result = $sync->run(
            'manual',
            (bool) $this->option('force'),
            (bool) $this->option('restart'),
        );

        if ($result['status'] === 'locked') {
            $this->warn($result['message']);

            return self::SUCCESS;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $result['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
