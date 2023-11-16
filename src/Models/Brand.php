<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Brand extends \Lunar\Models\Brand
{
    /**
     * Get the mapped attributes relation.
     */
    public function mappedAttributes(): MorphToMany
    {
        return parent::mappedAttributes();
    }

    /**
     * Return the product relationship.
     */
    public function products(): HasMany
    {
        return parent::products();
    }
}
