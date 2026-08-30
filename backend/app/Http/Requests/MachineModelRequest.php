<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MachineModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['manufacturer_id' => 'required|uuid', 'model_code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'account_id' => 'nullable|uuid'];
    }
}
