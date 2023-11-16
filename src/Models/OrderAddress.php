<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends \Lunar\Models\OrderAddress
{
    /**
     * Return the order relationship.
     */
    public function order(): BelongsTo
    {
        return parent::order();
    }

    /**
     * Return the country relationship.
     */
    public function country(): BelongsTo
    {
        return parent::country();
    }
}
