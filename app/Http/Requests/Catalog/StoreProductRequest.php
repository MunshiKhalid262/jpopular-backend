<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Services\ProductImageStore;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Rules\UniqueSku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Money: up to 12 integer digits and at most 2 decimals, matching
     * DECIMAL(14,2). Anchored, so scientific notation ("1e5"), signs, and
     * excess scale are all rejected before they reach the database.
     */
    public const MONEY_REGEX = '/^\d{1,12}(\.\d{1,2})?$/';

    /** Quantity: matches DECIMAL(12,3). */
    public const QUANTITY_REGEX = '/^\d{1,9}(\.\d{1,3})?$/';

    /** Percentage: 0.00 - 100.00, range enforced separately. */
    public const RATE_REGEX = '/^\d{1,3}(\.\d{1,2})?$/';

    /** 2 MB. */
    public const MAX_IMAGE_KB = 2048;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],

            // Strict policy: unique across active AND archived products.
            'sku' => ['required', 'string', 'max:64', new UniqueSku],

            'category_id' => [
                'required', 'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'brand_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('brands', 'id')->whereNull('deleted_at'),
            ],

            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'unit' => ['required', 'string', Rule::in(ProductUnit::values())],
            'hsn_code' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^[0-9]{4,8}$/'],

            'gst_rate' => ['required', 'regex:'.self::RATE_REGEX, 'numeric', 'between:0,100'],
            'selling_price' => ['required', 'regex:'.self::MONEY_REGEX, 'numeric', 'min:0'],
            'purchase_price' => ['sometimes', 'nullable', 'regex:'.self::MONEY_REGEX, 'numeric', 'min:0'],
            'min_stock_level' => ['sometimes', 'regex:'.self::QUANTITY_REGEX, 'numeric', 'min:0'],

            'is_active' => ['sometimes', 'boolean'],

            // mimetypes (not `image`) because Laravel's `image` rule permits
            // SVG, which can carry script. Checked against the real MIME type.
            'image' => [
                'sometimes', 'nullable', 'file',
                'mimetypes:'.implode(',', ProductImageStore::allowedMimeTypes()),
                'max:'.self::MAX_IMAGE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gst_rate.regex' => 'The GST rate must be a number with at most two decimal places.',
            'selling_price.regex' => 'The selling price must be a positive amount with at most two decimal places.',
            'purchase_price.regex' => 'The purchase price must be a positive amount with at most two decimal places.',
            'min_stock_level.regex' => 'The minimum stock level must be zero or a positive number.',
            'hsn_code.regex' => 'The HSN/SAC code must be 4 to 8 digits.',
            'image.mimetypes' => 'The image must be a JPEG, PNG, or WebP file.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => trim($this->input('sku'))]);
        }
    }
}
