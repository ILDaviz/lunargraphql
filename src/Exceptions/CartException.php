<?php

namespace Lunargraphql\Exceptions;

class CartException extends ApplicationException
{
    public static function productVariantNotFound(): self
    {
        return new static('Product variant not found', 404);
    }

    public static function cartNotFound(): self
    {
        return new static('Cart not found', 404);
    }

    public static function cartLineNotFound(): self
    {
        return new static('Cart line not found', 404);
    }

    public static function orderCreationFailed(string $message = 'Unable to create order from cart'): self
    {
        return new static($message, 422);
    }

    public static function cartEmpty(): self
    {
        return new static('Cannot checkout an empty cart', 422);
    }

    public static function invalidAddress(string $type = 'shipping'): self
    {
        return new static("A valid {$type} address is required", 422);
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
