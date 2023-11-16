<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Transaction extends \Lunar\Models\Transaction
{
    /**
     * Return the order relationship.
     */
    public function order(): BelongsTo
    {
        return parent::order();
    }

    /**
     * Return the currency relationship.
     */
    public function currency(): HasOneThrough
    {
        return parent::currency();
    }
}
