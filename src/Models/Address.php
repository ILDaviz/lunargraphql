<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends \Lunar\Models\Address
{
    /**
     * Return the country relationship.
     */
    public function country(): BelongsTo
    {
        return parent::country();
    }

    /**
     * Return the customer relationship.
     */
    public function customer(): BelongsTo
    {
        return parent::customer();
    }
}
