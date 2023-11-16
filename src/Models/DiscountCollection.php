<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountCollection extends \Lunar\Models\DiscountCollection
{
    /**
     * Return the discount relationship.
     */
    public function discount(): BelongsTo
    {
        return parent::discount();
    }

    public function collection(): BelongsTo
    {
        return parent::collection();
    }
}
