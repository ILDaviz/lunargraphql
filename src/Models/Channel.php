<?php

namespace Lunargraphql\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class Channel extends \Lunar\Models\Channel
{
    /**
     * Get the parent channelable model.
     */
    public function channelable(): MorphTo
    {
        return parent::channelable();
    }
}
