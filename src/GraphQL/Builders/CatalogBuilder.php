<?php

namespace Lunargraphql\GraphQL\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CatalogBuilder
{
    //input CatalogFilterInput {
    //    channels: [String!]
    //    collections: [String!]
    //    search: String
    //    filters: [FilterInput!]
    //}
    //
    //input FilterInput {
    //    attribute: String!
    //    value: String!
    //}
    public function filterCatalog(Builder $builder, array $args): Builder
    {
        $prefix = config('lunar.database.table_prefix');

        // TODO: Missing check customerGroup if is logged.

        // TODO: Display only product without customerGroup.

        if ($channels = Arr::get($args, 'channels')) {
            $builder->whereHas('channels', function ($query) use ($prefix, $channels) {
                $query->whereIn("{$prefix}channels.id", $channels);
            });
        }

        if ($collections = Arr::get($args, 'collections')) {
            $builder->whereHas('collections', function ($query) use ($prefix, $collections) {
                $query->whereIn("{$prefix}collection_product.collection_id", $collections);
            });
        }

        if ($filters = Arr::get($args, 'filters')) {
            foreach ($filters as $filter) {
                $builder->whereJsonContains('attribute_data', [
                    $filter['attribute'] => ['value' => $filter['value']]
                ]);
            }
        }

        return $builder;
    }
}
