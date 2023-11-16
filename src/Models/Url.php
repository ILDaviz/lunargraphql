<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Url extends \Lunar\Models\Url
{
    /**
     * Return the element relationship.
     */
    public function element(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the language relationship.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
