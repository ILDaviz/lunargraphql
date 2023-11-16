<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attribute extends \Lunar\Models\Attribute
{
    /**
     * Return the attribuable relation.
     */
    public function attributable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Returns the attribute group relation.
     */
    public function attributeGroup(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class);
    }
}
