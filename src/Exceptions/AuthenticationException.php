<?php

namespace Lunargraphql\Exceptions;

class AuthenticationException extends ApplicationException
{
    public static function incorrectAccessData(): self
    {
        return new static(static::trans('incorrect_access_data', 'Incorrect access data'), 401);
    }

    public static function typeModelNotFound(): self
    {
        return new static(static::trans('type_model_not_found', 'Type model not found'), 404);
    }

    public static function invalidUser(): self
    {
        return new static(static::trans('invalid_user', 'Invalid user'), 404);
    }

    public static function invalidToken(): self
    {
        return new static(static::trans('invalid_token', 'Invalid token'), 404);
    }

    public static function resetThrottled(): self
    {
        return new static(static::trans('reset_throttled', 'Reset throttled'), 429);
    }

    public static function userAlreadyExists(): self
    {
        return new static(static::trans('user_already_exists', 'User already exists'), 409);
    }

    public static function userNotFound(): self
    {
        return new static(static::trans('user_not_found', 'User not found'), 404);
    }

    public static function passwordIncorrect(): self
    {
        return new static(static::trans('password_incorrect', 'Password is incorrect'), 401);
    }

    public static function passwordConfirmationMismatch(): self
    {
        return new static(static::trans('password_confirmation_mismatch', 'Password confirmation does not match'), 422);
    }

    public static function invalidEmail(?string $message = null): self
    {
        return new static($message ?? static::trans('invalid_email', 'Invalid email address'), 422);
    }

    public static function weakPassword(?string $message = null): self
    {
        return new static($message ?? static::trans('weak_password', 'Password must be at least 8 characters long'), 422);
    }

    public static function emailAlreadyInUse(): self
    {
        return new static(static::trans('email_already_in_use', 'This email address is already in use'), 409);
    }

    public static function unauthorized(?string $message = null): self
    {
        return new static($message ?? static::trans('unauthorized', 'This action is unauthorized'), 403);
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
