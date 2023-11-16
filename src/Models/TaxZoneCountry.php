<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxZoneCountry extends \Lunar\Models\TaxZoneCountry
{
    /**
     * Return the tax zone relation.
     */
    public function taxZone(): BelongsTo
    {
        return parent::taxZone();
    }

    /**
     * Return the country relation.
     */
    public function country(): BelongsTo
    {
        return parent::country();
    }
}
