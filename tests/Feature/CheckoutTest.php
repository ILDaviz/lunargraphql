<?php

use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $this->productType = ProductType::create(['name' => 'Digital Type']);
    $this->brand = Brand::create(['name' => 'Digital Brand']);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
        'status' => 'published',
        'name' => ['en' => 'Digital E-Book'],
    ]);

    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    // Non-shippable variant so shipping options are not strictly required for test checkout
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'EBOOK-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'shippable' => false,
        'enabled' => true,
        'stock_on_hand' => 10,
        'stock_available' => 10,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 2500, // 25.00 EUR
        'list_price' => 3000,
        'min_quantity' => 1,
    ]);
});

it('can create an order from cart', function () {
    // 1. Add item to cart
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', [
        'variantId' => $this->variant->id,
    ]);

    // 2. Set billing address
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($billing: CartAddressInput!) {
            setCartBillingAddress(address: $billing) {
                id
            }
        }
    ', [
        'billing' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'lineOne' => '123 Tech Lane',
            'city' => 'Milan',
            'postcode' => '20100',
        ],
    ]);

    // 3. Checkout
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            createOrderFromCart {
                id
                subTotal
                total
                subTotalFormatted
                totalFormatted
                status
                lines {
                    id
                    description
                    quantity
                    subTotal
                    total
                }
            }
        }
    ');

    $response->assertJsonStructure([
        'data' => [
            'createOrderFromCart' => [
                'id',
                'subTotal',
                'total',
                'lines',
            ],
        ],
    ]);

    expect($response->json('data.createOrderFromCart.total'))->toBe(2500);
});

it('can query an order by ID', function () {
    // Create cart and order
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
        'user_id' => $this->user->id,
    ]);

    $cart->add($this->variant, 1);
    $cart->setBillingAddress([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'line_one' => '123 Tech Lane',
        'city' => 'Milan',
        'postcode' => '20100',
    ]);

    $order = $cart->createOrder();

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            order(id: $id) {
                id
                subTotal
                total
                status
            }
        }
    ', [
        'id' => $order->id,
    ]);

    $response->assertJson([
        'data' => [
            'order' => [
                'total' => 2500,
            ],
        ],
    ]);
});

it('can query authenticated user orders via myOrders', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
        'user_id' => $this->user->id,
    ]);

    $cart->add($this->variant, 1);
    $cart->setBillingAddress([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'line_one' => '123 Tech Lane',
        'city' => 'Milan',
        'postcode' => '20100',
    ]);

    $order = $cart->createOrder();

    $response = $this->actingAs($this->user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        query {
            myOrders(first: 5) {
                data {
                    id
                    total
                    status
                }
                paginatorInfo {
                    total
                    count
                }
            }
        }
    ');

    $response->assertJson([
        'data' => [
            'myOrders' => [
                'paginatorInfo' => [
                    'total' => 1,
                    'count' => 1,
                ],
                'data' => [
                    [
                        'total' => 2500,
                    ],
                ],
            ],
        ],
    ]);
});

it('prevents unauthenticated access to myOrders', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            myOrders(first: 5) {
                data {
                    id
                }
            }
        }
    ');

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('Unauthenticated');
});

it('fails to create order when cart is empty', function () {
    CartSession::forget();

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            createOrderFromCart {
                id
            }
        }
    ');

    expect($response->json('errors'))->not->toBeNull();
});
