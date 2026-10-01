<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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
                return (int) $model->priceIncTax();
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
                return (int) $model->priceExTax();
            }
        } catch (\Throwable) {
            // Fallback if priceable is not loaded or missing
        }

        return (int) ($model->price ?? 0);
    }

    public function resolveVariantPrice(ProductVariant $variant, array $args): ?Price
    {
        $currencyCode = Arr::get($args, 'currency') ?? Arr::get($args, 'currencyCode');
        $currency = ($currencyCode ? Currency::where('code', $currencyCode)->first() : null)
            ?? CartSession::getCurrency()
            ?? Currency::getDefault()
            ?? Currency::first();

        // 1. If basePrices relation is already eager-loaded, use in-memory collection
        if ($variant->relationLoaded('basePrices') && $variant->basePrices->isNotEmpty()) {
            if ($currency) {
                $matched = $variant->basePrices->first(fn ($p) => (int) $p->currency_id === (int) $currency->id);
                if ($matched) {
                    return $matched;
                }
            }

            return $variant->basePrices->first();
        }

        // 2. If prices relation is already loaded, match base price in-memory
        if ($variant->relationLoaded('prices') && $variant->prices->isNotEmpty()) {
            if ($currency) {
                $matched = $variant->prices->first(fn ($p) => (int) $p->currency_id === (int) $currency->id && (int) $p->min_quantity === 1 && $p->customer_group_id === null)
                    ?? $variant->prices->first(fn ($p) => (int) $p->currency_id === (int) $currency->id && (int) $p->min_quantity === 1);
                if ($matched) {
                    return $matched;
                }
            }

            return $variant->prices->first(fn ($p) => (int) $p->min_quantity === 1 && $p->customer_group_id === null)
                ?? $variant->prices->first();
        }

        try {
            if ($currency && method_exists($variant, 'pricing')) {
                $pricing = $variant->pricing()->currency($currency)->get();
                if ($pricing && ($pricing->matched ?? $pricing->base)) {
                    return $pricing->matched ?? $pricing->base;
                }
            }
        } catch (\Throwable) {
            // Fallback to base prices relation or prices
        }

        if ($currency) {
            $matched = $variant->basePrices()->where('currency_id', $currency->id)->first()
                ?? $variant->prices()->where('currency_id', $currency->id)->first();
            if ($matched) {
                return $matched;
            }
        }

        return $variant->basePrices()->first() ?? $variant->prices()->first();
    }

    public function resolveProductPrice(Product $product, array $args): ?Price
    {
        $currencyCode = Arr::get($args, 'currency') ?? Arr::get($args, 'currencyCode');
        $currency = ($currencyCode ? Currency::where('code', $currencyCode)->first() : null)
            ?? CartSession::getCurrency()
            ?? Currency::getDefault()
            ?? Currency::first();

        // 1. If prices relation is already loaded directly on product, match price in-memory
        if ($product->relationLoaded('prices') && $product->prices->isNotEmpty()) {
            if ($currency) {
                $matched = $product->prices->first(fn ($p) => (int) $p->currency_id === (int) $currency->id && (int) $p->min_quantity === 1 && $p->customer_group_id === null)
                    ?? $product->prices->first(fn ($p) => (int) $p->currency_id === (int) $currency->id && (int) $p->min_quantity === 1)
                    ?? $product->prices->first(fn ($p) => (int) $p->currency_id === (int) $currency->id);
                if ($matched) {
                    return $matched;
                }
            }

            return $product->prices->first(fn ($p) => (int) $p->min_quantity === 1 && $p->customer_group_id === null)
                ?? $product->prices->first();
        }

        $variant = $product->relationLoaded('variants')
            ? $product->variants->first()
            : ($product->variant ?? $product->variants()->first());

        return $variant ? $this->resolveVariantPrice($variant, $args) : null;
    }
}
