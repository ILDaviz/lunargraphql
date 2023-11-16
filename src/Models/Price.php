<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Price extends \Lunar\Models\Price
{
    /**
     * Return the priceable relationship.
     */
    public function priceable(): MorphTo
    {
        return parent::priceable();
    }

    /**
     * Return the currency relationship.
     */
    public function currency(): BelongsTo
    {
        return parent::currency();
    }

    /**
     * Return the customer group relationship.
     */
    public function customerGroup(): BelongsTo
    {
        return parent::customerGroup();
    }
}
