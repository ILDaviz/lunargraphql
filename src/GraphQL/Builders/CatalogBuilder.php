<?php

namespace Lunargraphql\GraphQL\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Lunar\Core\Models\Collection;
use Lunar\Core\Models\Price;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductOption;
use Lunar\Core\Models\ProductOptionValue;
use Lunar\Core\Models\ProductVariant;
use Lunargraphql\Traits\WithGlobalID;

class CatalogBuilder
{
    use WithGlobalID;

    public function filterCatalog(Builder $builder, mixed $value = null, mixed $root = null, array $args = []): Builder
    {
        $filter = is_array($value) && ! empty($value)
            ? $value
            : (Arr::get($args, 'filter') ?? (is_array($value) ? $value : []));

        // Status filter (defaults to published for public storefront security)
        $status = Arr::get($filter, 'status');
        if ($status && $status !== 'all') {
            $builder->where('status', $status);
        } elseif (! $status) {
            $builder->where('status', 'published');
        }

        if (empty($filter)) {
            return $builder;
        }

        // Sorting support
        $sortBy = Arr::get($filter, 'sortBy');
        $sortDir = strtolower((string) Arr::get($filter, 'sortDir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy) {
            if ($sortBy === 'price') {
                $priceTable = (new Price)->getTable();
                $variantTable = (new ProductVariant)->getTable();
                $productTable = (new Product)->getTable();

                $builder->orderBy(
                    Price::select("{$priceTable}.price")
                        ->join($variantTable, "{$variantTable}.id", '=', "{$priceTable}.priceable_id")
                        ->whereColumn("{$variantTable}.product_id", "{$productTable}.id")
                        ->where("{$priceTable}.priceable_type", (new ProductVariant)->getMorphClass())
                        ->where(function ($q) use ($priceTable) {
                            $q->where("{$priceTable}.min_quantity", 1)
                                ->orWhereNull("{$priceTable}.min_quantity");
                        })
                        ->orderBy("{$priceTable}.price", $sortDir)
                        ->limit(1),
                    $sortDir
                );
            } elseif (in_array($sortBy, ['id', 'created_at', 'updated_at', 'name', 'status'])) {
                $builder->orderBy($sortBy, $sortDir);
            }
        }

        if ($channels = Arr::get($filter, 'channels')) {
            $channelIds = collect($channels)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($channelIds)) {
                $builder->whereHas('channels', function ($query) use ($channelIds) {
                    $query->whereIn($query->getModel()->getQualifiedKeyName(), $channelIds);
                });
            }
        }

        // Collection filter
        if ($collections = Arr::get($filter, 'collections')) {
            $collectionIds = collect($collections)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($collectionIds)) {
                $builder->whereHas('collections', function ($query) use ($collectionIds) {
                    $query->whereIn($query->getModel()->getQualifiedKeyName(), $collectionIds);
                });
            }
        }

        // Brand filter
        if ($brands = Arr::get($filter, 'brands')) {
            $brandIds = collect($brands)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($brandIds)) {
                $builder->whereIn('brand_id', $brandIds);
            }
        }

        // Text search across product name and description
        if ($search = Arr::get($filter, 'search')) {
            $builder->where(function (Builder $query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Attribute filters
        if ($filters = Arr::get($filter, 'filters')) {
            foreach ($filters as $filterItem) {
                $attr = Arr::get($filterItem, 'attribute');
                $val = Arr::get($filterItem, 'value');

                if ($attr && $val !== null) {
                    $builder->whereJsonContains('attribute_data', [
                        $attr => ['value' => $val],
                    ]);
                }
            }
        }

        // Price range filter
        if ($priceRange = Arr::get($filter, 'priceRange') ?? Arr::get($filter, 'prices')) {
            $min = Arr::get($priceRange, 'min');
            $max = Arr::get($priceRange, 'max');

            $builder->whereHas('prices', function (Builder $query) use ($min, $max) {
                $table = $query->getModel()->getTable();

                $query->where(function ($q) use ($table) {
                    $q->where("{$table}.min_quantity", 1)
                        ->orWhereNull("{$table}.min_quantity");
                });

                if ($min !== null) {
                    $minVal = (int) round($min * 100);
                    $query->where("{$table}.price", '>=', $minVal);
                }

                if ($max !== null) {
                    $maxVal = (int) round($max * 100);
                    $query->where("{$table}.price", '<=', $maxVal);
                }
            });
        }

        // Option values filter (variants matching specific option values)
        if ($optionValues = Arr::get($filter, 'optionValues')) {
            $valueIds = collect($optionValues)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($valueIds)) {
                $optValTable = (new ProductOptionValue)->getTable();
                $builder->whereHas('variants.values', function (Builder $query) use ($valueIds, $optValTable) {
                    $query->whereIn("{$optValTable}.id", $valueIds);
                });
            }
        }

        // Product options filter (products having specified options)
        if ($options = Arr::get($filter, 'options')) {
            $optionIds = collect($options)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($optionIds)) {
                $optTable = (new ProductOption)->getTable();
                $builder->whereHas('productOptions', function (Builder $query) use ($optionIds, $optTable) {
                    $query->whereIn("{$optTable}.id", $optionIds);
                });
            }
        }

        return $builder;
    }

    public function resolveCollectionProducts(mixed $root, array $args): Builder
    {
        /** @var Collection $root */
        $builder = $root->products()->getQuery();

        return $this->filterCatalog($builder, Arr::get($args, 'filter', []), $root, $args);
    }
}
