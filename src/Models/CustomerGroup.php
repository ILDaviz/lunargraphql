<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerGroup extends \Lunar\Models\CustomerGroup
{
    /**
     * Return the customer's relationship.
     */
    public function customers(): BelongsToMany
    {
        return parent::customers();
    }
}
