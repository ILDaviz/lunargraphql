<?php

namespace Lunargraphql\Exceptions;

class AuthenticationException extends ApplicationException
{
    public static function incorrectAccessData(): self
    {
        return new static('Incorrect access data', 401);
    }

    public static function typeModelNotFound(): self
    {
        return new static('Type model not found', 404);
    }

    public static function invalidUser(): self
    {
        return new static('Invalid user', 404);
    }

    public static function invalidToken(): self
    {
        return new static('Invalid token', 404);
    }

    public static function resetThrottled(): self
    {
        return new static('Reset throttled', 429);
    }

    public static function userAlreadyExists(): self
    {
        return new static('User already exists', 409);
    }

    public static function userNotFound(): self
    {
        return new static('User not found', 404);
    }

    public static function passwordIncorrect(): self
    {
        return new static('Password is incorrect', 401);
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
