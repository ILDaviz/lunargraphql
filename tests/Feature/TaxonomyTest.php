<?php

use Lunar\Core\Models\CollectionGroup;
use Lunar\Core\Models\CustomerGroup;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\Tag;

it('can query languages', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            languages {
                id
                code
                name
                default
            }
        }
    ');

    $response->assertSuccessful();
    $languages = $response->json('data.languages');

    expect($languages)->not->toBeEmpty()
        ->and($languages[0]['code'])->toBe('en')
        ->and($languages[0]['default'])->toBeTrue();
});

it('can query countries', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            countries {
                id
                name
                iso3
                iso2
                phonecode
            }
        }
    ');

    $response->assertSuccessful();
    $countries = $response->json('data.countries');

    expect($countries)->not->toBeEmpty();
    $italy = collect($countries)->firstWhere('iso3', 'ITA');
    expect($italy)->not->toBeNull()
        ->and($italy['name'])->toBe('Italy')
        ->and($italy['iso2'])->toBe('IT')
        ->and($italy['phonecode'])->toBe('39');
});

it('can query tags', function () {
    Tag::create(['value' => 'SPRING_COLLECTION']);
    Tag::create(['value' => 'DISCOUNTED']);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            tags {
                id
                value
            }
        }
    ');

    $response->assertSuccessful();
    $tags = $response->json('data.tags');

    $values = collect($tags)->pluck('value')->all();
    expect($values)->toContain('SPRING_COLLECTION', 'DISCOUNTED');
});

it('can query product types', function () {
    ProductType::create([
        'name' => 'Apparel',
        'handle' => 'apparel',
        'status' => 'active',
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            productTypes {
                id
                name
                handle
                status
            }
        }
    ');

    $response->assertSuccessful();
    $types = $response->json('data.productTypes');

    $apparel = collect($types)->firstWhere('handle', 'apparel');
    expect($apparel)->not->toBeNull()
        ->and($apparel['name'])->toBe('Apparel')
        ->and($apparel['status'])->toBe('active');
});

it('can query collection groups', function () {
    CollectionGroup::create([
        'name' => 'Promotions Group',
        'handle' => 'promotions-group',
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            collectionGroups {
                id
                name
                handle
            }
        }
    ');

    $response->assertSuccessful();
    $groups = $response->json('data.collectionGroups');

    $promoGroup = collect($groups)->firstWhere('handle', 'promotions-group');
    expect($promoGroup)->not->toBeNull()
        ->and($promoGroup['name'])->toBe('Promotions Group');
});

it('can query customer groups', function () {
    CustomerGroup::create([
        'name' => 'VIP Members',
        'handle' => 'vip-members',
        'default' => false,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            customerGroups {
                id
                name
                handle
                default
            }
        }
    ');

    $response->assertSuccessful();
    $groups = $response->json('data.customerGroups');

    $vipGroup = collect($groups)->firstWhere('handle', 'vip-members');
    expect($vipGroup)->not->toBeNull()
        ->and($vipGroup['name'])->toBe('VIP Members')
        ->and($vipGroup['default'])->toBeFalse();
});
