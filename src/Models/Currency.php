<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends \Lunar\Models\Currency
{
    /**
     * Return the prices relationship
     */
    public function prices(): HasMany
    {
        return parent::prices();
    }
}
