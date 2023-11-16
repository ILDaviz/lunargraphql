<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionGroup extends \Lunar\Models\CollectionGroup
{
    public function collections(): HasMany
    {
        return parent::collections();
    }
}
