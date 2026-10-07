<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Support\WorkingSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'staff_code' => ['required', 'string', 'max:50',
                Rule::unique('users', 'staff_code')->where('school_id', WorkingSchool::id(request()))],
            'password' => ['required', 'string', 'min:8'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }
}
