<?php

namespace Lunargraphql\Exceptions;

class OrderException extends ApplicationException
{
    public static function orderNotFound(): self
    {
        return new static(static::trans('order_not_found', 'Order not found'), 404);
    }

    public static function invalidAmount(): self
    {
        return new static(static::trans('invalid_amount', 'Transaction amount must be greater than zero.'), 422);
    }

    public static function alreadyPaid(): self
    {
        return new static(static::trans('already_paid', 'Order has already been paid in full.'), 422);
    }

    public static function amountExceedsTotal(): self
    {
        return new static(static::trans('amount_exceeds_total', 'Transaction amount cannot exceed order total.'), 422);
    }

    public static function paymentProviderUnavailable(): self
    {
        return new static(static::trans('payment_provider_unavailable', 'The selected payment provider is unavailable or disabled.'), 422);
    }

    public static function paymentFailed(?string $message = null): self
    {
        return new static($message ?? static::trans('payment_failed', 'The payment provider could not authorize this payment.'), 422);
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
