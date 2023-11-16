<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class ProductType extends \Lunar\Models\ProductType
{
    /**
     * Get the mapped attributes relation.
     */
    public function mappedAttributes(): MorphToMany
    {
        return parent::mappedAttributes();
    }

    /**
     * Return the product attributes relationship.
     */
    public function productAttributes(): MorphToMany
    {
        return parent::productAttributes();
    }

    /**
     * Return the variant attributes relationship.
     */
    public function variantAttributes(): MorphToMany
    {
        return parent::variantAttributes();
    }

    /**
     * Get the products relation.
     */
    public function products(): HasMany
    {
        return parent::products();
    }
}
