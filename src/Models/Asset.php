<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\MorphOne;

class Asset extends \Lunar\Models\Asset
{
    public function file(): MorphOne
    {
        return parent::file();
    }
}
