<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\SlugGenerator;
use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;

final class ManageBrand
{
    public function __construct(private readonly SlugGenerator $slugs) {}

    /**
     * @param  array{name: string, slug?: string|null, is_active?: bool}  $attributes
     */
    public function create(array $attributes): Brand
    {
        return DB::transaction(function () use ($attributes): Brand {
            $brand = new Brand;
            $brand->name = $attributes['name'];
            $brand->is_active = $attributes['is_active'] ?? true;

            $provided = $attributes['slug'] ?? null;
            $brand->slug = $this->slugs->generate(
                Brand::class,
                is_string($provided) && $provided !== '' ? $provided : $attributes['name'],
            );

            $brand->save();

            return $brand;
        });
    }

    /**
     * @param  array{name?: string, slug?: string|null, is_active?: bool}  $attributes
     */
    public function update(Brand $brand, array $attributes): Brand
    {
        return DB::transaction(function () use ($brand, $attributes): Brand {
            if (array_key_exists('name', $attributes)) {
                $brand->name = $attributes['name'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $brand->is_active = $attributes['is_active'];
            }

            if (array_key_exists('slug', $attributes) && $attributes['slug'] !== null && $attributes['slug'] !== '') {
                $brand->slug = $this->slugs->generate(Brand::class, $attributes['slug'], $brand->getKey());
            }

            $brand->save();

            return $brand;
        });
    }

    /**
     * Archive (soft delete).
     *
     * Refused while products reference it. The FK is SET NULL on hard delete,
     * but a soft delete leaves brand_id pointing at an archived row, which
     * would show products with a vanished brand -- so deactivation is the
     * correct operation for a brand in use.
     *
     * @throws BusinessRuleException
     */
    public function archive(Brand $brand): void
    {
        if ($brand->hasProducts()) {
            throw new BusinessRuleException(
                'This brand is used by one or more products. Deactivate it instead of deleting it.',
                'BRAND_IN_USE',
            );
        }

        $brand->delete();
    }
}
