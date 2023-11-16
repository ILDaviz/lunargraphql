<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Product extends \Lunar\Models\Product
{
    /**
     * Return the product type relation.
     */
    public function productType(): BelongsTo
    {
        return parent::productType();
    }

    /**
     * Return the product images relation.
     */
    public function images(): HasMany
    {
        return $this->hasMany(config('media-library.media_model'), 'model_id')
            ->where('collection_name', 'images')
            ->where('model_type', 'Lunar\Models\Product');
    }

    /**
     * Return the product variants relation.
     */
    public function variants(): HasMany
    {
        return parent::variants();
    }

    /**
     * Return the product collections relation.
     */
    public function collections(): BelongsToMany
    {
        return parent::collections();
    }

    /**
     * Return the associations relationship.
     */
    public function associations(): HasMany
    {
        return parent::associations();
    }

    /**
     * Return the associations relationship.
     */
    public function inverseAssociations(): HasMany
    {
        return parent::inverseAssociations();
    }

    /**
     * Return the customer groups relationship.
     */
    public function customerGroups(): BelongsToMany
    {
        return parent::customerGroups();
    }

    /**
     * Return the brand relationship.
     */
    public function brand(): BelongsTo
    {
        return parent::brand();
    }

    /**
     * Return the prices relationship.
     */
    public function prices(): HasManyThrough
    {
        return parent::prices();
    }
}
