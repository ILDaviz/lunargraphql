<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductVariant extends \Lunar\Models\ProductVariant
{
    /**
     * The related product.
     */
    public function product(): BelongsTo
    {
        return parent::product();
    }

    /**
     * Return the tax class relationship.
     */
    public function taxClass(): BelongsTo
    {
        return parent::taxClass();
    }

    /**
     * Return the related product option values.
     */
    public function values(): BelongsToMany
    {
        return parent::values();
    }

    public function images(): BelongsToMany
    {
        return parent::images();
    }
}
