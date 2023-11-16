<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lunar\Models\Discount;

class DiscountPurchasable extends \Lunar\Models\DiscountPurchasable
{
    /**
     * Return the discount relationship.
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Return the priceable relationship.
     *
     */
    public function purchasable(): MorphTo
    {
        return parent::purchasable();
    }
}
