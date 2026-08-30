<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,32}$/'], 'password' => 'required|string|min:10|max:128', 'role' => 'required|in:admin,technician,operator', 'branch_ids' => 'required|array|min:1', 'branch_ids.*' => 'uuid'];
    }
}
