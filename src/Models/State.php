<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class State extends \Lunar\Models\State
{
    /**
     * Return the country relationship.
     */
    public function country(): BelongsTo
    {
        return parent::country();
    }
}
