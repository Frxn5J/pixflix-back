<?php

namespace App\Services\Iptv;

use App\Services\IptvOrg\IptvOrgSyncService;
use RuntimeException;
use Throwable;

class IptvResourceSyncService
{
    public function __construct(
        private readonly IptvOrgSyncService $live,
    ) {}

    /** @return array{live: array<string, mixed>|null, errors: array<int, string>} */
    public function run(): array
    {
        $result = ['live' => null, 'errors' => []];

        try {
            $result['live'] = $this->live->run(
                config('pixflix.iptv.country'),
                null,
                config('pixflix.iptv.max_channels'),
            );
        } catch (Throwable $exception) {
            $result['errors'][] = 'Canales: '.$exception->getMessage();
        }

        if ($result['live'] === null && $result['errors'] !== []) {
            throw new RuntimeException(implode(' ', $result['errors']));
        }

        return $result;
    }

}
