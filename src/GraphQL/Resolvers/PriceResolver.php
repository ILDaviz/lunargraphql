<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Exceptions\MissingCurrencyPriceException;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Price;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductVariant;

class PriceResolver
{
    public function getPriceFormatted(Model $model, array $args): string
    {
        if ($model instanceof Price && method_exists($model, 'format')) {
            return $model->format('price') ?? '';
        }

        $priceVal = $model->price ?? 0;

        return number_format($priceVal / 100, 2);
    }

    public function getPriceDecimal(Model $model, array $args): float
    {
        if ($model instanceof Price && method_exists($model, 'decimal')) {
            return $model->decimal('price') ?? 0.0;
        }

        $currency = $model->relationLoaded('currency') ? $model->currency : ($model->currency ?? null);
        $decimalPlaces = $currency?->decimal_places ?? 2;

        return round(($model->price ?? 0) / (10 ** $decimalPlaces), $decimalPlaces);
    }

    public function getListPriceFormatted(Model $model, array $args): ?string
    {
        if ($model->list_price === null) {
            return null;
        }

        if ($model instanceof Price && method_exists($model, 'format')) {
            return $model->format('list_price');
        }

        $currency = $model->relationLoaded('currency') ? $model->currency : ($model->currency ?? null);
        $decimalPlaces = $currency?->decimal_places ?? 2;

        return number_format($model->list_price / (10 ** $decimalPlaces), $decimalPlaces);
    }

    public function getListPriceDecimal(Model $model, array $args): ?float
    {
        if ($model->list_price === null) {
            return null;
        }

        if ($model instanceof Price && method_exists($model, 'decimal')) {
            return $model->decimal('list_price');
        }

        $currency = $model->relationLoaded('currency') ? $model->currency : ($model->currency ?? null);
        $decimalPlaces = $currency?->decimal_places ?? 2;

        return round($model->list_price / (10 ** $decimalPlaces), $decimalPlaces);
    }

    public function getPriceIncTax(Model $model, array $args): int
    {
        try {
            if (method_exists($model, 'priceIncTax')) {
                $price = $model->priceIncTax();

                return $price instanceof PriceValue ? $price->value : (int) $price;
            }
        } catch (\Throwable) {
            // Fallback if priceable is not loaded or missing
        }

        return (int) ($model->price ?? 0);
    }

    public function getPriceExTax(Model $model, array $args): int
    {
        try {
            if (method_exists($model, 'priceExTax')) {
                $price = $model->priceExTax();

                return $price instanceof PriceValue ? $price->value : (int) $price;
            }
        } catch (\Throwable) {
            // Fallback if priceable is not loaded or missing
        }

        return (int) ($model->price ?? 0);
    }

    public function resolveVariantPrice(ProductVariant $variant, array $args): ?Price
    {
        $currency = $this->resolveCurrency($args);
        if (! $currency) {
            return null;
        }

        try {
            $pricing = $variant->pricing()->currency($currency)->get();

            return $pricing->matched ?? $pricing->base;
        } catch (MissingCurrencyPriceException|\ErrorException) {
            return null;
        }
    }

    public function resolveProductPrice(Product $product, array $args): ?Price
    {
        $variant = $product->relationLoaded('variants')
            ? $product->variants->first(fn (ProductVariant $variant) => $variant->enabled)
            : ($product->variants()->where('enabled', true)->first());

        return $variant ? $this->resolveVariantPrice($variant, $args) : null;
    }

    protected function resolveCurrency(array $args): ?Currency
    {
        $currencyCode = Arr::get($args, 'currency') ?? Arr::get($args, 'currencyCode');
        if ($currencyCode !== null) {
            return Currency::where('code', $currencyCode)->where('enabled', true)->first();
        }

        return CartSession::getCurrency() ?? Currency::getDefault();
    }
}
