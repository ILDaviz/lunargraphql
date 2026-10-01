<?php

use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;

beforeEach(function () {
    $this->productType = ProductType::create(['name' => 'Default Type']);

    $this->brand = Brand::create(['name' => 'Acme Brand']);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
        'status' => 'published',
        'name' => ['en' => 'Awesome Sneakers'],
    ]);

    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'SNK-001-RED',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'stock_on_hand' => 10,
        'stock_available' => 10,
    ]);

    $this->price = $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 5000, // 50.00 EUR
        'list_price' => 6000,
        'min_quantity' => 1,
    ]);
});

it('can get current cart or create new cart', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            getCart {
                id
                subTotal
                total
            }
        }
    ');

    $response->assertJsonStructure([
        'data' => [
            'getCart' => [
                'id',
            ],
        ],
    ]);

    expect(CartSession::current())->not->toBeNull();
});

it('can add a product variant to the cart', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!, $quantity: Int!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: $quantity) {
                id
                lines {
                    id
                    quantity
                    subTotal
                    total
                    unitPrice
                    purchasable {
                        id
                        sku
                    }
                }
                subTotal
                total
            }
        }
    ', [
        'variantId' => $this->variant->id,
        'quantity' => 2,
    ]);

    $response->assertJson([
        'data' => [
            'addProductVariantToCart' => [
                'lines' => [
                    [
                        'quantity' => 2,
                        'purchasable' => [
                            'sku' => 'SNK-001-RED',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($response->json('data.addProductVariantToCart.lines'))->toHaveCount(1);
});

it('can update cart line quantity', function () {
    // First add to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!, $quantity: Int!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: $quantity) {
                lines {
                    id
                    quantity
                }
            }
        }
    ', [
        'variantId' => $this->variant->id,
        'quantity' => 1,
    ]);

    $lineId = $addResponse->json('data.addProductVariantToCart.lines.0.id');

    // Update quantity
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($lineId: ID!, $quantity: Int!) {
            updateCartLine(cartLineID: $lineId, quantity: $quantity) {
                lines {
                    id
                    quantity
                }
            }
        }
    ', [
        'lineId' => $lineId,
        'quantity' => 4,
    ]);

    $response->assertJson([
        'data' => [
            'updateCartLine' => [
                'lines' => [
                    [
                        'quantity' => 4,
                    ],
                ],
            ],
        ],
    ]);
});

it('can remove cart line', function () {
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!, $quantity: Int!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: $quantity) {
                lines {
                    id
                }
            }
        }
    ', [
        'variantId' => $this->variant->id,
        'quantity' => 1,
    ]);

    $lineId = $addResponse->json('data.addProductVariantToCart.lines.0.id');

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($lineId: ID!) {
            removeCartLine(cartLineID: $lineId) {
                lines {
                    id
                }
            }
        }
    ', [
        'lineId' => $lineId,
    ]);

    $response->assertJson([
        'data' => [
            'removeCartLine' => [
                'lines' => [],
            ],
        ],
    ]);
});

it('can clear cart', function () {
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', [
        'variantId' => $this->variant->id,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            clearCart {
                lines {
                    id
                }
            }
        }
    ');

    $response->assertJson([
        'data' => [
            'clearCart' => [
                'lines' => [],
            ],
        ],
    ]);
});

it('can set shipping and billing address on cart', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($shipping: CartAddressInput!, $billing: CartAddressInput!) {
            setCartShippingAddress(address: $shipping) {
                shippingAddress {
                    firstName
                    lastName
                    city
                    postcode
                }
            }
            setCartBillingAddress(address: $billing) {
                billingAddress {
                    firstName
                    lastName
                    city
                    postcode
                }
            }
        }
    ', [
        'shipping' => [
            'country' => 'IT',
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'lineOne' => 'Via Roma 1',
            'city' => 'Roma',
            'postcode' => '00100',
        ],
        'billing' => [
            'country' => 'IT',
            'firstName' => 'Luigi',
            'lastName' => 'Verdi',
            'lineOne' => 'Via Milano 2',
            'city' => 'Milano',
            'postcode' => '20100',
        ],
    ]);

    $response->assertJson([
        'data' => [
            'setCartShippingAddress' => [
                'shippingAddress' => [
                    'firstName' => 'Mario',
                    'lastName' => 'Rossi',
                    'city' => 'Roma',
                    'postcode' => '00100',
                ],
            ],
            'setCartBillingAddress' => [
                'billingAddress' => [
                    'firstName' => 'Luigi',
                    'lastName' => 'Verdi',
                    'city' => 'Milano',
                    'postcode' => '20100',
                ],
            ],
        ],
    ]);
});

it('can apply and remove coupon on cart', function () {
    $apply = $this->graphQL(/** @lang GraphQL */ '
        mutation ($coupon: String!) {
            applyCouponToCart(coupon: $coupon) {
                couponCode
            }
        }
    ', [
        'coupon' => 'SUMMER20',
    ]);

    $apply->assertJson([
        'data' => [
            'applyCouponToCart' => [
                'couponCode' => 'SUMMER20',
            ],
        ],
    ]);

    $remove = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            removeCouponFromCart {
                couponCode
            }
        }
    ');

    $remove->assertJson([
        'data' => [
            'removeCouponFromCart' => [
                'couponCode' => null,
            ],
        ],
    ]);
});

it('can calculate cart totals', function () {
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 2) {
                id
            }
        }
    ', [
        'variantId' => $this->variant->id,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            calculateCart {
                id
                subTotal
                total
                subTotalFormatted
                totalFormatted
            }
        }
    ');

    $response->assertJsonStructure([
        'data' => [
            'calculateCart' => [
                'id',
                'subTotal',
                'total',
            ],
        ],
    ]);
});
