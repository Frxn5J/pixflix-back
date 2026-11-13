<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Keeps the video provider kit that the apps download at startup.
 *
 * The admin uploads the original provider file once. It is sealed here with a
 * fresh AES-256-GCM key, in the same envelope format that the app opens
 * (front/services/provider/kit.ts, front/video-service/kit-envelope.cjs), and
 * stored encrypted with the application key. Each publication bumps `revision`
 * so devices know when to replace what they cached.
 */
class VideoKitStore
{
    private const SETTING = 'video.kit';

    private const AAD = 'pixflix-video-kit:v1';

    public function __construct(private readonly SyncSettings $settings) {}

    /** What the apps need to open the kit, or null if none was published. */
    public function current(): ?array
    {
        $stored = $this->read();
        if ($stored === null) {
            return null;
        }

        return [
            'revision' => $stored['revision'],
            'envelope' => $stored['envelope'],
            'key' => $stored['key'],
        ];
    }

    /** Public facts about the stored kit; never includes the key. */
    public function status(): array
    {
        $stored = $this->read();

        return [
            'configured' => $stored !== null,
            'revision' => $stored['revision'] ?? 0,
            'updated_at' => $stored['updated_at'] ?? null,
        ];
    }

    /** Seals the original provider file and publishes it as the next revision. */
    public function publish(string $original): array
    {
        $original = trim($original);
        if ($original === '') {
            throw new RuntimeException('El archivo del proveedor está vacío.');
        }

        $secret = random_bytes(32);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($original, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('No se pudo cifrar el archivo del proveedor.');
        }

        $stored = [
            'revision' => ($this->read()['revision'] ?? 0) + 1,
            'envelope' => [
                'version' => 1,
                'nonce' => base64_encode($nonce),
                'tag' => base64_encode($tag),
                'ciphertext' => base64_encode($ciphertext),
            ],
            'key' => base64_encode($secret),
            'updated_at' => now()->toIso8601String(),
        ];
        $this->settings->put(self::SETTING, Crypt::encryptString(json_encode($stored, JSON_THROW_ON_ERROR)));

        return $this->status();
    }

    private function read(): ?array
    {
        $value = $this->settings->get(self::SETTING);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $stored = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($stored)
            && is_int($stored['revision'] ?? null)
            && is_array($stored['envelope'] ?? null)
            && is_string($stored['key'] ?? null)
            ? $stored
            : null;
    }
}
