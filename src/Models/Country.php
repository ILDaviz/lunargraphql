<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends \Lunar\Models\Country
{
    /**
     * Return the states relationship.
     */
    public function states(): HasMany
    {
        return parent::states();
    }
}
