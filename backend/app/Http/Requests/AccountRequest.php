<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return ['code' => [$required, 'string', 'max:64'], 'name' => [$required, 'string', 'max:120'], 'default_timezone' => [$required, 'timezone:all'], 'default_currency' => 'sometimes|in:IDR', 'status' => 'sometimes|in:active,suspended,archived', 'notes' => 'nullable|string'];
    }
}
