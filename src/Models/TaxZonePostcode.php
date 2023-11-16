<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxZonePostcode extends \Lunar\Models\TaxZonePostcode
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
