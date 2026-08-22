<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Services\ProductImageStore;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Rules\UniqueSku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && ($this->user()?->can('update', $product) ?? false);
    }

    /**
     * Note the absence of `current_stock`. It is not accepted here, is not in
     * the model's $fillable, and is not in ManageProduct::WRITABLE -- three
     * independent barriers, because a catalog request silently rewriting the
     * cached stock balance would desynchronise it from the inventory ledger.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $id = $product instanceof Product ? $product->getKey() : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'sku' => ['sometimes', 'required', 'string', 'max:64', new UniqueSku($id)],

            'category_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'brand_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('brands', 'id')->whereNull('deleted_at'),
            ],

            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'unit' => ['sometimes', 'required', 'string', Rule::in(ProductUnit::values())],
            'hsn_code' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^[0-9]{4,8}$/'],

            'gst_rate' => ['sometimes', 'required', 'regex:'.StoreProductRequest::RATE_REGEX, 'numeric', 'between:0,100'],
            'selling_price' => ['sometimes', 'required', 'regex:'.StoreProductRequest::MONEY_REGEX, 'numeric', 'min:0'],
            'purchase_price' => ['sometimes', 'nullable', 'regex:'.StoreProductRequest::MONEY_REGEX, 'numeric', 'min:0'],
            'min_stock_level' => ['sometimes', 'regex:'.StoreProductRequest::QUANTITY_REGEX, 'numeric', 'min:0'],

            'is_active' => ['sometimes', 'boolean'],

            'image' => [
                'sometimes', 'nullable', 'file',
                'mimetypes:'.implode(',', ProductImageStore::allowedMimeTypes()),
                'max:'.StoreProductRequest::MAX_IMAGE_KB,
            ],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreProductRequest)->messages();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => trim($this->input('sku'))]);
        }
    }
}
