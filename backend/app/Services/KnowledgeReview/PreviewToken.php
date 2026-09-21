<?php

namespace App\Services\KnowledgeReview;

use Illuminate\Validation\ValidationException;

/**
 * V1.8 - the same stateless, HMAC-signed, short-lived confirmation-token pattern V1.7.2 uses for filter
 * bulk triage (no table, keyed from APP_KEY), factored out so group publishing does not re-implement
 * signing. A distinct `purpose` gives domain separation: a token issued for one operation can never
 * verify for another. Claims are opaque to the client; only the signature matters.
 */
final class PreviewToken
{
    /** @param  array<string, mixed>  $claims */
    public static function issue(string $purpose, array $claims, int $ttlSeconds): string
    {
        $claims = ['v' => 1, 'p' => $purpose, 'exp' => now()->timestamp + $ttlSeconds] + $claims;
        $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, self::key($purpose));
    }

    /**
     * Verifies the signature and purpose only. Binding checks (import, actor, content...) and expiry are the
     * caller's job, because expiry must be evaluated AFTER the idempotency check.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $purpose, string $token, string $field = 'confirmation_token'): array
    {
        $invalid = fn () => ValidationException::withMessages([$field => '[INVALID_CONFIRMATION_TOKEN] The confirmation is invalid. Preview again before publishing.']);
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $invalid();
        }
        if (! hash_equals(hash_hmac('sha256', $parts[0], self::key($purpose)), $parts[1])) {
            throw $invalid();
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($claims) || ($claims['v'] ?? null) !== 1 || ($claims['p'] ?? null) !== $purpose) {
            throw $invalid();
        }

        return $claims;
    }

    public static function isExpired(array $claims): bool
    {
        return (int) ($claims['exp'] ?? 0) < now()->timestamp;
    }

    private static function key(string $purpose): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required to sign confirmations.');
        }

        return hash('sha256', 'knowledge-preview-token|'.$purpose.'|'.$key);
    }
}
