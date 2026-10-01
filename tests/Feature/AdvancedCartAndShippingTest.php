<?php

use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\DataTypes\ShippingOption;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\ShippingManifest;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'cart-type'], [
        'name' => 'Cart Product Type',
        'status' => 'active',
    ]);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Test Shippable Product'],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'TEST-CART-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'shippable' => true,
        'stock_on_hand' => 20,
        'stock_available' => 20,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 4000, // 40.00 EUR
        'min_quantity' => 1,
    ]);

    $this->usdCurrency = Currency::where('code', 'USD')->first();

    $this->variant->prices()->create([
        'currency_id' => $this->usdCurrency->id,
        'price' => 4400, // 44.00 USD
        'min_quantity' => 1,
    ]);

    ShippingManifest::clearOptions();

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'Standard Post',
            description: 'Delivery in 3-5 days',
            identifier: 'std_post',
            price: new PriceValue(500, $this->defaultCurrency),
            taxClass: $this->defaultTaxClass,
        )
    );

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'DHL Express',
            description: 'Next day courier',
            identifier: 'dhl_express',
            price: new PriceValue(1500, $this->defaultCurrency),
            taxClass: $this->defaultTaxClass,
        )
    );
});

it('can query cart with isShippable, totalQuantity, subTotalDiscounted and shipping totals', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    $cart->add($this->variant, quantity: 2);
    CartSession::use($cart);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            getCart {
                id
                isShippable
                totalQuantity
                subTotal
                subTotalFormatted
                subTotalDiscounted
                subTotalDiscountedFormatted
                shippingTotal
                total
                totalFormatted
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.getCart');

    expect($data['isShippable'])->toBeTrue()
        ->and($data['totalQuantity'])->toBe(2)
        ->and($data['subTotal'])->toBe(8000)
        ->and($data['subTotalDiscounted'])->toBe(8000);
});

it('can estimate shipping options before selecting address', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    $cart->add($this->variant, quantity: 1);
    CartSession::use($cart);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            estimateShipping(country: "IT", postcode: "00100") {
                name
                identifier
                price
                priceFormatted
            }
        }
    ');

    $response->assertSuccessful();
    $options = $response->json('data.estimateShipping');

    expect($options)->toHaveCount(2)
        ->and($options[0]['identifier'])->toBe('std_post')
        ->and($options[0]['price'])->toBe(500)
        ->and($options[1]['identifier'])->toBe('dhl_express')
        ->and($options[1]['price'])->toBe(1500);
});

it('can change cart currency and recalculate totals', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    $cart->add($this->variant, quantity: 1);
    CartSession::use($cart);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ChangeCurrency($code: String!) {
            setCartCurrency(currencyCode: $code) {
                id
                currency {
                    code
                }
                subTotal
            }
        }
    ', [
        'code' => 'USD',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.setCartCurrency');

    expect($data['currency']['code'])->toBe('USD')
        ->and($data['subTotal'])->toBe(4400);
});

it('can resolve tax breakdown on cart when tax is calculated', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    $cart->add($this->variant, quantity: 1);
    CartSession::use($cart);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            getCart {
                id
                taxTotal
                taxBreakdown {
                    identifier
                    description
                    percentage
                    price
                }
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.getCart');

    expect($data)->toHaveKey('taxTotal')
        ->and($data)->toHaveKey('taxBreakdown')
        ->and($data['taxBreakdown'])->toBeArray();
});
