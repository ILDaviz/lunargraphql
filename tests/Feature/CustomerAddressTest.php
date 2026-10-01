<?php

use Lunar\Core\Models\Customer;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Charlie Brown',
        'email' => 'charlie@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->customer = Customer::create([
        'first_name' => 'Charlie',
        'last_name' => 'Brown',
    ]);

    $this->user->customers()->attach($this->customer);
});

it('can create customer address', function () {
    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($address: AddressInput!) {
            createCustomerAddress(address: $address) {
                id
                firstName
                lastName
                lineOne
                city
                postcode
                shippingDefault
                billingDefault
            }
        }
    ', [
        'address' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Charlie',
            'lastName' => 'Brown',
            'lineOne' => 'Via Garibaldi 10',
            'city' => 'Roma',
            'postcode' => '00100',
            'shippingDefault' => true,
        ],
    ]);

    $response->assertJson([
        'data' => [
            'createCustomerAddress' => [
                'firstName' => 'Charlie',
                'lastName' => 'Brown',
                'lineOne' => 'Via Garibaldi 10',
                'city' => 'Roma',
                'postcode' => '00100',
                'shippingDefault' => true,
            ],
        ],
    ]);

    expect($this->customer->addresses()->count())->toBe(1);
});

it('can query customer addresses', function () {
    $this->customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Charlie',
        'last_name' => 'Brown',
        'line_one' => 'Corso Vittorio 20',
        'city' => 'Napoli',
        'postcode' => '80100',
    ]);

    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        query {
            customerAddresses {
                id
                lineOne
                city
                postcode
            }
        }
    ');

    $response->assertJson([
        'data' => [
            'customerAddresses' => [
                [
                    'lineOne' => 'Corso Vittorio 20',
                    'city' => 'Napoli',
                ],
            ],
        ],
    ]);
});

it('can update customer address', function () {
    $address = $this->customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Charlie',
        'last_name' => 'Brown',
        'line_one' => 'Via Po 5',
        'city' => 'Torino',
        'postcode' => '10100',
    ]);

    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($id: ID!, $address: AddressInput!) {
            updateCustomerAddress(id: $id, address: $address) {
                id
                city
                lineOne
            }
        }
    ', [
        'id' => $address->id,
        'address' => [
            'firstName' => 'Charlie',
            'lastName' => 'Brown',
            'lineOne' => 'Via Roma 99',
            'city' => 'Milano',
            'postcode' => '20100',
        ],
    ]);

    $response->assertJson([
        'data' => [
            'updateCustomerAddress' => [
                'city' => 'Milano',
                'lineOne' => 'Via Roma 99',
            ],
        ],
    ]);

    expect($address->fresh()->city)->toBe('Milano');
});

it('can set default customer address', function () {
    $address = $this->customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Charlie',
        'last_name' => 'Brown',
        'line_one' => 'Via Roma 1',
        'city' => 'Roma',
        'postcode' => '00100',
        'shipping_default' => false,
        'billing_default' => false,
    ]);

    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($id: ID!, $type: AddressDefaultType!) {
            setDefaultCustomerAddress(id: $id, type: $type) {
                id
                shippingDefault
                billingDefault
            }
        }
    ', [
        'id' => $address->id,
        'type' => 'BOTH',
    ]);

    $response->assertJson([
        'data' => [
            'setDefaultCustomerAddress' => [
                'shippingDefault' => true,
                'billingDefault' => true,
            ],
        ],
    ]);

    expect($address->fresh()->shipping_default)->toBeTrue();
    expect($address->fresh()->billing_default)->toBeTrue();
});

it('can delete customer address', function () {
    $address = $this->customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Charlie',
        'last_name' => 'Brown',
        'line_one' => 'Via Roma 1',
        'city' => 'Roma',
        'postcode' => '00100',
    ]);

    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($id: ID!) {
            deleteCustomerAddress(id: $id)
        }
    ', [
        'id' => $address->id,
    ]);

    $response->assertJson([
        'data' => [
            'deleteCustomerAddress' => true,
        ],
    ]);

    expect($this->customer->addresses()->count())->toBe(0);
});
