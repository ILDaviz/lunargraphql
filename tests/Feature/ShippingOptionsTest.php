<?php

use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\DataTypes\ShippingOption;
use Lunar\Core\Facades\ShippingManifest;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'default-type'], [
        'name' => 'Default Type',
        'status' => 'active',
    ]);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Shippable Physical Item'],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'SHIP-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'shippable' => true,
        'stock_on_hand' => 10,
        'stock_available' => 10,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 5000, // 50.00 EUR
        'min_quantity' => 1,
    ]);

    // Clear any previous shipping options in manifest
    ShippingManifest::clearOptions();

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'Standard Delivery',
            description: 'Delivery in 3-5 business days',
            identifier: 'standard_shipping',
            price: new PriceValue(599, $this->defaultCurrency),
            taxClass: $this->defaultTaxClass,
        )
    );

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'Express Courier',
            description: 'Next business day delivery',
            identifier: 'express_courier',
            price: new PriceValue(1250, $this->defaultCurrency),
            taxClass: $this->defaultTaxClass,
        )
    );
});

it('returns empty shipping options if cart is empty or not shippable', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            shippingOptions {
                identifier
            }
        }
    ');

    $response->assertSuccessful();
    expect($response->json('data.shippingOptions'))->toBeEmpty();
});

it('returns available shipping options for a shippable cart', function () {
    // Add shippable item to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantId: $variantId, quantity: 1) {
                id
            }
        }
    ', [
        'variantId' => $this->variant->id,
    ]);
    $addResponse->assertSuccessful();

    // Query available options
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            shippingOptions {
                name
                description
                identifier
                price
                priceFormatted
                priceDecimal
                collect
            }
        }
    ');

    $response->assertSuccessful();
    $options = $response->json('data.shippingOptions');

    expect($options)->toHaveCount(2)
        ->and($options[0]['name'])->toBe('Standard Delivery')
        ->and($options[0]['identifier'])->toBe('standard_shipping')
        ->and($options[0]['price'])->toBe(599)
        ->and($options[0]['priceDecimal'])->toBe(5.99)
        ->and($options[1]['name'])->toBe('Express Courier')
        ->and($options[1]['identifier'])->toBe('express_courier')
        ->and($options[1]['price'])->toBe(1250)
        ->and($options[1]['priceDecimal'])->toBe(12.5);
});

it('can select a shipping option on cart and recalculates totals', function () {
    // 1. Add variant to cart
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantId: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);

    // 2. Set shipping address
    $this->graphQL(/** @lang GraphQL */ '
        mutation {
            setCartShippingAddress(address: {
                firstName: "Marco"
                lastName: "Rossi"
                lineOne: "Via Roma 1"
                city: "Milano"
                postcode: "20100"
                country: "IT"
            }) {
                id
            }
        }
    ');

    // 3. Select shipping option
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            setCartShippingOption(shippingOption: "express_courier") {
                id
                shippingAddress {
                    shippingOption
                }
                shippingTotal
                subTotal
                total
            }
        }
    ');

    $response->assertSuccessful();
    $cart = $response->json('data.setCartShippingOption');

    expect($cart['shippingAddress']['shippingOption'])->toBe('express_courier')
        ->and($cart['shippingTotal'])->toBe(1250)
        ->and($cart['subTotal'])->toBe(5000)
        ->and($cart['total'])->toBe(6250);
});

it('fails to select shipping option if shipping address is missing', function () {
    // Add variant to cart
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantId: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            setCartShippingOption(shippingOption: "express_courier") {
                id
            }
        }
    ');

    expect($response->json('errors.0.message'))->toContain('Shipping address must be set')
        ->and($response->json('data.setCartShippingOption'))->toBeNull();
});
