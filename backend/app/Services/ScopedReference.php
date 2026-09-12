<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ScopedReference
{
    /** Active platform-global or target-account catalog reference; never branch authorization. */
    public static function activeGlobalOrOwned(string $model, ?string $id, ?string $accountId, string $field): void
    {
        if ($id === null) {
            return;
        }
        $row = $model::whereKey($id)->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId))->first();
        if (! $row || ! $row->is_active || ($row->account_id !== null && $row->account_id !== $accountId)) {
            throw ValidationException::withMessages([$field => 'Selected reference is not available in this scope.']);
        }
    }
}
