<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class IdentityInput
{
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    public static function ensureAvailable(string $field, string $value, string $except): void
    {
        // Database collation remains authoritative (including MySQL accent folding).
        if (User::whereRaw('lower('.$field.') = ?', [self::normalize($value)])->where('id', '<>', $except)->exists()) {
            throw ValidationException::withMessages([$field => 'This identity value is unavailable.']);
        }
    }
}
