<?php

namespace Lunargraphql\Traits;

use Illuminate\Database\Eloquent\Builder;

trait UseLunargraphqlUsers
{
    public function getUserModel(): Builder
    {
        if (! config('lunargraphql.user_model')) {
            throw new \Exception('User model not found');
        }

        $className = config('lunargraphql.user_model');

        return (new $className)::query();
    }

    public function getUserAuthProvider(): string
    {
        return config('lunargraphql.user_auth_provider');
    }
}
