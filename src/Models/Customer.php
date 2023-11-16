<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Customer extends \Lunar\Models\Customer
{
    /**
     * Return the customer group relationship.
     */
    public function customerGroups(): BelongsToMany
    {
        return parent::customerGroups();
    }

    /**
     * Return the customer group relationship.
     */
    public function users(): BelongsToMany
    {
        return parent::users();
    }

    /**
     * Return the addresses relationship.
     */
    public function addresses(): HasMany
    {
        return parent::addresses();
    }

    /**
     * Return the orders relationship.
     */
    public function orders(): HasMany
    {
        return parent::orders();
    }

    /**
     * Get the mapped attributes relation.
     */
    public function mappedAttributes():MorphToMany
    {
        return parent::mappedAttributes();
    }
}
