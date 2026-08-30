<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['correction_reason' => 'required|string|max:500', 'replacement_value' => 'nullable|numeric|min:0', 'replacement_notes' => 'nullable|string', 'client_request_id' => 'nullable|uuid'];
    }
}
