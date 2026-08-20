<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $id = $target instanceof User ? $target->getKey() : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:160',
                // No deleted_at filter, so soft-deleted rows still collide.
                Rule::unique('users', 'email')->ignore($id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Optional admin-initiated reset. Absent means "leave unchanged".
            'password' => ['sometimes', 'nullable', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
