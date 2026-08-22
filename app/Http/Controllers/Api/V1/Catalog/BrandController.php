<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Actions\ManageBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\Catalog\BrandResource;
use App\Models\Brand;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $brands = Brand::query()
            ->withCount('products')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where('name', 'like', $term);
            })
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 25), 100))
            ->withQueryString();

        return ApiResponse::success(BrandResource::collection($brands));
    }

    public function store(StoreBrandRequest $request, ManageBrand $action): JsonResponse
    {
        $brand = $action->create($request->validated());

        return ApiResponse::success(new BrandResource($brand), status: 201);
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return ApiResponse::success(new BrandResource($brand->loadCount('products')));
    }

    public function update(UpdateBrandRequest $request, Brand $brand, ManageBrand $action): JsonResponse
    {
        $updated = $action->update($brand, $request->validated());

        return ApiResponse::success(new BrandResource($updated->loadCount('products')));
    }

    public function destroy(Brand $brand, ManageBrand $action): JsonResponse
    {
        $this->authorize('delete', $brand);

        $action->archive($brand);

        return ApiResponse::success(['message' => 'Brand archived.']);
    }
}
