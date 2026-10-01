<?php

use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Discount;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductOption;
use Lunar\Core\Models\ProductVariant;

it('verifies all updated types.graphql fields resolve correctly', function () {
    $currency = Currency::getDefault();

    $brand = Brand::create([
        'name' => 'Acme Corp',
        'handle' => 'acme-corp',
        'description' => ['en' => 'Full description'],
        'short_description' => ['en' => 'Short brand description'],
    ]);

    $product = Product::factory()->create([
        'brand_id' => $brand->id,
        'status' => 'published',
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'sku' => 'ACME-V1',
        'min_quantity' => 2,
        'quantity_increment' => 5,
        'stock_on_hand' => 100,
        'stock_incoming' => 20,
        'stock_committed' => 10,
        'stock_reserved' => 5,
        'stock_unavailable' => 0,
        'stock_available' => 85,
    ]);

    $option = ProductOption::create([
        'name' => ['en' => 'Color'],
        'handle' => 'color',
        'type' => 'swatch',
        'shared' => true,
    ]);

    $discount = Discount::create([
        'name' => 'VIP Promo',
        'handle' => 'vip-promo',
        'coupon' => 'VIP2026',
        'type' => 'percentage',
        'starts_at' => now(),
        'max_uses' => 100,
        'max_uses_per_user' => 1,
        'restriction' => 'customer_group',
    ]);

    // 1. Verify ProductVariant and Brand and ProductOption fields
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetVariantDetails($id: ID!, $brandId: ID!, $optionId: ID!, $discountId: ID!) {
            productVariant(id: $id) {
                id
                sku
                minQuantity
                quantityIncrement
                stockOnHand
                stockAvailable
            }
            brand(id: $brandId) {
                id
                name
                shortDescription
            }
            productOption(id: $optionId) {
                id
                type
                handle
            }
            discount(id: $discountId) {
                id
                maxUses
                maxUsesPerUser
                restriction
            }
        }
    ', [
        'id' => $variant->id,
        'brandId' => $brand->id,
        'optionId' => $option->id,
        'discountId' => $discount->id,
    ]);

    $response->assertJson([
        'data' => [
            'productVariant' => [
                'sku' => 'ACME-V1',
                'minQuantity' => 2,
                'quantityIncrement' => 5,
                'stockOnHand' => 100,
                'stockAvailable' => 85,
            ],
            'brand' => [
                'name' => 'Acme Corp',
                'shortDescription' => 'Short brand description',
            ],
            'productOption' => [
                'type' => 'swatch',
                'handle' => 'color',
            ],
            'discount' => [
                'maxUses' => 100,
                'maxUsesPerUser' => 1,
                'restriction' => 'customer_group',
            ],
        ],
    ]);
});
