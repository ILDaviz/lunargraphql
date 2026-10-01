<?php

declare(strict_types=1);

namespace Lunargraphql\GraphQL\GlobalId;

use Nuwave\Lighthouse\GlobalId\GlobalId;

class SmartGlobalId implements GlobalId
{
    /**
     * Glue together a type and an id to create a global id.
     */
    public function encode(string $type, int|string $id): string
    {
        return base64_encode("{$type}:{$id}");
    }

    /**
     * Split a global id into the type and the id it contains.
     * Supports Relay Base64, Colon-separated ("Product:1"), or raw ID/ULID.
     *
     * @return array{0: string, 1: string}
     */
    public function decode(string $globalID): array
    {
        // 1. Try decoding from base64
        $decoded = base64_decode($globalID, true);
        if ($decoded !== false && base64_encode($decoded) === $globalID) {
            $parts = explode(':', $decoded);
            if (count($parts) === 2 && ! empty($parts[0]) && ! empty($parts[1])) {
                return [$parts[0], $parts[1]];
            }
        }

        // 2. Unencoded colon separated, e.g. "Product:1"
        if (str_contains($globalID, ':')) {
            $parts = explode(':', $globalID, 2);

            return [$parts[0], $parts[1]];
        }

        // 3. Raw database ID, integer, or ULID
        return ['', (string) $globalID];
    }

    /**
     * Decode the Global ID and get just the ID.
     */
    public function decodeID(string $globalID): string
    {
        [, $id] = $this->decode($globalID);

        return trim($id);
    }

    /**
     * Decode the Global ID and get just the type.
     */
    public function decodeType(string $globalID): string
    {
        [$type] = $this->decode($globalID);

        return trim($type);
    }
}
