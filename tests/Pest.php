<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Lunargraphql\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(TestCase::class)->in('Feature', 'Unit', 'Types');

function actingAs(Authenticatable $user, ?string $driver = null): TestCase
{
    return test()->actingAs($user, $driver);
}
