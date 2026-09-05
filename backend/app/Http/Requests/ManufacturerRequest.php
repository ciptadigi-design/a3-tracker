<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManufacturerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'notes' => 'nullable|string', 'account_id' => 'nullable|uuid'];
    }
}
