<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lunar\Models\Channel;

class Order extends \Lunar\Models\Order
{
    /**
     * Return the channel relationship.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Return the cart relationship.
     */
    public function cart(): BelongsTo
    {
        return parent::cart();
    }

    /**
     * Return the lines relationship.
     */
    public function lines(): HasMany
    {
        return parent::lines();
    }

    /**
     * Return digital product lines relationship.
     */
    public function digitalLines(): HasMany
    {
        return parent::digitalLines();
    }

    /**
     * Return shipping lines relationship.
     */
    public function shippingLines(): HasMany
    {
        return parent::shippingLines();
    }

    /**
     * Return product lines relationship.
     */
    public function productLines(): HasMany
    {
        return parent::productLines();
    }

    /**
     * Return the currency relationship.
     */
    public function currency(): BelongsTo
    {
        return parent::currency();
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
     * Return the transactions relationship.
     *
     */
    public function transactions(): HasMany
    {
        return parent::transactions();
    }

    /**
     * Return the charges relationship.
     */
    public function captures(): HasMany
    {
        return parent::captures();
    }

    /**
     * Return the charges relationship.
     */
    public function intents(): HasMany
    {
        return parent::intents();
    }

    /**
     * Return the refunds relationship.
     */
    public function refunds(): HasMany
    {
        return parent::refunds();
    }

    /**
     * Return the customer relationship.
     */
    public function customer(): BelongsTo
    {
        return parent::customer();
    }

    /**
     * Return the user relationship.
     */
    public function user(): BelongsTo
    {
        return parent::user();
    }
}
