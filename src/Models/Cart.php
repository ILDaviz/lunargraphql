<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cart extends \Lunar\Models\Cart
{
    /**
     * Return the cart lines relationship.
     */
    public function lines(): HasMany
    {
        return parent::lines();
    }

    /**
     * Return the currency relationship.
     */
    public function currency(): BelongsTo
    {
        return parent::currency();
    }

    /**
     * Return the user relationship.
     */
    public function user(): BelongsTo
    {
        return parent::user();
    }

    /**
     * Return the customer relationship.
     */
    public function customer(): BelongsTo
    {
        return parent::customer();
    }

    /**
     * Return the addresses relationship.
     */
    public function addresses(): HasMany
    {
        return parent::addresses();
    }

    /**
     * Return the shipping address relationship.
     */
    public function shippingAddress(): HasOne
    {
        return parent::shippingAddress();
    }

    /**
     * Return the billing address relationship.
     */
    public function billingAddress(): HasOne
    {
        return parent::billingAddress();
    }

    /**
     * Return the order relationship.
     */
    public function orders(): HasMany
    {
        return parent::orders();
    }

    /**
     * Return the draft order relationship.
     */
    public function draftOrder(int $draftOrderId = null): HasOne
    {
        return parent::draftOrder($draftOrderId);
    }

    /**
     * Return the completed order relationship.
     */
    public function completedOrder(int $completedOrderId = null): HasOne
    {
        return parent::completedOrder($completedOrderId);
    }

    /**
     * Return the carts completed order.
     */
    public function completedOrders(): HasMany
    {
        return parent::completedOrders();
    }
}
