<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Actions\ManageProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->with(['category', 'brand'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';

                // Deliberately a simple LIKE across the three fields an
                // operator actually searches by. Full-text/scout is premature.
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('model', 'like', $term);
                });
            })
            ->when(
                $request->filled('category_id'),
                fn ($query) => $query->where('category_id', $request->integer('category_id'))
            )
            ->when(
                $request->filled('brand_id'),
                fn ($query) => $query->where('brand_id', $request->integer('brand_id'))
            )
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 25), 100))
            ->withQueryString();

        return ApiResponse::success(ProductResource::collection($products));
    }

    public function store(StoreProductRequest $request, ManageProduct $action): JsonResponse
    {
        $product = $action->create(
            attributes: $request->safe()->except(['image']),
            image: $request->file('image'),
        );

        return ApiResponse::success(new ProductResource($product), status: 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return ApiResponse::success(new ProductResource($product->load(['category', 'brand'])));
    }

    public function update(UpdateProductRequest $request, Product $product, ManageProduct $action): JsonResponse
    {
        $updated = $action->update(
            product: $product,
            attributes: $request->safe()->except(['image', 'remove_image']),
            image: $request->file('image'),
            removeImage: $request->boolean('remove_image'),
        );

        return ApiResponse::success(new ProductResource($updated));
    }

    /**
     * PUT /products/{product}/status — quick activate/deactivate from the list.
     */
    public function updateStatus(Request $request, Product $product, ManageProduct $action): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $updated = $action->update($product, ['is_active' => $validated['is_active']]);

        return ApiResponse::success(new ProductResource($updated));
    }

    /**
     * Archive (soft delete). Products are never hard-deleted: their SKU and
     * details appear on historical invoices.
     */
    public function destroy(Product $product, ManageProduct $action): JsonResponse
    {
        $this->authorize('delete', $product);

        $action->archive($product);

        return ApiResponse::success(['message' => 'Product archived.']);
    }
}
