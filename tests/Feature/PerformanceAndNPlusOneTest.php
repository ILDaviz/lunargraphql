<?php

use Illuminate\Support\Facades\DB;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Price;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductVariant;
use Lunargraphql\Tests\Models\User;
use Lunar\Core\Models\Order;

it('avoids N+1 queries when querying product catalog with prices and media', function () {
    $currency = Currency::getDefault();

    // Create 6 products with variants and prices
    for ($i = 1; $i <= 6; $i++) {
        $product = Product::factory()->create([
            'status' => 'published',
            'name' => ['en' => "Product {$i}"],
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => "SKU-{$i}",
        ]);

        Price::factory()->create([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'currency_id' => $currency->id,
            'price' => 1500 * $i,
            'min_quantity' => 1,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetCatalogPerformance {
            catalog(first: 6) {
                data {
                    id
                    name
                    price {
                        priceFormatted
                        priceDecimal
                    }
                    variants {
                        id
                        sku
                        price {
                            priceFormatted
                        }
                    }
                }
            }
        }
    ');

    $response->assertJsonStructure([
        'data' => [
            'catalog' => [
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'price' => [
                            'priceFormatted',
                            'priceDecimal',
                        ],
                        'variants' => [
                            '*' => [
                                'id',
                                'sku',
                                'price' => [
                                    'priceFormatted',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $queryCount = count(DB::getQueryLog());

    // Without optimization, 6 products with prices and variants would generate 20+ queries.
    // With eager loading batching (@with and relationLoaded), query count is strictly capped and low.
    expect($queryCount)->toBeLessThanOrEqual(6);
});

it('avoids N+1 queries when querying user orders with lines and formatting', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john.orders@example.com',
        'password' => bcrypt('password'),
    ]);
    $currency = Currency::getDefault();

    for ($i = 1; $i <= 4; $i++) {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_code' => $currency->code,
            'sub_total' => 5000,
            'total' => 6000,
        ]);

        $order->lines()->create([
            'type' => 'product',
            'description' => "Item {$i}",
            'identifier' => "ITEM-{$i}",
            'unit_quantity' => 1,
            'quantity' => 1,
            'unit_price' => 5000,
            'sub_total' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 5000,
            'tax_breakdown' => new \Lunar\Core\ValueObjects\Cart\TaxBreakdown(),
        ]);
    }

    $this->actingAs($user, 'sanctum');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetMyOrdersPerformance {
            myOrders(first: 4) {
                data {
                    id
                    subTotalFormatted
                    totalFormatted
                    lines {
                        id
                        description
                        unitPriceFormatted
                        totalFormatted
                    }
                }
            }
        }
    ');

    $response->assertJsonStructure([
        'data' => [
            'myOrders' => [
                'data' => [
                    '*' => [
                        'id',
                        'subTotalFormatted',
                        'totalFormatted',
                        'lines' => [
                            '*' => [
                                'id',
                                'description',
                                'unitPriceFormatted',
                                'totalFormatted',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $queryCount = count(DB::getQueryLog());
    expect($queryCount)->toBeLessThanOrEqual(4);
});
