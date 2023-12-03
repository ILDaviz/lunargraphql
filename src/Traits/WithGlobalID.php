<?php

namespace Lunargraphql\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\GlobalId\Base64GlobalId;
use Nuwave\Lighthouse\GlobalId\GlobalIdException;

trait WithGlobalID
{
    /**
     * Decode the global ID.
     *
     * @param string $globalId
     * @return array
     * @throws GlobalIdException
     */
    public function decodeGlobalId(string $globalId): array
    {
        return (new Base64GlobalId)->decode($globalId);
    }

    /**
     * Encode the global ID.
     *
     * @param string $type
     * @param string $id
     * @return string
     */
    public function encodeGlobalId(string $type, string $id): string
    {
        return (new Base64GlobalId)->encode($type, $id);
    }

    /**
     * Get the model from the global ID.
     *
     * @param array $args
     * @param string $fieldName
     * @param string $typeClass
     * @return Model|null
     * @throws \Exception
     */
    public function getModelFromGlobalId(array $args, string $fieldName, string $typeClass): ?Model
    {

        $globalValue = Arr::get($args, $fieldName);

        $nameClass = Str::of($typeClass)->afterLast('\\')->__toString();

        $nameClassGlobal = Arr::get($globalValue, 0);
        $idClassGlobal = Arr::get($globalValue, 1);

        if ($nameClassGlobal !== $nameClass) {
            throw new \Exception("The global ID is not from the type $nameClass");
        }

        return (new $typeClass)::find($idClassGlobal);
    }
}
