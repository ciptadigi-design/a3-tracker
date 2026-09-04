<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationalPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:160', 'code' => 'nullable|string|max:64', 'linked_user_id' => 'nullable|uuid', 'is_active' => 'sometimes|boolean'];
    }
}
