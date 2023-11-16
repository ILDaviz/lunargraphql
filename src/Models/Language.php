<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends \Lunar\Models\Language
{
    /**
     * Return the URLs relationship
     */
    public function urls(): HasMany
    {
        return parent::urls();
    }
}
