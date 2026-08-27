<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_health_uses_the_versioned_api_contract(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'service', 'version'],
                'meta',
                'links',
            ])
            ->assertJsonPath('data.status', 'ok')
            ->assertHeader('X-Request-Id');
    }

    public function test_health_preserves_a_valid_request_id(): void
    {
        $response = $this->withHeader('X-Request-Id', 'contract-test-01')
            ->getJson('/api/v1/health');

        $response->assertHeader('X-Request-Id', 'contract-test-01');
    }

    public function test_api_errors_use_the_canonical_error_contract(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response
            ->assertNotFound()
            ->assertJsonStructure(['error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'not_found');
    }
}
