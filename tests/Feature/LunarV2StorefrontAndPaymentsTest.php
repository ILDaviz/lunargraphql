<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Lunar\Core\Events\PaymentAttemptEvent;
use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\ValueObjects\Cart\TaxBreakdown;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario.rossi@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->productType = ProductType::create(['name' => 'Standard Product Type']);
    $this->brand = Brand::create(['name' => 'Acme Brand']);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
        'status' => 'published',
        'name' => ['en' => 'Test Lunar v2 Item'],
    ]);

    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    // Non-shippable variant to allow testing direct digital or in-store order flow
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'TEST-V2-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'shippable' => false,
        'enabled' => true,
        'stock_on_hand' => 20,
        'stock_available' => 20,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 5000, // 50.00 EUR
        'min_quantity' => 1,
    ]);
});

it('queries Lunar v2 payment providers with id, handle, driver, and enabled fields', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            paymentProviders {
                id
                handle
                name
                driver
                enabled
            }
        }
    ');

    $response->assertSuccessful();
    $providers = $response->json('data.paymentProviders');

    expect($providers)->toBeArray()->not->toBeEmpty();
    $cashInHand = collect($providers)->firstWhere('handle', 'cash-in-hand');
    expect($cashInHand)->not->toBeNull()
        ->and($cashInHand['id'])->toBe('cash-in-hand')
        ->and($cashInHand['driver'])->toBe('offline')
        ->and($cashInHand['enabled'])->toBeTrue();
});

it('authorizes payment directly from cart via Lunar v2 Payments driver using authorizePayment', function () {
    // 1. Add item to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
                total
            }
        }
    ', ['variantId' => $this->variant->id]);

    $cartId = $addResponse->json('data.addProductVariantToCart.id');

    // 2. Set billing address
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $billing: CartAddressInput!) {
            setCartBillingAddress(cartId: $cartId, address: $billing) {
                id
            }
        }
    ', [
        'cartId' => $cartId,
        'billing' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'lineOne' => 'Via Roma 10',
            'city' => 'Milano',
            'postcode' => '20121',
        ],
    ]);

    // 3. Authorize payment directly via Lunar v2 Payments driver (cash-in-hand)
    $authResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            authorizePayment(cartId: $cartId, paymentType: "cash-in-hand") {
                success
                message
                orderId
                paymentType
                status
                order {
                    id
                    reference
                    paymentStatus
                    total
                    transactions {
                        id
                        driver
                        amount
                        status
                        success
                    }
                }
            }
        }
    ', ['cartId' => $cartId]);

    $authResponse->assertSuccessful();
    $data = $authResponse->json('data.authorizePayment');

    expect($data['success'])->toBeTrue()
        ->and($data['paymentType'])->toBe('offline')
        ->and($data['status'])->toBe('paid')
        ->and($data['orderId'])->not->toBeNull()
        ->and($data['order']['total'])->toBe(5000)
        ->and($data['order']['paymentStatus'])->toBe('paid')
        ->and($data['order']['transactions'])->toHaveCount(1)
        ->and($data['order']['transactions'][0]['driver'])->toBe('cash-in-hand')
        ->and($data['order']['transactions'][0]['amount'])->toBe(5000)
        ->and($data['order']['transactions'][0]['status'])->toBe('success');
});

it('initiates and authorizes offline payment via initiatePayment with provider cash-in-hand', function () {
    // 1. Add item to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);

    $cartId = $addResponse->json('data.addProductVariantToCart.id');

    // 2. Set billing address
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $billing: CartAddressInput!) {
            setCartBillingAddress(cartId: $cartId, address: $billing) {
                id
            }
        }
    ', [
        'cartId' => $cartId,
        'billing' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'lineOne' => 'Via Roma 10',
            'city' => 'Milano',
            'postcode' => '20121',
        ],
    ]);

    // 3. Call initiatePayment with Lunar core provider cash-in-hand
    $initiateResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            initiatePayment(cartId: $cartId, provider: "cash-in-hand") {
                success
                orderId
                transactionId
                status
                requiresAction
                clientSecret
            }
        }
    ', ['cartId' => $cartId]);

    $initiateResponse->assertSuccessful();
    $initiateData = $initiateResponse->json('data.initiatePayment');

    expect($initiateData['success'])->toBeTrue()
        ->and($initiateData['orderId'])->not->toBeNull()
        ->and($initiateData['transactionId'])->not->toBeNull()
        ->and($initiateData['status'])->toBe('succeeded')
        ->and($initiateData['requiresAction'])->toBeFalse()
        ->and($initiateData['clientSecret'])->toBeNull();

    // Verify order in database
    $order = Order::find($initiateData['orderId']);
    expect($order)->not->toBeNull()
        ->and((string) $order->payment_status)->toBe('paid')
        ->and($order->transactions)->toHaveCount(1);
});

it('dispatches PaymentAttemptEvent when recording order transaction', function () {
    Event::fake([PaymentAttemptEvent::class]);

    // Create order
    $order = Order::create([
        'user_id' => $this->user->id,
        'channel_id' => $this->defaultChannel->id,
        'currency_code' => $this->defaultCurrency->code,
        'payment_status' => 'pending',
        'fulfilment_status' => 'unfulfilled',
        'sub_total' => 5000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'tax_breakdown' => new TaxBreakdown,
        'total' => 5000,
    ]);

    $this->actingAs($this->user, 'sanctum');
    Gate::define('record-order-transaction', fn ($actor, $authorizedOrder) => $actor->is($this->user));

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            recordOrderTransaction(
                orderId: $orderId
                amount: 5000
                type: "capture"
                driver: "manual"
                reference: "manual_ref_123"
                status: "success"
                notes: "Manual cashier payment"
            ) {
                id
                amount
                status
                success
            }
        }
    ', ['orderId' => $order->id]);

    $response->assertSuccessful();
    expect($response->json('data.recordOrderTransaction.success'))->toBeTrue();

    Event::assertDispatched(PaymentAttemptEvent::class, function ($event) use ($order) {
        return $event->paymentAuthorize->orderId == $order->id && $event->paymentAuthorize->success === true;
    });
});

it('queries all Lunar v2 Storefront context entities successfully', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            regions {
                id
                name
                handle
            }
            defaultRegion {
                id
                name
                handle
            }
            channels {
                id
                name
                handle
            }
            defaultChannel {
                id
                name
                handle
            }
            currencies {
                id
                code
                name
            }
            defaultCurrency {
                id
                code
            }
            languages {
                id
                code
                name
            }
            defaultLanguage {
                id
                code
            }
            customerGroups {
                id
                name
                handle
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['regions'])->not->toBeEmpty()
        ->and($data['defaultRegion']['handle'])->toBe($this->defaultRegion->handle)
        ->and($data['channels'])->not->toBeEmpty()
        ->and($data['defaultChannel']['handle'])->toBe($this->defaultChannel->handle)
        ->and($data['currencies'])->not->toBeEmpty()
        ->and($data['defaultCurrency']['code'])->toBe($this->defaultCurrency->code)
        ->and($data['languages'])->not->toBeEmpty()
        ->and($data['defaultLanguage']['code'])->toBe($this->defaultLanguage->code)
        ->and($data['customerGroups'])->toBeArray();
});
