<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAssociation extends \Lunar\Models\ProductAssociation
{
    /**
     * Return the parent relationship.
     */
    public function parent(): BelongsTo
    {
        return parent::parent();
    }

    /**
     * Return the parent relationship.
     */
    public function target(): BelongsTo
    {
        return parent::target();
    }
}
