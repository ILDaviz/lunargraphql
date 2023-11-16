<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\ProductVariant;

class TaxClass extends \Lunar\Models\TaxClass
{
    /**
     * Return the tax rate amounts relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function taxRateAmounts(): HasMany
    {
        return parent::taxRateAmounts();
    }

    /**
     * Return the ProductVariants relationship.
     *
     * @return HasMany
     */
    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
