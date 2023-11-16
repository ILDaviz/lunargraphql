<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class Tag extends \Lunar\Models\Tag
{
    public function taggables(): MorphTo
    {
        return parent::taggables();
    }
}
