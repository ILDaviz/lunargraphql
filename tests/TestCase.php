<?php

namespace Lunargraphql\Tests;

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
use Lunargraphql\LunargraphqlServiceProvider;
use Lunargraphql\Tests\Models\User;
use Nuwave\Lighthouse\LighthouseServiceProvider;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

class TestCase extends Orchestra
{
    use RefreshDatabase, MakesGraphQLRequests;

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

        $this->seedLunarBaselines();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Lunar\Nestedset\NestedSetServiceProvider::class,
            \Spatie\ModelStates\ModelStatesServiceProvider::class,
            LunarServiceProvider::class,
            LighthouseServiceProvider::class,
            \Nuwave\Lighthouse\Async\AsyncServiceProvider::class,
            \Nuwave\Lighthouse\Auth\AuthServiceProvider::class,
            \Nuwave\Lighthouse\Bind\BindServiceProvider::class,
            \Nuwave\Lighthouse\Cache\CacheServiceProvider::class,
            \Nuwave\Lighthouse\GlobalId\GlobalIdServiceProvider::class,
            \Nuwave\Lighthouse\OrderBy\OrderByServiceProvider::class,
            \Nuwave\Lighthouse\Pagination\PaginationServiceProvider::class,
            \Nuwave\Lighthouse\SoftDeletes\SoftDeletesServiceProvider::class,
            \Nuwave\Lighthouse\Testing\TestingServiceProvider::class,
            \Nuwave\Lighthouse\Validation\ValidationServiceProvider::class,
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
        $app['config']->set('lighthouse.debug', \GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE | \GraphQL\Error\DebugFlag::INCLUDE_TRACE);
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
