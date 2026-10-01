<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lunar\Core\Contracts\FieldType;
use Lunar\Core\Models\Attribute;
use Lunar\Core\Models\Product;

class CommonResolver
{
    public function attributeDataField(Model $model, array $args): Collection
    {
        /** @var Collection|null $attributeData */
        $attributeData = $model->attribute_data;

        if (! $attributeData instanceof Collection) {
            return collect();
        }

        $result = collect();

        foreach ($attributeData as $keyName => $item) {
            if ($item instanceof FieldType) {
                $nameType = Str::of($item::class)->afterLast('\\')->lower()->toString();

                $result->push([
                    'type' => $nameType,
                    'name' => (string) $keyName,
                    'value' => json_encode($item->getValue()),
                ]);
            } elseif (is_array($item) || is_scalar($item)) {
                $result->push([
                    'type' => gettype($item),
                    'name' => (string) $keyName,
                    'value' => json_encode($item),
                ]);
            }
        }

        return $result;
    }

    public function arrayObjectField(Model $model, array $args): string
    {
        return $this->metaField($model, $args);
    }

    public function metaField(Model $model, array $args): string
    {
        $meta = $model->meta;

        if (is_string($meta)) {
            return $meta;
        }

        if (is_array($meta)) {
            return json_encode($meta);
        }

        if (is_object($meta) && method_exists($meta, 'toArray')) {
            return json_encode($meta->toArray());
        }

        return json_encode($meta ?? []);
    }

    public function getFilterableAttributesQuery(mixed $model, array $args): Collection
    {
        $productMorph = (new Product)->getMorphClass();

        return Attribute::query()
            ->where('filterable', true)
            ->whereHas('models', function ($query) use ($productMorph) {
                $query->where('model_type', $productMorph)
                    ->orWhere('model_type', Product::class);
            })
            ->get();
    }

    public function productNameField(Model $model, array $args): ?string
    {
        $lang = Arr::get($args, 'lang');

        if (method_exists($model, 'translate')) {
            return $model->translate('name', $lang);
        }

        return is_array($model->name) ? Arr::first($model->name) : (string) $model->name;
    }

    public function productDescriptionField(Model $model, array $args): ?string
    {
        $lang = Arr::get($args, 'lang');

        if (method_exists($model, 'translate')) {
            return $model->translate('description', $lang);
        }

        return is_array($model->description) ? Arr::first($model->description) : (string) $model->description;
    }

    public function productShortDescriptionField(Model $model, array $args): ?string
    {
        $lang = Arr::get($args, 'lang');

        if (method_exists($model, 'translate')) {
            return $model->translate('short_description', $lang);
        }

        return is_array($model->short_description) ? Arr::first($model->short_description) : (string) $model->short_description;
    }

    public function productOptionNameField(Model $model, array $args): string
    {
        $lang = Arr::get($args, 'lang');

        if (method_exists($model, 'translate')) {
            return (string) ($model->translate('name', $lang) ?? '');
        }

        return is_array($model->name) ? (string) Arr::first($model->name) : (string) ($model->name ?? '');
    }

    public function productOptionLabelField(Model $model, array $args): ?string
    {
        $lang = Arr::get($args, 'lang');

        if (method_exists($model, 'translate')) {
            return $model->translate('label', $lang);
        }

        return is_array($model->label) ? (string) Arr::first($model->label) : (string) ($model->label ?? '');
    }

    public function productOptionValueNameField(Model $model, array $args): string
    {
        $lang = Arr::get($args, 'lang');

        if (method_exists($model, 'translate')) {
            return (string) ($model->translate('name', $lang) ?? '');
        }

        return is_array($model->name) ? (string) Arr::first($model->name) : (string) ($model->name ?? '');
    }

    public function nameTranslationsField(Model $model, array $args): array
    {
        $raw = $model->getRawOriginal('name') ?? $model->name;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                return [
                    ['lang' => app()->getLocale(), 'value' => $raw],
                ];
            }
        }

        if (is_object($raw)) {
            $raw = get_object_vars($raw);
        }

        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)->map(function ($value, $lang) {
            return [
                'lang' => (string) $lang,
                'value' => (string) $value,
            ];
        })->values()->toArray();
    }

    public function sellingPolicyField(mixed $model): ?string
    {
        $policy = $model->selling_policy;

        return $policy instanceof \BackedEnum ? $policy->value : (string) ($policy ?? '');
    }

    public function orderStatusField(Model $model): ?string
    {
        $status = $model->status;

        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        if (is_object($status) && method_exists($status, 'name')) {
            return $status->name();
        }

        if (is_object($status) && method_exists($status, '__toString')) {
            return (string) $status;
        }

        return (string) ($status ?? '');
    }

    public function paymentStatusField(Model $model): ?string
    {
        $status = $model->payment_status;

        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        if (is_object($status) && method_exists($status, '__toString')) {
            return (string) $status;
        }

        return (string) ($status ?? '');
    }

    public function fulfilmentStatusField(Model $model): ?string
    {
        $status = $model->fulfilment_status ?? $model->state ?? $model->status;

        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        if (is_object($status) && method_exists($status, 'name')) {
            return $status->name();
        }

        if (is_object($status) && method_exists($status, '__toString')) {
            return (string) $status;
        }

        return (string) ($status ?? '');
    }

    public function fulfilmentTrackingReferenceField(Model $model): ?string
    {
        if (isset($model->tracking_reference)) {
            return $model->tracking_reference;
        }

        if (method_exists($model, 'trackings')) {
            $tracking = $model->relationLoaded('trackings')
                ? $model->trackings->first()
                : $model->trackings()->first();

            return $tracking?->tracking_number;
        }

        return null;
    }

    public function fulfilmentTrackingUrlField(Model $model): ?string
    {
        if (isset($model->tracking_url)) {
            return $model->tracking_url;
        }

        if (method_exists($model, 'trackings')) {
            $tracking = $model->relationLoaded('trackings')
                ? $model->trackings->first()
                : $model->trackings()->first();

            return $tracking?->tracking_url;
        }

        return null;
    }

    public function statusField(Model $model): ?string
    {
        $status = $model->status;

        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        if (is_object($status) && method_exists($status, 'name')) {
            return $status->name();
        }

        if (is_object($status) && method_exists($status, '__toString')) {
            return (string) $status;
        }

        return (string) ($status ?? '');
    }
}
