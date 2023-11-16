<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxZoneState extends \Lunar\Models\TaxZoneState
{
    /**
     * Return the tax zone relation.
     */
    public function taxZone(): BelongsTo
    {
        return parent::taxZone();
    }

    /**
     * Return the state relation.
     */
    public function state(): BelongsTo
    {
        return parent::state();
    }
}
