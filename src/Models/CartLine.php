<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CartLine extends \Lunar\Models\CartLine
{
    /**
     * Return the cart relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Return the tax class relationship.
     */
    public function taxClass(): HasOneThrough
    {
        return parent::taxClass();
    }

    /**
     * Return the tax breakdown attribute.
     */
    public function discounts(): BelongsToMany
    {
        return parent::discounts();
    }

    /**
     * Return the polymorphic relation.
     */
    public function purchasable(): MorphTo
    {
        return parent::purchasable();
    }
}
