<?php

use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Collection;
use Lunar\Core\Models\CollectionGroup;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Price;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductOption;
use Lunar\Core\Models\ProductOptionValue;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\Models\TaxClass;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'default-type'], [
        'name' => 'Default Type',
        'status' => 'active',
    ]);

    $this->brand = Brand::firstOrCreate(['name' => 'Acme Brand']);

    $this->collectionGroup = CollectionGroup::firstOrCreate(['handle' => 'main'], [
        'name' => 'Main Group',
    ]);

    $this->collection = Collection::firstOrCreate(['collection_group_id' => $this->collectionGroup->id], [
        'name' => [
            'en' => 'Main Collection',
        ],
        'collection_group_id' => $this->collectionGroup->id,
    ]);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
        'status' => 'published',
        'name' => [
            'en' => 'Awesome Sneakers',
            'it' => 'Scarpe Fantastiche',
        ],
        'description' => [
            'en' => 'The best running sneakers in the world.',
        ],
        'short_description' => [
            'en' => 'Best sneakers.',
        ],
    ]);

    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    $this->product->collections()->sync([$this->collection->id]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'SNK-001-RED',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'stock_on_hand' => 15,
        'stock_available' => 15,
    ]);

    $this->price = $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 9999, // 99.99 EUR
        'list_price' => 12999, // 129.99 EUR
        'min_quantity' => 1,
    ]);

    $this->option = ProductOption::create([
        'name' => ['en' => 'Color'],
        'label' => ['en' => 'Color'],
        'handle' => 'color',
        'shared' => true,
    ]);

    $this->optionValue = ProductOptionValue::create([
        'product_option_id' => $this->option->id,
        'name' => ['en' => 'Red'],
        'position' => 1,
    ]);

    $this->variant->values()->sync([$this->optionValue->id]);
});

it('can fetch products via catalog query with pagination', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog(first: 10) {
                data {
                    id
                    status
                    name
                    description
                    shortDescription
                    nameTranslations {
                        lang
                        value
                    }
                    brand {
                        id
                        name
                    }
                    variants {
                        id
                        sku
                        sellingPolicy
                        stockOnHand
                        prices {
                            price
                            priceFormatted
                            priceDecimal
                            listPrice
                            listPriceFormatted
                        }
                        values {
                            id
                            name
                            option {
                                id
                                name
                            }
                        }
                    }
                }
                paginatorInfo {
                    total
                    count
                    currentPage
                }
            }
        }
    ');

    $response->assertJson([
        'data' => [
            'catalog' => [
                'paginatorInfo' => [
                    'total' => 1,
                    'count' => 1,
                ],
                'data' => [
                    [
                        'status' => 'published',
                        'name' => 'Awesome Sneakers',
                        'description' => 'The best running sneakers in the world.',
                        'shortDescription' => 'Best sneakers.',
                        'brand' => [
                            'name' => 'Acme Brand',
                        ],
                        'variants' => [
                            [
                                'sku' => 'SNK-001-RED',
                                'sellingPolicy' => 'always',
                                'stockOnHand' => 15,
                                'prices' => [
                                    [
                                        'price' => 9999,
                                        'listPrice' => 12999,
                                    ],
                                ],
                                'values' => [
                                    [
                                        'name' => 'Red',
                                        'option' => [
                                            'name' => 'Color',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

it('can filter catalog by search text', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($search: String!) {
            catalog(first: 10, filter: { search: $search }) {
                data {
                    id
                    name
                }
            }
        }
    ', ['search' => 'Sneakers']);

    $response->assertJsonCount(1, 'data.catalog.data');

    $emptyResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($search: String!) {
            catalog(first: 10, filter: { search: $search }) {
                data {
                    id
                    name
                }
            }
        }
    ', ['search' => 'NonExistentProduct']);

    $emptyResponse->assertJsonCount(0, 'data.catalog.data');
});

it('can filter catalog by channel and collection', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($channels: [ID!], $collections: [ID!]) {
            catalog(first: 10, filter: { channels: $channels, collections: $collections }) {
                data {
                    id
                    name
                }
            }
        }
    ', [
        'channels' => [$this->defaultChannel->id],
        'collections' => [$this->collection->id],
    ]);

    $response->assertJsonCount(1, 'data.catalog.data');
});

it('can filter catalog by price range', function () {
    // Matched range: 50.00 to 150.00 (variant price is 99.99)
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($min: Float, $max: Float) {
            catalog(first: 10, filter: { priceRange: { min: $min, max: $max } }) {
                data {
                    id
                    name
                }
            }
        }
    ', ['min' => 50.0, 'max' => 150.0]);

    $response->assertJsonCount(1, 'data.catalog.data');

    // Mismatched range: 120.00 to 200.00
    $unmatched = $this->graphQL(/** @lang GraphQL */ '
        query ($min: Float, $max: Float) {
            catalog(first: 10, filter: { priceRange: { min: $min, max: $max } }) {
                data {
                    id
                    name
                }
            }
        }
    ', ['min' => 120.0, 'max' => 200.0]);

    $unmatched->assertJsonCount(0, 'data.catalog.data');
});

it('can query a single product by ID', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            product(id: $id) {
                id
                name
                status
                brand {
                    name
                }
                variants {
                    sku
                }
            }
        }
    ', ['id' => $this->product->id]);

    $response->assertJson([
        'data' => [
            'product' => [
                'name' => 'Awesome Sneakers',
                'status' => 'published',
                'brand' => [
                    'name' => 'Acme Brand',
                ],
                'variants' => [
                    ['sku' => 'SNK-001-RED'],
                ],
            ],
        ],
    ]);
});

it('can query a single variant by ID', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            productVariant(id: $id) {
                id
                sku
                sellingPolicy
                product {
                    name
                }
            }
        }
    ', ['id' => $this->variant->id]);

    $response->assertJson([
        'data' => [
            'productVariant' => [
                'sku' => 'SNK-001-RED',
                'sellingPolicy' => 'always',
                'product' => [
                    'name' => 'Awesome Sneakers',
                ],
            ],
        ],
    ]);
});

it('can query collections, brands, channels, currencies, and regions', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            collections {
                id
            }
            brands {
                id
                name
            }
            currencies {
                id
                code
            }
            channels {
                id
                handle
            }
            regions {
                id
                handle
            }
        }
    ');

    $response->assertJsonStructure([
        'data' => [
            'collections',
            'brands',
            'currencies',
            'channels',
            'regions',
        ],
    ]);

    expect($response->json('data.brands.0.name'))->toBe('Acme Brand');
    expect($response->json('data.currencies.0.code'))->toBe('EUR');
    expect($response->json('data.channels.0.handle'))->toBe('webstore');
    expect($response->json('data.regions.0.handle'))->toBe('default');
});

it('can query catalog with null or empty filter without error', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetCatalog($filter: CatalogFilterInput) {
            catalog(first: 5, filter: $filter) {
                data {
                    id
                    name
                }
                paginatorInfo {
                    total
                }
            }
        }
    ', ['filter' => null]);

    $response->assertStatus(200);
    expect($response->json('data.catalog.paginatorInfo.total'))->toBeGreaterThanOrEqual(1);

    $responseEmpty = $this->graphQL(/** @lang GraphQL */ '
        query GetCatalog($filter: CatalogFilterInput) {
            catalog(first: 5, filter: $filter) {
                data {
                    id
                    name
                }
            }
        }
    ', ['filter' => []]);

    $responseEmpty->assertStatus(200);
});

