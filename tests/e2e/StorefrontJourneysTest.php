<?php

use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\DataTypes\ShippingOption;
use Lunar\Core\Facades\ShippingManifest;
use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $productType = ProductType::create([
        'name' => 'Storefront E2E Type',
        'status' => 'active',
    ]);
    $brand = Brand::create(['name' => 'Storefront E2E Brand']);

    $this->product = Product::create([
        'product_type_id' => $productType->id,
        'brand_id' => $brand->id,
        'status' => 'published',
        'name' => ['en' => 'E2E Espresso Machine'],
    ]);
    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'E2E-ESPRESSO-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'shippable' => true,
        'enabled' => true,
        'stock_on_hand' => 10,
        'stock_available' => 10,
    ]);
    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 10000,
        'min_quantity' => 1,
    ]);

    ShippingManifest::clearOptions();
    ShippingManifest::addOption(new ShippingOption(
        name: 'Standard delivery',
        description: 'Standard delivery',
        identifier: 'E2E_STANDARD',
        price: new PriceValue(1500, $this->defaultCurrency),
        taxClass: $this->defaultTaxClass,
    ));
});

it('completes a guest journey from catalog browsing through order tracking', function () {
    $catalogResponse = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog(first: 10) {
                data { id name }
            }
        }
    ');
    $catalogResponse->assertSuccessful();
    expect($catalogResponse->json('data.catalog.data.0.name'))->toBe('E2E Espresso Machine');

    $cartResponse = $this->graphQL(/** @lang GraphQL */ '
        query {
            getCart {
                id
                totalQuantity
            }
        }
    ');
    $cartResponse->assertSuccessful();
    $cartId = $cartResponse->json('data.getCart.id');

    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!) {
            addProductVariantToCart(cartId: $cartId, productVariantId: $variantId, quantity: 2) {
                id
                totalQuantity
                subTotal
            }
        }
    ', [
        'cartId' => $cartId,
        'variantId' => $this->variant->id,
    ]);
    $addResponse->assertSuccessful();
    expect($addResponse->json('data.addProductVariantToCart.totalQuantity'))->toBe(2)
        ->and($addResponse->json('data.addProductVariantToCart.subTotal'))->toBe(20000);

    $shippingAddress = [
        'countryId' => $this->defaultCountry->id,
        'firstName' => 'Guest',
        'lastName' => 'Buyer',
        'lineOne' => '1 Test Street',
        'city' => 'Milan',
        'postcode' => '20100',
        'contactEmail' => 'guest@example.com',
    ];
    $addressResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $address: CartAddressInput!) {
            setCartShippingAddress(cartId: $cartId, address: $address, sameAsBilling: true) {
                shippingAddress { firstName }
                billingAddress { contactEmail }
            }
        }
    ', ['cartId' => $cartId, 'address' => $shippingAddress]);
    $addressResponse->assertSuccessful();
    expect($addressResponse->json('errors'))->toBeNull();
    expect($addressResponse->json('data.setCartShippingAddress.billingAddress.contactEmail'))
        ->toBe('guest@example.com');

    $optionsResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($cartId: ID!) {
            shippingOptions(cartId: $cartId) { identifier }
        }
    ', ['cartId' => $cartId]);
    $optionsResponse->assertSuccessful();
    expect($optionsResponse->json('errors'))->toBeNull();
    expect(collect($optionsResponse->json('data.shippingOptions'))->pluck('identifier'))
        ->toContain('E2E_STANDARD');

    $shippingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            setCartShippingOption(cartId: $cartId, shippingOption: "E2E_STANDARD") {
                shippingTotal
            }
        }
    ', ['cartId' => $cartId]);
    $shippingResponse->assertSuccessful();
    expect($shippingResponse->json('errors'))->toBeNull();

    $checkoutResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            createOrderFromCart(cartId: $cartId) {
                id
                reference
                total
                billingAddress { contactEmail }
            }
        }
    ', ['cartId' => $cartId]);
    $checkoutResponse->assertSuccessful();
    expect($checkoutResponse->json('errors'))->toBeNull();
    $order = $checkoutResponse->json('data.createOrderFromCart');
    expect($order['total'])->toBeGreaterThan(20000)
        ->and($order['billingAddress']['contactEmail'])->toBe('guest@example.com');
    expect(Order::where('reference', $order['reference'])->exists())->toBeTrue();

    $trackingResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($reference: String!, $email: String!) {
            guestOrder(reference: $reference, email: $email) {
                id
                reference
                total
            }
        }
    ', ['reference' => $order['reference'], 'email' => 'guest@example.com']);
    $trackingResponse->assertSuccessful();
    expect($trackingResponse->json('data.guestOrder.reference'))->toBe($order['reference']);

    $unauthorizedTrackingResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($reference: String!, $email: String!) {
            guestOrder(reference: $reference, email: $email) { id }
        }
    ', ['reference' => $order['reference'], 'email' => 'someone-else@example.com']);
    expect($unauthorizedTrackingResponse->json('data.guestOrder'))->toBeNull();
});

it('completes an authenticated customer purchase and scopes order history to that customer', function () {
    $cartResponse = $this->graphQL(/** @lang GraphQL */ '
        query { getCart { id } }
    ');
    $cartResponse->assertSuccessful();
    expect($cartResponse->json('errors'))->toBeNull();
    $cartId = $cartResponse->json('data.getCart.id');

    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantId: $variantId, quantity: 1) {
                totalQuantity
            }
        }
    ', ['variantId' => $this->variant->id]);
    $addResponse->assertSuccessful();
    expect($addResponse->json('errors'))->toBeNull();

    $registrationResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            createUser(
                name: "E2E Customer"
                email: "customer-e2e@example.com"
                password: "password123"
                cartId: $cartId
            ) { token user { email } }
        }
    ', ['cartId' => $cartId]);
    $registrationResponse->assertSuccessful();
    expect($registrationResponse->json('errors'))->toBeNull()
        ->and($registrationResponse->json('data.createUser.user.email'))->toBe('customer-e2e@example.com');

    $loginResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            login(email: "customer-e2e@example.com", password: "password123") {
                token
                user { email }
            }
        }
    ');
    $loginResponse->assertSuccessful();
    expect($loginResponse->json('errors'))->toBeNull();
    $this->withToken($loginResponse->json('data.login.token'));
    $this->app['config']->set('lighthouse.guards', ['sanctum']);

    $identityResponse = $this->graphQL(/** @lang GraphQL */ '
        query { me { email } }
    ');
    $identityResponse->assertSuccessful();
    expect($identityResponse->json('data.me.email'))->toBe('customer-e2e@example.com');

    $billingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $address: CartAddressInput!) {
            setCartBillingAddress(cartId: $cartId, address: $address) { id }
        }
    ', [
        'cartId' => $cartId,
        'address' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'E2E',
            'lastName' => 'Customer',
            'lineOne' => '2 Test Street',
            'city' => 'Rome',
            'postcode' => '00100',
        ],
    ]);
    $billingResponse->assertSuccessful();
    expect($billingResponse->json('errors'))->toBeNull();

    $shippingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $address: CartAddressInput!) {
            setCartShippingAddress(cartId: $cartId, address: $address, sameAsBilling: true) { id }
        }
    ', [
        'cartId' => $cartId,
        'address' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'E2E',
            'lastName' => 'Customer',
            'lineOne' => '2 Test Street',
            'city' => 'Rome',
            'postcode' => '00100',
        ],
    ]);
    $shippingResponse->assertSuccessful();
    expect($shippingResponse->json('errors'))->toBeNull();

    $shippingOptionResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            setCartShippingOption(cartId: $cartId, shippingOption: "E2E_STANDARD") { id }
        }
    ', ['cartId' => $cartId]);
    $shippingOptionResponse->assertSuccessful();
    expect($shippingOptionResponse->json('errors'))->toBeNull();

    $checkoutResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            createOrderFromCart(cartId: $cartId) { id reference total }
        }
    ', ['cartId' => $cartId]);
    $checkoutResponse->assertSuccessful();
    expect($checkoutResponse->json('errors'))->toBeNull();
    $orderData = $checkoutResponse->json('data.createOrderFromCart');
    $order = Order::where('reference', $orderData['reference'])->firstOrFail();
    expect($order->user_id)->toBe(User::where('email', 'customer-e2e@example.com')->value('id'));

    $ordersResponse = $this->graphQL(/** @lang GraphQL */ '
        query {
            myOrders { data { reference total } }
        }
    ');
    $ordersResponse->assertSuccessful();
    expect($ordersResponse->json('errors'))->toBeNull();
    expect(collect($ordersResponse->json('data.myOrders.data'))->pluck('reference'))
        ->toContain($orderData['reference']);

    $otherCustomerRegistrationResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            createUser(name: "Other E2E Customer", email: "other-customer-e2e@example.com", password: "password123") {
                token
            }
        }
    ');
    $otherCustomerRegistrationResponse->assertSuccessful();
    expect($otherCustomerRegistrationResponse->json('errors'))->toBeNull();
    $this->withToken($otherCustomerRegistrationResponse->json('data.createUser.token'));
    $this->app['auth']->forgetGuards();
    $otherIdentityResponse = $this->graphQL(/** @lang GraphQL */ '
        query { me { email } }
    ');
    expect($otherIdentityResponse->json('data.me.email'))->toBe('other-customer-e2e@example.com');
    $unauthorizedOrderResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) { order(id: $id) { id } }
    ', ['id' => $order->id]);
    expect($unauthorizedOrderResponse->json('data.order'))->toBeNull();
});

it('completes an offline payment journey and persists its transaction', function () {
    $this->variant->update(['shippable' => false]);

    $cartResponse = $this->graphQL(/** @lang GraphQL */ '
        query { getCart { id } }
    ');
    $cartResponse->assertSuccessful();
    expect($cartResponse->json('errors'))->toBeNull();
    $cartId = $cartResponse->json('data.getCart.id');

    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!) {
            addProductVariantToCart(cartId: $cartId, productVariantId: $variantId, quantity: 1) { id }
        }
    ', ['cartId' => $cartId, 'variantId' => $this->variant->id]);
    $addResponse->assertSuccessful();
    expect($addResponse->json('errors'))->toBeNull();

    $billingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $address: CartAddressInput!) {
            setCartBillingAddress(cartId: $cartId, address: $address) { id }
        }
    ', [
        'cartId' => $cartId,
        'address' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Payment',
            'lastName' => 'Test',
            'lineOne' => '3 Test Street',
            'city' => 'Milan',
            'postcode' => '20100',
        ],
    ]);
    $billingResponse->assertSuccessful();
    expect($billingResponse->json('errors'))->toBeNull();

    $paymentResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            authorizePayment(cartId: $cartId, paymentType: "cash-in-hand") {
                success
                orderId
                paymentType
                status
            }
        }
    ', ['cartId' => $cartId]);
    $paymentResponse->assertSuccessful();
    expect($paymentResponse->json('errors'))->toBeNull();
    $payment = $paymentResponse->json('data.authorizePayment');
    expect($payment['success'])->toBeTrue()
        ->and($payment['status'])->toBe('paid')
        ->and($payment['paymentType'])->toBe('offline');

    $order = Order::with('transactions')->findOrFail($payment['orderId']);
    expect($order->transactions)->toHaveCount(1)
        ->and($order->transactions->first()->amount)->toBe(10000);
});
