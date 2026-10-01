<?php

use Lunargraphql\GraphQL\Directives\HasOneThroughDirective;
use Nuwave\Lighthouse\Schema\DirectiveLocator;

it('has a valid GraphQL schema definition for hasOneThrough', function () {
    $definition = HasOneThroughDirective::definition();

    expect($definition)
        ->toContain('directive @hasOneThrough')
        ->toContain('relation: String')
        ->toContain('scopes: [String!]')
        ->toContain('on FIELD_DEFINITION');
});

it('is discoverable by lighthouse directive locator', function () {
    $directiveLocator = app(DirectiveLocator::class);
    $directive = $directiveLocator->create('hasOneThrough');

    expect($directive)->toBeInstanceOf(HasOneThroughDirective::class);
});
