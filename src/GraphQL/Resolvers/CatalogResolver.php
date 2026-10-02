<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    protected function canViewUnpublished(): bool
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        return Gate::forUser($user)->allows('view-unpublished-catalog');
    }

    public function resolveVariants(Product $product): EloquentCollection
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->get();

        if (! $this->canViewUnpublished()) {
            return $variants->filter(fn (ProductVariant $variant) => $variant->enabled)->values();
        }

        return $variants;
    }

    public function resolveCollectionProductList(LunarCollection $collection): EloquentCollection
    {
        $builder = $collection->products()->getQuery();
        if (! $this->canViewUnpublished()) {
            $builder->where('status', 'published');
        }

        return $builder->with(['variants.prices.currency', 'variants.prices.priceable'])->get();
    }

    public function productQuery(mixed $root, array $args): ?Product
    {
        $id = $this->extractIdFromArgs($args, 'id');
        if (! $id) {
            return null;
        }

        /** @var Product|null $product */
        $product = is_numeric($id)
            ? Product::find($id)
            : Product::where('public_id', $id)->first();

        if (! $product) {
            return null;
        }

        $status = is_object($product->status) ? (string) $product->status : $product->status;
        if ($status !== 'published' && ! $this->canViewUnpublished()) {
            return null;
        }

        return $product;
    }

    public function productVariantQuery(mixed $root, array $args): ?ProductVariant
    {
        $id = $this->extractIdFromArgs($args, 'id');
        if (! $id) {
            return null;
        }

        /** @var ProductVariant|null $variant */
        $variant = is_numeric($id)
            ? ProductVariant::find($id)
            : ProductVariant::where('public_id', $id)->first();

        if (! $variant) {
            return null;
        }

        if ((! $variant->enabled || (string) $variant->product?->status !== 'published') && ! $this->canViewUnpublished()) {
            return null;
        }

        return $variant;
    }

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

        $product = $url?->element instanceof Product ? $url->element : null;

        if ($product) {
            $status = is_object($product->status) ? (string) $product->status : $product->status;
            if ($status !== 'published' && ! $this->canViewUnpublished()) {
                return null;
            }
        }

        return $product;
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

    public function resolveImageUrl(mixed $media, array $args = []): string
    {
        $conversion = Arr::get($args, 'conversion');

        if (is_object($media) && method_exists($media, 'getUrl')) {
            try {
                if ($conversion && method_exists($media, 'hasGeneratedConversion') && $media->hasGeneratedConversion($conversion)) {
                    return $media->getUrl($conversion);
                }
            } catch (\Throwable) {
            }

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
