<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Actions\ManageCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\Catalog\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
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

        return ApiResponse::success(CategoryResource::collection($categories));
    }

    public function store(StoreCategoryRequest $request, ManageCategory $action): JsonResponse
    {
        $category = $action->create($request->validated());

        return ApiResponse::success(new CategoryResource($category), status: 201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return ApiResponse::success(new CategoryResource($category->loadCount('products')));
    }

    public function update(UpdateCategoryRequest $request, Category $category, ManageCategory $action): JsonResponse
    {
        $updated = $action->update($category, $request->validated());

        return ApiResponse::success(new CategoryResource($updated->loadCount('products')));
    }

    /**
     * Archive. Refused while products reference the category -- see
     * ManageCategory::archive.
     */
    public function destroy(Category $category, ManageCategory $action): JsonResponse
    {
        $this->authorize('delete', $category);

        $action->archive($category);

        return ApiResponse::success(['message' => 'Category archived.']);
    }
}
