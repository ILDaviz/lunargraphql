<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lunar\Base\FieldType;
use Lunar\Models\Attribute;
use Lunar\Models\Product;

class CommonResolver
{
    public function attributeDataField(Model $model, array $args): Collection
    {
        /** @var Collection $attributeData */
        $attributeData = $model?->attribute_data?->map(function ($item, $keyName) {
            if ($item instanceof FieldType){
                $nameType = Str::of($item::class)->afterLast('\\')->lower();

                return [
                    'type' => $nameType,
                    'name' => $keyName,
                    'value' => json_encode($item->getValue()),
                ];
            }

            return [];
        });

        if (! $attributeData) {
            return collect();
        }

        return $attributeData;
    }

    public function arrayObjectField(Model $model, array $args): string
    {
        /** @var ArrayObject $meta */
        return json_encode($model?->meta?->toArray() ?? []);
    }

    public function getFilterableAttributesQuery(mixed $model, array $args): Collection
    {
        return Attribute::query()
            ->where('attribute_type', Product::class)
            ->where('filterable', true)
            ->get();
    }

    public function nameField(Model $model, array $args): array
    {
        $nameValues = get_object_vars($model->name);

        return collect($nameValues)->map(function ($value, $lang) {
            return [
                'lang' => $lang,
                'value' => $value,
            ];
        })->toArray();
    }

    public function labelField(Model $model, array $args): array
    {
        return $this->nameField($model, $args);
    }
}
