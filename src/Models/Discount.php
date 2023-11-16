<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends \Lunar\Models\Discount
{

    /**
     * The attributes that are mass assignable.
     */
    public function users(): BelongsToMany
    {
        return parent::users();
    }

    /**
     * Return the purchasables relationship.
     */
    public function purchasables(): HasMany
    {
        return parent::purchasables();
    }

    /**
     * Return the purchasable conditions relationship.
     */
    public function purchasableConditions(): HasMany
    {
        return parent::purchasableConditions();
    }

    /**
     * Return the purchasable limitations relationship.
     */
    public function purchasableLimitations(): HasMany
    {
        return parent::purchasableLimitations();
    }

    /**
     * Return the purchasable rewards relationship.
     */
    public function purchasableRewards(): HasMany
    {
        return parent::purchasableRewards();
    }

    /**
     * Return the collections relationship.
     */
    public function collections(): HasMany
    {
        return parent::collections();
    }

    /**
     * Return the customer groups relationship.
     */
    public function customerGroups(): BelongsToMany
    {
        return parent::customerGroups();
    }

    /**
     * Return the channels relationship.
     */
    public function brands(): BelongsToMany
    {
        return parent::brands();
    }
}
