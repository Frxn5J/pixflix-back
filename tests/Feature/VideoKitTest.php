<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VideoKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_publishes_the_kit_and_a_subscriber_can_open_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $original = 'contenido-original-del-proveedor';

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/video-kit', ['kit' => $original])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.revision', 1)
            ->assertJsonMissingPath('data.key');

        $kit = $this->withToken($this->subscriberToken())
            ->getJson('/api/v1/video/kit')
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.revision', 1)
            ->json('data');

        // Same envelope format that the app opens (AES-256-GCM, AAD pixflix-video-kit:v1).
        $this->assertSame(1, $kit['envelope']['version']);
        $plain = openssl_decrypt(
            base64_decode($kit['envelope']['ciphertext']),
            'aes-256-gcm',
            base64_decode($kit['key']),
            OPENSSL_RAW_DATA,
            base64_decode($kit['envelope']['nonce']),
            base64_decode($kit['envelope']['tag']),
            'pixflix-video-kit:v1',
        );
        $this->assertSame($original, $plain);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{43}=$/', $kit['key']);
    }

    public function test_each_publication_uses_a_new_key_and_revision(): void
    {
        $admin = $this->withToken(User::factory()->create(['role' => 'admin'])->createToken('t')->plainTextToken);
        $admin->putJson('/api/v1/admin/video-kit', ['kit' => 'uno'])->assertOk();
        $first = $this->withToken($this->subscriberToken())->getJson('/api/v1/video/kit')->json('data');
        $admin->putJson('/api/v1/admin/video-kit', ['kit' => 'dos'])->assertOk()->assertJsonPath('data.revision', 2);
        $second = $this->withToken($this->subscriberToken())->getJson('/api/v1/video/kit')->json('data');

        $this->assertSame(2, $second['revision']);
        $this->assertNotSame($first['key'], $second['key']);
    }

    public function test_an_up_to_date_device_gets_no_key(): void
    {
        $this->publish('contenido');
        $token = $this->subscriberToken();

        $this->withToken($token)->getJson('/api/v1/video/kit?revision=1')
            ->assertOk()
            ->assertExactJson(['data' => ['revision' => 1, 'changed' => false]]);
        $this->withToken($token)->getJson('/api/v1/video/kit?revision=0')
            ->assertOk()->assertJsonPath('data.changed', true);
    }

    public function test_the_kit_is_stored_encrypted(): void
    {
        $this->publish('contenido-secreto');

        $stored = (string) \DB::table('settings')->where('key', 'video.kit')->value('value');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('contenido-secreto', $stored);
        $this->assertStringNotContainsString('ciphertext', $stored);
    }

    public function test_it_is_not_available_before_publishing(): void
    {
        $this->withToken($this->subscriberToken())->getJson('/api/v1/video/kit')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'video_kit_missing');
    }

    public function test_it_requires_a_session_and_an_active_subscription(): void
    {
        $this->publish('contenido');

        $this->getJson('/api/v1/video/kit')->assertUnauthorized();

        $without = User::factory()->create();
        $this->withToken($without->createToken('t')->plainTextToken)
            ->getJson('/api/v1/video/kit')->assertForbidden();
    }

    public function test_only_admins_can_publish(): void
    {
        foreach (['agent', 'subscriber'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->withToken($user->createToken('t')->plainTextToken)
                ->putJson('/api/v1/admin/video-kit', ['kit' => 'x'])
                ->assertForbidden();
            $this->withToken($user->createToken('t')->plainTextToken)
                ->getJson('/api/v1/admin/video-kit')
                ->assertForbidden();
            app('auth')->forgetGuards();
        }
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/video-kit', ['kit' => '   '])
            ->assertUnprocessable();
    }

    public function test_the_admin_panel_publishes_an_uploaded_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'web')
            ->put('/admin/video-kit', ['kit_file' => UploadedFile::fake()->createWithContent('provider.dat', 'archivo-subido')])
            ->assertRedirect(route('admin.dashboard', ['section' => 'video-kit']))
            ->assertSessionHas('success');

        $this->actingAs($admin, 'web')->get('/admin?section=video-kit')
            ->assertOk()
            ->assertSee('Revisión 1')
            ->assertDontSee('archivo-subido');
        $this->assertTrue((bool) $this->withToken($this->subscriberToken())->getJson('/api/v1/video/kit')->json('data.changed'));
    }

    private function publish(string $original): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withToken($admin->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/video-kit', ['kit' => $original])->assertOk();
        $this->flushHeaders();
        app('auth')->forgetGuards();
    }

    private function subscriberToken(): string
    {
        $user = User::factory()->create();
        Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => Plan::factory()->create()->id]);

        return $user->createToken('t')->plainTextToken;
    }
}
