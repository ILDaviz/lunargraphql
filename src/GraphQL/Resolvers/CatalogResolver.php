<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Channel;
use Lunar\Core\Models\Collection as LunarCollection;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Language;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\Models\Region;
use Lunar\Core\Models\Url;
use Lunargraphql\Traits\WithGlobalID;

class CatalogResolver
{
    use WithGlobalID;

    public function productBySlug(mixed $root, array $args): ?Product
    {
        $slug = Arr::get($args, 'slug');
        if (! $slug) {
            return null;
        }

        $productMorph = (new Product)->getMorphClass();

        $url = Url::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($productMorph) {
                $query->where('element_type', $productMorph)
                    ->orWhere('element_type', Product::class);
            })
            ->first();

        return $url?->element instanceof Product ? $url->element : null;
    }

    public function collectionBySlug(mixed $root, array $args): ?LunarCollection
    {
        $slug = Arr::get($args, 'slug');
        if (! $slug) {
            return null;
        }

        $collectionMorph = (new LunarCollection)->getMorphClass();

        $url = Url::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($collectionMorph) {
                $query->where('element_type', $collectionMorph)
                    ->orWhere('element_type', LunarCollection::class);
            })
            ->first();

        return $url?->element instanceof LunarCollection ? $url->element : null;
    }

    public function brandByHandle(mixed $root, array $args): ?Brand
    {
        $handle = Arr::get($args, 'handle');
        if (! $handle) {
            return null;
        }

        return Brand::query()->where('handle', $handle)->first();
    }

    public function urlQuery(mixed $root, array $args): ?Url
    {
        $slug = Arr::get($args, 'slug');
        if (! $slug) {
            return null;
        }

        return Url::query()->where('slug', $slug)->first();
    }

    public function resolveThumbnail(mixed $model): mixed
    {
        if (! $model) {
            return null;
        }

        // 1. If media relation is already loaded in-memory, use it directly without hitting DB
        if (is_object($model) && method_exists($model, 'relationLoaded')) {
            if ($model->relationLoaded('media') && $model->media->isNotEmpty()) {
                return $model->media->first();
            }

            if ($model->relationLoaded('images') && $model->images->isNotEmpty()) {
                return $model->images->first();
            }
        }

        if (method_exists($model, 'getThumbnail')) {
            $thumbnail = $model->getThumbnail();
            if ($thumbnail) {
                return $thumbnail;
            }
        }

        if (method_exists($model, 'thumbnail')) {
            $thumbnail = $model->thumbnail;
            if ($thumbnail) {
                return $thumbnail;
            }
        }

        if (method_exists($model, 'images')) {
            return $model->images()->first();
        }

        if (method_exists($model, 'media')) {
            return $model->media()->first();
        }

        return null;
    }

    public function resolveImages(mixed $model): iterable
    {
        if (! $model) {
            return [];
        }

        // 1. If media relation is already loaded in-memory, use it directly without hitting DB
        if (is_object($model) && method_exists($model, 'relationLoaded')) {
            if ($model->relationLoaded('media')) {
                return $model->media;
            }

            if ($model->relationLoaded('images')) {
                return $model->images;
            }
        }

        if (method_exists($model, 'images')) {
            return $model->images()->get();
        }

        if (method_exists($model, 'media')) {
            return $model->media()->get();
        }

        return [];
    }

    public function resolveImageUrl(mixed $media): string
    {
        if (is_object($media) && method_exists($media, 'getUrl')) {
            return $media->getUrl();
        }

        if (is_array($media)) {
            return $media['url'] ?? '';
        }

        return '';
    }

    public function resolveImageThumbnailUrl(mixed $media): ?string
    {
        if (is_object($media) && method_exists($media, 'getUrl')) {
            try {
                if (method_exists($media, 'hasGeneratedConversion') && $media->hasGeneratedConversion('small')) {
                    return $media->getUrl('small');
                }
            } catch (\Throwable) {
                // Ignore conversion errors
            }

            return $media->getUrl();
        }

        if (is_array($media)) {
            return $media['thumbnailUrl'] ?? $media['url'] ?? null;
        }

        return null;
    }

    public function resolveCollectionAncestors(LunarCollection $collection): Collection
    {
        return $collection->ancestors()->get();
    }

    public function resolveCollectionDescendants(LunarCollection $collection): Collection
    {
        return $collection->descendants()->get();
    }

    public function resolveCollectionBreadcrumbs(LunarCollection $collection): Collection
    {
        return $collection->ancestors()->get()->push($collection);
    }

    public function defaultCurrency(): ?Currency
    {
        return Currency::getDefault() ?? Currency::where('default', true)->first() ?? Currency::first();
    }

    public function defaultChannel(): ?Channel
    {
        return Channel::getDefault() ?? Channel::where('default', true)->first() ?? Channel::first();
    }

    public function defaultLanguage(): ?Language
    {
        return Language::getDefault() ?? Language::where('default', true)->first() ?? Language::first();
    }

    public function defaultRegion(): ?Region
    {
        return Region::getDefault() ?? Region::where('default', true)->first() ?? Region::first();
    }

    public function resolveTotalInventory(ProductVariant $variant): int
    {
        if (method_exists($variant, 'getTotalInventory')) {
            return $variant->getTotalInventory();
        }

        return (int) ($variant->stock_available ?? 0);
    }

    public function resolveIsPurchasable(ProductVariant $variant): bool
    {
        if (method_exists($variant, 'isPurchasable')) {
            return $variant->isPurchasable();
        }

        return (bool) ($variant->enabled ?? false);
    }
}
