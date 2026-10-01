<?php

use Illuminate\Support\Facades\Hash;
use Lunar\Core\Models\Customer;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'password' => Hash::make('password123'),
    ]);

    $this->customer = Customer::create([
        'first_name' => 'Alice',
        'last_name' => 'Smith',
    ]);

    $this->user->customers()->attach($this->customer);
});

it('can register a new user and return sanctum token', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($name: String!, $email: String!, $password: String!) {
            createUser(name: $name, email: $email, password: $password) {
                token
                user {
                    id
                    name
                    email
                    customers {
                        id
                        firstName
                        lastName
                    }
                }
            }
        }
    ', [
        'name' => 'Bob Builder',
        'email' => 'bob@example.com',
        'password' => 'secret12345',
    ]);

    $response->assertJson([
        'data' => [
            'createUser' => [
                'user' => [
                    'name' => 'Bob Builder',
                    'email' => 'bob@example.com',
                ],
            ],
        ],
    ]);

    expect($response->json('data.createUser.token'))->not->toBeEmpty();

    $newUser = User::where('email', 'bob@example.com')->first();
    expect($newUser)->not->toBeNull();
    expect(Hash::check('secret12345', $newUser->password))->toBeTrue();
    expect($newUser->customers()->count())->toBe(1);
});

it('can login with correct credentials and returns token', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($email: String!, $password: String!) {
            login(email: $email, password: $password) {
                token
                user {
                    name
                    email
                }
            }
        }
    ', [
        'email' => 'alice@example.com',
        'password' => 'password123',
    ]);

    $response->assertJson([
        'data' => [
            'login' => [
                'user' => [
                    'name' => 'Alice Smith',
                    'email' => 'alice@example.com',
                ],
            ],
        ],
    ]);

    expect($response->json('data.login.token'))->not->toBeEmpty();
});

it('rejects login with wrong password', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($email: String!, $password: String!) {
            login(email: $email, password: $password) {
                token
            }
        }
    ', [
        'email' => 'alice@example.com',
        'password' => 'wrong-pass',
    ]);

    expect($response->json('errors'))->not->toBeNull();
});

it('can get authenticated user details via me query', function () {
    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        query {
            me {
                id
                name
                email
                customers {
                    id
                    firstName
                    lastName
                }
            }
        }
    ');

    $response->assertJson([
        'data' => [
            'me' => [
                'name' => 'Alice Smith',
                'email' => 'alice@example.com',
                'customers' => [
                    [
                        'firstName' => 'Alice',
                        'lastName' => 'Smith',
                    ],
                ],
            ],
        ],
    ]);
});

it('can logout and revoke tokens', function () {
    $token = $this->user->createToken('test-token');

    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation {
            logout
        }
    ');

    $response->assertJson([
        'data' => [
            'logout' => true,
        ],
    ]);

    expect($this->user->fresh()->tokens()->count())->toBe(0);
});

it('can reset password for authenticated user', function () {
    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($password: String!) {
            resetPassword(password: $password)
        }
    ', [
        'password' => 'new-secure-password',
    ]);

    $response->assertJson([
        'data' => [
            'resetPassword' => true,
        ],
    ]);

    expect(Hash::check('new-secure-password', $this->user->fresh()->password))->toBeTrue();
});
