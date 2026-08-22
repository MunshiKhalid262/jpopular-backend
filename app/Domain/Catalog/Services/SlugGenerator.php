<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Derives a unique slug for categories and brands.
 *
 * Slugs are URL/lookup conveniences, not business identifiers, so a collision
 * is resolved by appending a counter rather than rejecting the request. SKU is
 * the opposite case -- it IS a business identifier, so a duplicate there is a
 * hard validation failure (see App\Rules\UniqueSku).
 *
 * Archived rows are included, because the unique index spans them.
 */
final class SlugGenerator
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function generate(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->taken($modelClass, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function taken(string $modelClass, string $slug, ?int $ignoreId): bool
    {
        /*
         * NOTE: method_exists($modelClass, 'withTrashed') is FALSE even for a
         * soft-deleting model -- withTrashed() is a query-builder macro added
         * by SoftDeletingScope, not a method on the class. Checking for the
         * trait is the reliable test; getting this wrong silently excludes
         * archived rows and hands out a slug that the unique index then rejects.
         */
        $softDeletes = in_array(
            SoftDeletes::class,
            class_uses_recursive($modelClass),
            true,
        );

        /** @var Builder<Model> $query */
        $query = $softDeletes ? $modelClass::withTrashed() : $modelClass::query();

        return $query
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
