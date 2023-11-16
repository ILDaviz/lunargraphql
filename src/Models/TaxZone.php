<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TaxZone extends \Lunar\Models\TaxZone
{
    /**
     * Return the countries relationship.
     *
     * @return HasMany
     */
    public function countries(): HasMany
    {
        return parent::countries();
    }

    /**
     * Return the states relationship.
     *
     * @return HasMany
     */
    public function states(): HasMany
    {
        return parent::states();
    }

    /**
     * Return the postcodes relationship.
     *
     * @return HasMany
     */
    public function postcodes(): HasMany
    {
        return parent::postcodes();
    }

    /**
     * Return the customer groups relationship.
     *
     * @return HasMany
     */
    public function customerGroups(): HasMany
    {
        return parent::customerGroups();
    }

    /**
     * Return the tax rates relationship.
     */
    public function taxRates(): HasMany
    {
        return parent::taxRates();
    }

    /**
     * Return the tax amounts relationship.
     */
    public function taxAmounts(): HasManyThrough
    {
        return parent::taxAmounts();
    }
}
