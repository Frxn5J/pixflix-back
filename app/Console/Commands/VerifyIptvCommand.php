<?php

namespace App\Console\Commands;

use App\Services\Iptv\IptvStreamVerifier;
use Illuminate\Console\Command;
use Throwable;

class VerifyIptvCommand extends Command
{
    protected $signature = 'pixflix:verify-iptv
                            {--country= : Filtrar por codigo de pais ISO alpha-2, por ejemplo MX}
                            {--limit= : Limitar el numero de canales revisados}';

    protected $description = 'Verifica manifiestos, segmentos y CORS de los canales IPTV activos';

    public function handle(IptvStreamVerifier $verifier): int
    {
        try {
            $result = $verifier->run(
                $this->option('country') !== null ? (string) $this->option('country') : config('pixflix.iptv.country'),
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] === 'disabled') {
            $this->warn('El verificador IPTV esta desactivado.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Verificacion IPTV: %d revisados, %d saludables, %d desactivados.',
            $result['checked'],
            $result['healthy'],
            $result['deactivated'],
        ));

        foreach ($result['failures'] as $reason => $count) {
            $this->line(sprintf('  %s: %d', $reason, $count));
        }

        return self::SUCCESS;
    }
}
