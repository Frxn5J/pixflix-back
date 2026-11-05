<?php

namespace App\Rules;

use App\Support\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! OutboundUrlGuard::isAllowed($value)) {
            $fail('La URL debe apuntar a un servidor publico, no a una direccion interna.');
        }
    }
}
