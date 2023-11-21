<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lunar\Base\FieldType;

class CommonResolver
{
    public function attributeData(Model $model, array $args): Collection
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

    public function arrayObject(Model $model, array $args): string
    {
        /** @var ArrayObject $meta */
        return json_encode($model?->meta?->toArray() ?? []);
    }
}
