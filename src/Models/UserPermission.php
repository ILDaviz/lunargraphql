<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends \Lunar\Models\UserPermission
{
    /**
     * Return the user relationship.
     */
    public function user(): BelongsTo
    {
        return parent::user();
    }
}
