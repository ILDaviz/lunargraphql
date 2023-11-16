<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeGroup extends \Lunar\Models\AttributeGroup
{
    /**
     * Return the attributes relationship.
     */
    public function attributes(): HasMany
    {
        return parent::attributes();
    }
}
