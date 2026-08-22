<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = $this->route('brand');

        return $brand instanceof Brand
            && ($this->user()?->can('update', $brand) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
