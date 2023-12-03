<?php

namespace Lunargraphql;

use Illuminate\Support\Facades\Gate;
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
            ]);
    }
}
