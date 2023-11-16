<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderLine extends \Lunar\Models\OrderLine
{
    /**
     * Return the order relationship.
     */
    public function order(): BelongsTo
    {
        return parent::order();
    }

    /**
     * Return the polymorphic relation.
     */
    public function purchasable(): MorphTo
    {
        return parent::purchasable();
    }

    /**
     * Return the currency relationship.
     */
    public function currency(): HasOneThrough
    {
        return parent::currency();
    }
}
