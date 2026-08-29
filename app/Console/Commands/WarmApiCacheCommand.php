<?php

namespace App\Console\Commands;

use App\Jobs\WarmApiCacheJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmApiCacheCommand extends Command
{
    protected $signature = 'pixflix:warm-api-cache
                            {--force : Calentar de nuevo aunque ya se haya completado}
                            {--sync : Ejecutar ahora en este proceso, sin encolar}';

    protected $description = 'Calienta las respuestas de los endpoints principales de la API';

    public function handle(): int
    {
        if ($this->option('sync')) {
            WarmApiCacheJob::dispatchSync((bool) $this->option('force'));
            $this->info('Cache de API calentado.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('pixflix:cache:warmup:dispatch', 60);

        if (! $lock->get()) {
            $this->line('El calentamiento de cache ya esta siendo encolado.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('force') && Cache::has('pixflix:cache:warmup:completed')) {
                $this->line('El cache de API ya fue calentado anteriormente.');

                return self::SUCCESS;
            }

            WarmApiCacheJob::dispatch((bool) $this->option('force'));
            $this->info('Calentamiento de cache encolado para ejecutarse en segundo plano.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
