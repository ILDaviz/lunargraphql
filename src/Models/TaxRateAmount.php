<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRateAmount extends \Lunar\Models\TaxRateAmount
{
    /**
     * Return the tax rate relation.
     */
    public function taxRate(): BelongsTo
    {
        return parent::taxRate();
    }

    /**
     * Return the tax class relation.
     */
    public function taxClass(): BelongsTo
    {
        return parent::taxClass();
    }
}
