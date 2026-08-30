<?php
namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest;
class BranchRequest extends FormRequest { public function authorize():bool{return auth()->check();} public function rules():array{return ['code'=>'required|string|max:32','name'=>'required|string|max:120','timezone'=>'nullable|string|max:64','address'=>'nullable|string','notes'=>'nullable|string','is_active'=>'sometimes|boolean'];} }
