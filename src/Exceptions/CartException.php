<?php

namespace Lunargraphql\Exceptions;

class CartException extends ApplicationException
{
    public static function productVariantNotFound(): self
    {
        return new static(static::trans('product_variant_not_found', 'Product variant not found'), 404);
    }

    public static function cartNotFound(): self
    {
        return new static(static::trans('cart_not_found', 'Cart not found'), 404);
    }

    public static function cartLineNotFound(): self
    {
        return new static(static::trans('cart_line_not_found', 'Cart line not found'), 404);
    }

    public static function orderCreationFailed(?string $message = null): self
    {
        return new static($message ?? static::trans('order_creation_failed', 'Unable to create order from cart'), 422);
    }

    public static function cartEmpty(): self
    {
        return new static(static::trans('cart_empty', 'Cannot checkout an empty cart'), 422);
    }

    public static function invalidAddress(string $type = 'shipping'): self
    {
        return new static(static::trans("invalid_{$type}_address", static::trans('invalid_address', "A valid {$type} address is required", ['type' => $type])), 422);
    }

    public static function userAlreadyExists(): AuthenticationException
    {
        return AuthenticationException::userAlreadyExists();
    }

    public static function userNotFound(): AuthenticationException
    {
        return AuthenticationException::userNotFound();
    }

    public static function passwordIncorrect(): AuthenticationException
    {
        return AuthenticationException::passwordIncorrect();
    }

    public static function invalidUser(): AuthenticationException
    {
        return AuthenticationException::invalidUser();
    }

    public static function invalidToken(): AuthenticationException
    {
        return AuthenticationException::invalidToken();
    }

    public static function resetThrottled(): AuthenticationException
    {
        return AuthenticationException::resetThrottled();
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
