<?php

use Laravel\Sanctum\Sanctum;
use Lunar\Core\Models\Address;
use Lunar\Core\Models\Customer;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Luigi Verdi',
        'email' => 'luigi.verdi@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $this->customer = Customer::create([
        'title' => 'Mr',
        'first_name' => 'Luigi',
        'last_name' => 'Verdi',
        'company_name' => 'Verdi Design',
        'tax_identifier' => 'IT98765432100',
    ]);

    $this->user->customers()->attach($this->customer);

    $this->shippingAddr = Address::create([
        'customer_id' => $this->customer->id,
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Luigi',
        'last_name' => 'Verdi',
        'line_one' => 'Via Dante 10',
        'city' => 'Milano',
        'postcode' => '20100',
        'shipping_default' => true,
        'billing_default' => false,
    ]);

    $this->billingAddr = Address::create([
        'customer_id' => $this->customer->id,
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Luigi',
        'last_name' => 'Verdi',
        'company_name' => 'Verdi Design',
        'line_one' => 'Corso Buenos Aires 25',
        'city' => 'Milano',
        'postcode' => '20124',
        'shipping_default' => false,
        'billing_default' => true,
    ]);
});

it('can query single customer address by ID', function () {
    Sanctum::actingAs($this->user);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetAddress($id: ID!) {
            customerAddress(id: $id) {
                id
                lineOne
                city
                postcode
                shippingDefault
                billingDefault
            }
        }
    ', [
        'id' => $this->shippingAddr->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.customerAddress');

    expect($data['lineOne'])->toBe('Via Dante 10')
        ->and($data['city'])->toBe('Milano')
        ->and($data['shippingDefault'])->toBeTrue()
        ->and($data['billingDefault'])->toBeFalse();
});

it('can query customer default shipping and billing addresses', function () {
    Sanctum::actingAs($this->user);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            customer {
                id
                firstName
                lastName
                defaultShippingAddress {
                    id
                    lineOne
                    shippingDefault
                }
                defaultBillingAddress {
                    id
                    lineOne
                    billingDefault
                }
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.customer');

    expect($data['defaultShippingAddress']['lineOne'])->toBe('Via Dante 10')
        ->and($data['defaultShippingAddress']['shippingDefault'])->toBeTrue()
        ->and($data['defaultBillingAddress']['lineOne'])->toBe('Corso Buenos Aires 25')
        ->and($data['defaultBillingAddress']['billingDefault'])->toBeTrue();
});

it('can query authenticated user with latestCustomer', function () {
    Sanctum::actingAs($this->user);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            me {
                id
                name
                email
                latestCustomer {
                    id
                    firstName
                    lastName
                    companyName
                }
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.me');

    expect($data['name'])->toBe('Luigi Verdi')
        ->and($data['email'])->toBe('luigi.verdi@example.com')
        ->and($data['latestCustomer'])->not->toBeNull()
        ->and($data['latestCustomer']['companyName'])->toBe('Verdi Design');
});
