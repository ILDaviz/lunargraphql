<?php

use Laravel\Sanctum\Sanctum;
use Lunar\Core\Models\Customer;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->customer = Customer::create([
        'title' => 'Mr',
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'company_name' => 'Acme Corp',
        'tax_identifier' => 'IT12345678901',
        'account_ref' => 'REF-001',
    ]);

    $this->user->customers()->attach($this->customer);
});

it('can query the authenticated customer profile', function () {
    Sanctum::actingAs($this->user);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            customer {
                id
                title
                firstName
                lastName
                companyName
                vatNo
                accountRef
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.customer');

    expect($data['title'])->toBe('Mr')
        ->and($data['firstName'])->toBe('Mario')
        ->and($data['lastName'])->toBe('Rossi')
        ->and($data['companyName'])->toBe('Acme Corp')
        ->and($data['vatNo'])->toBe('IT12345678901')
        ->and($data['accountRef'])->toBe('REF-001');
});

it('can update customer profile details', function () {
    Sanctum::actingAs($this->user);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            updateCustomerProfile(
                title: "Dr"
                firstName: "Luigi"
                lastName: "Verdi"
                companyName: "Global Solutions"
                vatNo: "IT98765432109"
                accountRef: "ACC-999"
            ) {
                id
                title
                firstName
                lastName
                companyName
                vatNo
                accountRef
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.updateCustomerProfile');

    expect($data['title'])->toBe('Dr')
        ->and($data['firstName'])->toBe('Luigi')
        ->and($data['lastName'])->toBe('Verdi')
        ->and($data['companyName'])->toBe('Global Solutions')
        ->and($data['vatNo'])->toBe('IT98765432109')
        ->and($data['accountRef'])->toBe('ACC-999');

    $this->customer->refresh();
    expect($this->customer->first_name)->toBe('Luigi')
        ->and($this->customer->last_name)->toBe('Verdi')
        ->and($this->customer->company_name)->toBe('Global Solutions');
});

it('can update authenticated user profile details', function () {
    Sanctum::actingAs($this->user);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            updateUserProfile(
                name: "Mario Super Rossi"
                email: "mario.super@example.com"
            ) {
                id
                name
                email
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.updateUserProfile');

    expect($data['name'])->toBe('Mario Super Rossi')
        ->and($data['email'])->toBe('mario.super@example.com');

    $this->user->refresh();
    expect($this->user->name)->toBe('Mario Super Rossi')
        ->and($this->user->email)->toBe('mario.super@example.com');
});

it('prevents unauthenticated access to customer profile and updates', function () {
    $queryResponse = $this->graphQL(/** @lang GraphQL */ '
        query {
            customer {
                id
            }
        }
    ');

    $queryResponse->assertJson([
        'data' => [
            'customer' => null,
        ],
    ]);
    expect($queryResponse->json('errors.0.message'))->toContain('Unauthenticated');

    $mutationResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            updateCustomerProfile(firstName: "Hacker") {
                id
            }
        }
    ');

    expect($mutationResponse->json('errors.0.message'))->toContain('Unauthenticated')
        ->and($mutationResponse->json('data.updateCustomerProfile'))->toBeNull();
});
