<?php

namespace Lunargraphql\GraphQL\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Lunargraphql\Traits\WithGlobalID;

class CatalogBuilder
{
    use WithGlobalID;

    public function filterCatalog(Builder $builder, ?array $args = null): Builder
    {
        if (empty($args)) {
            return $builder;
        }
        // Channel filter
        if ($channels = Arr::get($args, 'channels')) {
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
        if ($collections = Arr::get($args, 'collections')) {
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
        if ($brands = Arr::get($args, 'brands')) {
            $brandIds = collect($brands)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($brandIds)) {
                $builder->whereIn('brand_id', $brandIds);
            }
        }

        // Text search across product name and description
        if ($search = Arr::get($args, 'search')) {
            $builder->where(function (Builder $query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Attribute filters
        if ($filters = Arr::get($args, 'filters')) {
            foreach ($filters as $filter) {
                $attr = Arr::get($filter, 'attribute');
                $val = Arr::get($filter, 'value');

                if ($attr && $val !== null) {
                    $builder->whereJsonContains('attribute_data', [
                        $attr => ['value' => $val],
                    ]);
                }
            }
        }

        // Price range filter
        if ($priceRange = Arr::get($args, 'priceRange') ?? Arr::get($args, 'prices')) {
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
        if ($optionValues = Arr::get($args, 'optionValues')) {
            $valueIds = collect($optionValues)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($valueIds)) {
                $builder->whereHas('variants.values', function (Builder $query) use ($valueIds) {
                    $prefix = config('lunar.database.table_prefix');
                    $query->whereIn("{$prefix}product_option_values.id", $valueIds);
                });
            }
        }

        // Product options filter (products having specified options)
        if ($options = Arr::get($args, 'options')) {
            $optionIds = collect($options)->map(function ($id) {
                return $this->extractIdFromArgs(['id' => $id], 'id');
            })->filter()->values()->all();

            if (! empty($optionIds)) {
                $builder->whereHas('productOptions', function (Builder $query) use ($optionIds) {
                    $prefix = config('lunar.database.table_prefix');
                    $query->whereIn("{$prefix}product_options.id", $optionIds);
                });
            }
        }

        return $builder;
    }
}
