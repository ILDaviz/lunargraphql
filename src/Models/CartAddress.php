<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartAddress extends \Lunar\Models\CartAddress
{
    /**
     * Return the cart relationship.
     */
    public function cart(): BelongsTo
    {
        return parent::cart();
    }

    /**
     * Return the country relationship.
     */
    public function country(): BelongsTo
    {
        return parent::country();
    }
}
