<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['machine_model_id' => 'required|uuid', 'machine_code' => 'required|string|max:80', 'display_name' => 'required|string|max:180', 'serial_number' => 'nullable|string|max:120', 'timezone' => 'nullable|string|max:64', 'status' => 'nullable|in:active,down,maintenance,retired'];
    }
}
