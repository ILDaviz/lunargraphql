<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends \Lunar\Models\Collection
{
    /**
     * Return the group relationship.
     */
    public function group(): BelongsTo
    {
        return parent::group();
    }

    /**
     * Return the products relationship.
     */
    public function products(): BelongsToMany
    {
        return parent::products();
    }

    /**
     * Return the customer groups relationship.
     */
    public function customerGroups(): BelongsToMany
    {
        return parent::customerGroups();
    }
}
