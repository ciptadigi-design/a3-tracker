<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['reading_value' => 'required|numeric|min:0', 'observed_at' => 'required|date', 'operator_person_id' => 'required|uuid', 'shift_code' => 'nullable|in:S1,S2', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid'];
    }
}
