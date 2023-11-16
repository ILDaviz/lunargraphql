<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOption extends \Lunar\Models\ProductOption
{
    /**
     * Get the values.
     */
    public function values(): HasMany
    {
        return parent::values();
    }
}
