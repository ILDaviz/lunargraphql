<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Lunargraphql\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(TestCase::class)->in('Feature', 'Unit');
uses(MakesGraphQLRequests::class)->in('Types');

function actingAs(Authenticatable $user, string $driver = null): TestCase
{
    return test()->actingAs($user, $driver);
}
