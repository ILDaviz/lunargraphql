<?php

namespace Lunargraphql\Tests;

use GraphQL\Error\DebugFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Scout\ScoutServiceProvider;
use Lunar\Core\LunarServiceProvider;
use Lunar\Core\Models\Channel;
use Lunar\Core\Models\Country;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Language;
use Lunar\Core\Models\Region;
use Lunar\Core\Models\TaxClass;
use Lunar\Core\Models\TaxZone;
use Lunar\Nestedset\NestedSetServiceProvider;
use Lunargraphql\LunargraphqlServiceProvider;
use Lunargraphql\Tests\Models\User;
use Nuwave\Lighthouse\Async\AsyncServiceProvider;
use Nuwave\Lighthouse\Auth\AuthServiceProvider;
use Nuwave\Lighthouse\Bind\BindServiceProvider;
use Nuwave\Lighthouse\Cache\CacheServiceProvider;
use Nuwave\Lighthouse\GlobalId\GlobalIdServiceProvider;
use Nuwave\Lighthouse\LighthouseServiceProvider;
use Nuwave\Lighthouse\OrderBy\OrderByServiceProvider;
use Nuwave\Lighthouse\Pagination\PaginationServiceProvider;
use Nuwave\Lighthouse\SoftDeletes\SoftDeletesServiceProvider;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Nuwave\Lighthouse\Testing\TestingServiceProvider;
use Nuwave\Lighthouse\Validation\ValidationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\ModelStates\ModelStatesServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

class TestCase extends Orchestra
{
    use MakesGraphQLRequests, RefreshDatabase;

    protected Channel $defaultChannel;

    protected Currency $defaultCurrency;

    protected Language $defaultLanguage;

    protected TaxClass $defaultTaxClass;

    protected TaxZone $defaultTaxZone;

    protected Region $defaultRegion;

    protected Country $defaultCountry;

    protected function setUp(): void
    {
        parent::setUp();

        app('session')->driver()->flush();

        $this->seedLunarBaselines();
    }

    protected function getPackageProviders($app): array
    {
        return [
            NestedSetServiceProvider::class,
            ModelStatesServiceProvider::class,
            LunarServiceProvider::class,
            LighthouseServiceProvider::class,
            AsyncServiceProvider::class,
            AuthServiceProvider::class,
            BindServiceProvider::class,
            CacheServiceProvider::class,
            GlobalIdServiceProvider::class,
            OrderByServiceProvider::class,
            PaginationServiceProvider::class,
            SoftDeletesServiceProvider::class,
            TestingServiceProvider::class,
            ValidationServiceProvider::class,
            SanctumServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            PermissionServiceProvider::class,
            ScoutServiceProvider::class,
            BlinkServiceProvider::class,
            LunargraphqlServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('lunargraphql.user_model', User::class);
        $app['config']->set('lunargraphql.user_auth_provider', 'users');

        $app['config']->set('lighthouse.schema_path', __DIR__.'/../graphql/schema.graphql');
        $app['config']->set('lighthouse.namespaces.models', [
            'Lunar\\Core\\Models',
            'Lunargraphql\\Tests\\Models',
        ]);
        $app['config']->set('lighthouse.namespaces.directives', [
            'Lunargraphql\\GraphQL\\Directives',
            'Nuwave\\Lighthouse\\Schema\\Directives',
        ]);
        $app['config']->set('app.debug', true);
        $app['config']->set('lighthouse.debug', DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE);
        $app['config']->set('lighthouse.schema_cache.enable', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function ($table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function seedLunarBaselines(): void
    {
        $this->defaultCurrency = Currency::firstOrCreate(['code' => 'EUR'], [
            'name' => 'Euro',
            'exchange_rate' => 1.0,
            'decimal_places' => 2,
            'default' => true,
            'enabled' => true,
        ]);

        Currency::firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'exchange_rate' => 1.10,
            'decimal_places' => 2,
            'default' => false,
            'enabled' => true,
        ]);

        Currency::firstOrCreate(['code' => 'GBP'], [
            'name' => 'British Pound',
            'exchange_rate' => 0.85,
            'decimal_places' => 2,
            'default' => false,
            'enabled' => true,
        ]);

        $this->defaultChannel = Channel::firstOrCreate(['handle' => 'webstore'], [
            'name' => 'Webstore',
            'default' => true,
            'url' => 'http://localhost',
        ]);

        $this->defaultLanguage = Language::firstOrCreate(['code' => 'en'], [
            'name' => 'English',
            'default' => true,
        ]);

        $this->defaultTaxClass = TaxClass::firstOrCreate(['name' => 'Standard Tax'], [
            'default' => true,
        ]);

        $this->defaultTaxZone = TaxZone::firstOrCreate(['name' => 'Default Tax Zone'], [
            'zone_type' => 'country',
            'active' => true,
            'default' => true,
        ]);

        $this->defaultCountry = Country::firstOrCreate(['iso3' => 'ITA'], [
            'name' => 'Italy',
            'iso2' => 'IT',
            'phonecode' => '39',
            'capital' => 'Rome',
            'currency' => 'EUR',
            'native' => 'Italia',
            'emoji' => '🇮🇹',
            'emoji_u' => 'U+1F1EE U+1F1F9',
        ]);

        $this->defaultRegion = Region::firstOrCreate(['handle' => 'default'], [
            'name' => 'Default Region',
            'default' => true,
            'channel_id' => $this->defaultChannel->id,
            'currency_id' => $this->defaultCurrency->id,
            'language_id' => $this->defaultLanguage->id,
            'tax_zone_id' => $this->defaultTaxZone->id,
            'prices_inc_tax' => false,
        ]);
    }
}
