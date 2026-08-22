<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\SlugGenerator;
use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class ManageCategory
{
    public function __construct(private readonly SlugGenerator $slugs) {}

    /**
     * @param  array{name: string, parent_id?: int|null, slug?: string|null, description?: string|null, is_active?: bool}  $attributes
     */
    public function create(array $attributes): Category
    {
        return DB::transaction(function () use ($attributes): Category {
            $category = new Category;
            $category->name = $attributes['name'];
            $category->parent_id = $attributes['parent_id'] ?? null;
            $category->description = $attributes['description'] ?? null;
            $category->is_active = $attributes['is_active'] ?? true;
            $category->slug = $this->resolveSlug($attributes, $attributes['name']);
            $category->save();

            return $category;
        });
    }

    /**
     * @param  array{name?: string, parent_id?: int|null, slug?: string|null, description?: string|null, is_active?: bool}  $attributes
     *
     * @throws BusinessRuleException
     */
    public function update(Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes): Category {
            if (array_key_exists('name', $attributes)) {
                $category->name = $attributes['name'];
            }

            if (array_key_exists('parent_id', $attributes)) {
                $this->assertParentIsAllowed($category, $attributes['parent_id']);
                $category->parent_id = $attributes['parent_id'];
            }

            if (array_key_exists('description', $attributes)) {
                $category->description = $attributes['description'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $category->is_active = $attributes['is_active'];
            }

            if (array_key_exists('slug', $attributes) && $attributes['slug'] !== null && $attributes['slug'] !== '') {
                $category->slug = $this->slugs->generate(Category::class, $attributes['slug'], $category->getKey());
            }

            $category->save();

            return $category;
        });
    }

    /**
     * Archive (soft delete).
     *
     * Refused while any product still references the category -- including
     * archived products, because the FK is RESTRICT. Business rule 25:
     * categories in use are deactivated, not deleted.
     *
     * @throws BusinessRuleException
     */
    public function archive(Category $category): void
    {
        if ($category->hasProducts()) {
            throw new BusinessRuleException(
                'This category is used by one or more products. Deactivate it instead of deleting it.',
                'CATEGORY_IN_USE',
            );
        }

        if ($category->children()->exists()) {
            throw new BusinessRuleException(
                'This category has child categories. Remove or reassign them first.',
                'CATEGORY_HAS_CHILDREN',
            );
        }

        $category->delete();
    }

    /**
     * @param  array{slug?: string|null}  $attributes
     */
    private function resolveSlug(array $attributes, string $fallbackSource): string
    {
        $provided = $attributes['slug'] ?? null;

        return $this->slugs->generate(
            Category::class,
            is_string($provided) && $provided !== '' ? $provided : $fallbackSource,
        );
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertParentIsAllowed(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $category->getKey()) {
            throw new BusinessRuleException(
                'A category cannot be its own parent.',
                'CATEGORY_SELF_PARENT',
            );
        }

        // Walk up the proposed ancestry to reject a cycle (A -> B -> A).
        $seen = [$category->getKey()];
        $cursor = Category::find($parentId);

        while ($cursor !== null) {
            if (in_array($cursor->getKey(), $seen, true)) {
                throw new BusinessRuleException(
                    'That parent would create a circular category hierarchy.',
                    'CATEGORY_CIRCULAR_PARENT',
                );
            }

            $seen[] = $cursor->getKey();
            $cursor = $cursor->parent_id === null ? null : Category::find($cursor->parent_id);
        }
    }
}
