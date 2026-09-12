<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Field comparison only; each domain explicitly supplies its resource and request fields. */
class ReplayFields
{
    public static function match(object $stored, array $expected, array $numbers = [], array $dates = []): void
    {
        foreach ($expected as $field => $value) {
            $actual = $stored->{$field} ?? null;
            $same = $actual === null || $value === null ? $actual === $value : (string) $actual === (string) $value;
            if ($actual !== null && $value !== null && in_array($field, $numbers, true)) {
                $same = (float) $actual === (float) $value;
            }
            if ($actual !== null && $value !== null && in_array($field, $dates, true)) {
                $same = Carbon::parse($actual)->format('Y-m-d H:i:s') === Carbon::parse($value)->format('Y-m-d H:i:s');
            }
            if (! $same) {
                throw new ConflictHttpException('Request key conflicts with another resource or payload.');
            }
        }
    }
}
