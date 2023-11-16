<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductOptionValue extends \Lunar\Models\ProductOptionValue
{
    public function option(): BelongsTo
    {
        return parent::option();
    }

    public function variants(): BelongsToMany
    {
        return parent::variants();
    }
}
