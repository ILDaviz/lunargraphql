<?php

namespace Lunargraphql\Exceptions;

class CartException extends ApplicationException
{

    public static function productVariantNotFound(): self
    {
        return new static('Product variant not found', 404);
    }

    public static function cartLineNotFound(): self
    {
        return new static('Cart line not found', 404);
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
