<?php

namespace Lunargraphql\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Nuwave\Lighthouse\GlobalId\Base64GlobalId;

trait WithGlobalID
{
    /**
     * Decode the global ID.
     */
    public function decodeGlobalId(string $globalId): array
    {
        try {
            return (new Base64GlobalId)->decode($globalId);
        } catch (\Throwable) {
            // Fallback for unencoded or custom values
            if (str_contains($globalId, ':')) {
                return explode(':', $globalId, 2);
            }

            return ['', $globalId];
        }
    }

    /**
     * Encode the global ID.
     */
    public function encodeGlobalId(string $type, string|int $id): string
    {
        return (new Base64GlobalId)->encode($type, (string) $id);
    }

    /**
     * Extract the raw ID from a field value (which could be an array from Lighthouse, a base64 string, or a raw id).
     */
    public function extractIdFromArgs(array $args, string $fieldName): string|int|null
    {
        $value = Arr::get($args, $fieldName);

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            // Lighthouse @globalId decodes to [type, id] or ['type' => ..., 'id' => ...]
            return Arr::get($value, 1) ?? Arr::get($value, 'id') ?? Arr::get($value, 0);
        }

        if (is_numeric($value)) {
            return $value;
        }

        // Try decoding as base64 global ID
        $decoded = $this->decodeGlobalId((string) $value);
        if (! empty($decoded[1])) {
            return $decoded[1];
        }

        return $value;
    }

    /**
     * Get the model from the global ID or direct ID / public_id.
     */
    public function getModelFromGlobalId(array $args, string $fieldName, string $typeClass): ?Model
    {
        $id = $this->extractIdFromArgs($args, $fieldName);

        if ($id === null) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $typeClass;

        // Try primary key find
        $model = $instance->newQuery()->find($id);

        // Fallback to public_id if model has public_id column and wasn't found by integer id
        if (! $model && is_string($id) && method_exists($instance, 'getConnection')) {
            $model = $instance->newQuery()->where('public_id', $id)->first();
        }

        return $model;
    }
}
