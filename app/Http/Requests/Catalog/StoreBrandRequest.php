<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;

class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Brand::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
