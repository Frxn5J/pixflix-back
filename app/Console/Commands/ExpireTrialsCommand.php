<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireTrialsCommand extends Command
{
    protected $signature = 'pixflix:expire-trials';

    protected $description = 'Expira cuentas de prueba vencidas y revoca sus tokens';

    public function handle(): int
    {
        $expired = Subscription::query()
            ->with('user')
            ->where('is_trial', true)
            ->where('status', 'trial')
            ->whereNotNull('trial_expires_at')
            ->where('trial_expires_at', '<=', now())
            ->get();

        foreach ($expired as $subscription) {
            $subscription->update(['status' => 'expired', 'ends_at' => $subscription->trial_expires_at]);
            $subscription->user?->tokens()->delete();
        }

        $this->info("Trials expirados: {$expired->count()}");

        return self::SUCCESS;
    }
}
