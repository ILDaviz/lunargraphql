<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Price;

class PriceResolver
{
    public function getPriceIncTax(Model $model, array $args): string
    {
        throw_if(! $model instanceof Price, new \Exception('Price not found'));

        return $model->priceIncTax();
    }

    public function getPrice(Model $model, array $args): array
    {
        throw_if(! $model instanceof Price, new \Exception('Price not found'));

        /** @var \Lunar\DataTypes\Price $price */
        $price = $model->price;

        return [
            'price' => $price->price,
            'currency'
        ];
    }

    public function getPriceExTax(Model $model, array $args): string
    {
        throw_if(! $model instanceof Price, new \Exception('Price not found'));

        return $model->priceExTax();
    }

    public function getComparePrice(Model $model, array $args): int
    {
        throw_if(! $model instanceof Price, new \Exception('Price not found'));

        return (int)$model->comppare_price;
    }

}
