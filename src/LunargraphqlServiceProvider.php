<?php

namespace Lunargraphql;

use Illuminate\Contracts\Events\Dispatcher;
use Lunargraphql\GraphQL\GlobalId\SmartGlobalId;
use Nuwave\Lighthouse\Events\BuildSchemaString;
use Nuwave\Lighthouse\Events\RegisterDirectiveNamespaces;
use Nuwave\Lighthouse\GlobalId\GlobalId;
use Nuwave\Lighthouse\Schema\Source\SchemaStitcher;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LunargraphqlServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * Info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('lunargraphql')
            ->hasConfigFile([
                'lunargraphql',
                'lighthouse',
            ])
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            GlobalId::class,
            SmartGlobalId::class
        );
    }

    public function packageBooted(): void
    {
        $this->app->singleton(
            GlobalId::class,
            SmartGlobalId::class
        );

        // Publish schema
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../graphql' => base_path('graphql/lunargraphql'),
            ], 'lunargraphql-schema');
        }

        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        // Register custom directives namespace
        $events->listen(RegisterDirectiveNamespaces::class, static function (): string {
            return 'Lunargraphql\\GraphQL\\Directives';
        });

        // Register schema dynamically into Lighthouse if not already matching the configured schema path
        $events->listen(BuildSchemaString::class, function (BuildSchemaString $event): string {
            if (! config('lunargraphql.auto_register_schema', true)) {
                return '';
            }

            $schemaPath = config('lighthouse.schema_path');
            if ($schemaPath && file_exists($schemaPath) && realpath($schemaPath) === realpath(__DIR__.'/../graphql/schema.graphql')) {
                return '';
            }

            $userSchema = $event->userSchema;
            $schemaChunks = [];

            // Add root types if not already defined in the user schema
            if (! preg_match('/\btype\s+Query\b/', $userSchema)) {
                $schemaChunks[] = 'type Query';
            }
            if (! preg_match('/\btype\s+Mutation\b/', $userSchema)) {
                $schemaChunks[] = 'type Mutation';
            }

            // Add scalars if not already defined in the user schema
            if (! preg_match('/\bscalar\s+Date\b/', $userSchema)) {
                $schemaChunks[] = 'scalar Date @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\Date")';
            }
            if (! preg_match('/\bscalar\s+DateTime\b/', $userSchema)) {
                $schemaChunks[] = 'scalar DateTime @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\DateTime")';
            }
            if (! preg_match('/\bscalar\s+DateTimeUtc\b/', $userSchema)) {
                $schemaChunks[] = 'scalar DateTimeUtc @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\DateTimeUtc")';
            }
            if (! preg_match('/\bscalar\s+Upload\b/', $userSchema)) {
                $schemaChunks[] = 'scalar Upload @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\Upload")';
            }

            // Stitch package GraphQL includes
            $includeFiles = [
                __DIR__.'/../graphql/includes/types.graphql',
                __DIR__.'/../graphql/includes/catalog.graphql',
                __DIR__.'/../graphql/includes/cart.graphql',
                __DIR__.'/../graphql/includes/checkout.graphql',
                __DIR__.'/../graphql/includes/customer.graphql',
                __DIR__.'/../graphql/includes/auth.graphql',
            ];

            foreach ($includeFiles as $file) {
                if (file_exists($file)) {
                    $schemaChunks[] = (new SchemaStitcher($file))->getSchemaString();
                }
            }

            return implode(PHP_EOL, $schemaChunks);
        });
    }
}
