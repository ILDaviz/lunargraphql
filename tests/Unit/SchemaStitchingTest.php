<?php

use Illuminate\Contracts\Events\Dispatcher;
use Nuwave\Lighthouse\Events\BuildSchemaString;
use Nuwave\Lighthouse\Events\RegisterDirectiveNamespaces;

it('registers directive namespaces event listener', function () {
    $dispatcher = app(Dispatcher::class);
    $results = (array) $dispatcher->dispatch(new RegisterDirectiveNamespaces());

    expect($results)->toContain('Lunargraphql\\GraphQL\\Directives');
});

it('stitches package schema safely into user schema without duplicate Query type', function () {
    config(['lighthouse.schema_path' => '/tmp/host_schema.graphql']);
    $dispatcher = app(Dispatcher::class);

    $userSchema = '
        type Query {
            customField: String
        }
        type Mutation {
            customAction: Boolean
        }
    ';

    $results = (array) $dispatcher->dispatch(new BuildSchemaString($userSchema));
    $stitched = implode(PHP_EOL, $results);

    // Stitched string should not define type Query as root because userSchema already has it
    expect($stitched)->not->toContain('type Query' . PHP_EOL)
        ->and($stitched)->toContain('extend type Query')
        ->and($stitched)->toContain('type Product')
        ->and($stitched)->toContain('type Cart');
});

it('provides root Query and Mutation types when user schema is empty', function () {
    config(['lighthouse.schema_path' => '/tmp/host_schema.graphql']);
    $dispatcher = app(Dispatcher::class);

    $results = (array) $dispatcher->dispatch(new BuildSchemaString(''));
    $stitched = implode(PHP_EOL, $results);

    expect($stitched)->toContain('type Query')
        ->and($stitched)->toContain('type Mutation');
});

it('respects auto_register_schema config when disabled', function () {
    config(['lunargraphql.auto_register_schema' => false]);

    $dispatcher = app(Dispatcher::class);
    $results = (array) $dispatcher->dispatch(new BuildSchemaString(''));
    $stitched = implode(PHP_EOL, array_filter($results));

    expect($stitched)->toBeEmpty();
});
