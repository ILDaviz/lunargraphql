<?php

use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\DataTypes\ShippingOption;
use Lunar\Core\Facades\ShippingManifest;
use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Customer;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario.rossi@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->customer = Customer::create([
        'title' => 'Mr',
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'account_ref' => 'CUST-ROSSI-01',
    ]);
    $this->customer->users()->attach($this->user);

    $this->productType = ProductType::firstOrCreate(['handle' => 'physical-goods'], [
        'name' => 'Physical Goods',
        'status' => 'active',
    ]);

    $this->brand = Brand::create(['name' => 'Italian Design']);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
        'status' => 'published',
        'name' => ['en' => 'Italian Espresso Machine'],
    ]);

    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'MCH-ESP-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'shippable' => true,
        'enabled' => true,
        'stock_on_hand' => 20,
        'stock_available' => 20,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 10000, // 100.00 EUR
        'min_quantity' => 1,
    ]);

    ShippingManifest::clearOptions();

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'Express Courier',
            description: 'Next business day delivery',
            identifier: 'EXPRESS_CORRIERE',
            price: new PriceValue(1500, $this->defaultCurrency),
            taxClass: $this->defaultTaxClass,
        )
    );
});

it('supports clear separation of shipping and billing addresses with taxIdentifier and vatNo', function () {
    // 1. Add item to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
                lines {
                    id
                    quantity
                }
            }
        }
    ', ['variantId' => $this->variant->id]);

    $addResponse->assertSuccessful();

    // 2. Set distinct Shipping Address (physical delivery location + delivery notes)
    $shippingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($shipping: CartAddressInput!) {
            setCartShippingAddress(address: $shipping) {
                id
                shippingAddress {
                    firstName
                    lastName
                    companyName
                    lineOne
                    city
                    postcode
                    deliveryInstructions
                    contactEmail
                    contactPhone
                }
            }
        }
    ', [
        'shipping' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'companyName' => 'Rossi Residence',
            'lineOne' => 'Via Montenapoleone 1',
            'city' => 'Milano',
            'postcode' => '20121',
            'deliveryInstructions' => 'Lasciare al portiere, scala B terzo piano',
            'contactEmail' => 'mario.rossi@example.com',
            'contactPhone' => '+39 02 1234567',
        ],
    ]);

    $shippingResponse->assertSuccessful();
    $shippingData = $shippingResponse->json('data.setCartShippingAddress.shippingAddress');
    expect($shippingData['firstName'])->toBe('Mario')
        ->and($shippingData['lineOne'])->toBe('Via Montenapoleone 1')
        ->and($shippingData['deliveryInstructions'])->toBe('Lasciare al portiere, scala B terzo piano');

    // 3. Set distinct Billing Address (fiscal/invoicing details + taxIdentifier / vatNo)
    $billingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($billing: CartAddressInput!) {
            setCartBillingAddress(address: $billing) {
                id
                billingAddress {
                    firstName
                    lastName
                    companyName
                    lineOne
                    city
                    postcode
                    taxIdentifier
                    vatNo
                    contactEmail
                    contactPhone
                }
            }
        }
    ', [
        'billing' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Ufficio',
            'lastName' => 'Fatturazione',
            'companyName' => 'Acme Italia S.r.l.',
            'taxIdentifier' => 'IT12345678901',
            'lineOne' => 'Piazza Affari 2',
            'city' => 'Milano',
            'postcode' => '20123',
            'contactEmail' => 'fatturazione@acme-italia.it',
            'contactPhone' => '+39 02 7654321',
        ],
    ]);

    $billingResponse->assertSuccessful();
    $billingData = $billingResponse->json('data.setCartBillingAddress.billingAddress');
    expect($billingData['companyName'])->toBe('Acme Italia S.r.l.')
        ->and($billingData['taxIdentifier'])->toBe('IT12345678901')
        ->and($billingData['vatNo'])->toBe('IT12345678901')
        ->and($billingData['lineOne'])->toBe('Piazza Affari 2');
});

it('supports sameAsBilling flag when setting shipping address to automatically sync both addresses', function () {
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($shipping: CartAddressInput!) {
            setCartShippingAddress(address: $shipping, sameAsBilling: true) {
                id
                shippingAddress {
                    firstName
                    lastName
                    lineOne
                    city
                    taxIdentifier
                    vatNo
                }
                billingAddress {
                    firstName
                    lastName
                    lineOne
                    city
                    taxIdentifier
                    vatNo
                }
            }
        }
    ', [
        'shipping' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Giuseppe',
            'lastName' => 'Verdi',
            'lineOne' => 'Via Roma 100',
            'city' => 'Torino',
            'postcode' => '10121',
            'taxIdentifier' => 'VRDGPP80A01L219X',
        ],
    ]);

    $response->assertSuccessful();
    $shipping = $response->json('data.setCartShippingAddress.shippingAddress');
    $billing = $response->json('data.setCartShippingAddress.billingAddress');

    expect($shipping['lineOne'])->toBe('Via Roma 100')
        ->and($shipping['taxIdentifier'])->toBe('VRDGPP80A01L219X')
        ->and($shipping['vatNo'])->toBe('VRDGPP80A01L219X')
        ->and($billing['lineOne'])->toBe('Via Roma 100')
        ->and($billing['taxIdentifier'])->toBe('VRDGPP80A01L219X')
        ->and($billing['vatNo'])->toBe('VRDGPP80A01L219X');
});

it('supports sameAsShipping flag when setting billing address to copy from existing shipping address without payload', function () {
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);

    // 1. Set shipping address first
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($shipping: CartAddressInput!) {
            setCartShippingAddress(address: $shipping) {
                id
            }
        }
    ', [
        'shipping' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Laura',
            'lastName' => 'Bianchi',
            'lineOne' => 'Corso Buenos Aires 45',
            'city' => 'Milano',
            'postcode' => '20124',
            'taxIdentifier' => 'BNCLRA85M41F205Z',
        ],
    ]);

    // 2. Set billing address with sameAsShipping: true and no address input
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation {
            setCartBillingAddress(sameAsShipping: true) {
                id
                billingAddress {
                    firstName
                    lastName
                    lineOne
                    city
                    postcode
                    taxIdentifier
                    vatNo
                }
            }
        }
    ');

    $response->assertSuccessful();
    $billing = $response->json('data.setCartBillingAddress.billingAddress');
    expect($billing['firstName'])->toBe('Laura')
        ->and($billing['lastName'])->toBe('Bianchi')
        ->and($billing['lineOne'])->toBe('Corso Buenos Aires 45')
        ->and($billing['taxIdentifier'])->toBe('BNCLRA85M41F205Z')
        ->and($billing['vatNo'])->toBe('BNCLRA85M41F205Z');
});

it('simulates a complete checkout and payment round with separated addresses and transaction capture', function () {
    // Step 1: User logs in and creates a cart by adding a shippable product
    $this->actingAs($this->user, 'sanctum');

    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 2) {
                id
                subTotal
                lines {
                    id
                    quantity
                    total
                }
            }
        }
    ', ['variantId' => $this->variant->id]);

    $addResponse->assertSuccessful();
    $cartId = $addResponse->json('data.addProductVariantToCart.id');
    expect($addResponse->json('data.addProductVariantToCart.subTotal'))->toBe(20000); // 2 * 100 EUR

    // Step 2: Set distinct Shipping Address
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $shipping: CartAddressInput!) {
            setCartShippingAddress(cartId: $cartId, address: $shipping) {
                id
                shippingAddress {
                    id
                    firstName
                    deliveryInstructions
                }
            }
        }
    ', [
        'cartId' => $cartId,
        'shipping' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'companyName' => 'Rossi Private Residence',
            'lineOne' => 'Via Garibaldi 15',
            'city' => 'Roma',
            'postcode' => '00100',
            'deliveryInstructions' => 'Citofonare interno 4, secondo piano',
            'contactEmail' => 'mario.rossi@example.com',
            'contactPhone' => '+39 06 12345678',
        ],
    ]);

    // Step 3: Set distinct Invoicing/Billing Address with taxIdentifier
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $billing: CartAddressInput!) {
            setCartBillingAddress(cartId: $cartId, address: $billing) {
                id
                billingAddress {
                    id
                    companyName
                    taxIdentifier
                    vatNo
                }
            }
        }
    ', [
        'cartId' => $cartId,
        'billing' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'companyName' => 'Rossi Consulting S.r.l.',
            'taxIdentifier' => 'IT98765432109',
            'lineOne' => 'Via del Corso 200',
            'city' => 'Roma',
            'postcode' => '00186',
            'contactEmail' => 'amministrazione@rossiconsulting.it',
        ],
    ]);

    // Step 4: Choose shipping option
    $shippingOptionsResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($cartId: ID!) {
            shippingOptions(cartId: $cartId) {
                identifier
                name
                price
            }
        }
    ', ['cartId' => $cartId]);

    $shippingOptionsResponse->assertSuccessful();
    $options = $shippingOptionsResponse->json('data.shippingOptions');
    expect($options)->not->toBeEmpty();
    $chosenOption = $options[0]['identifier'];

    $setOptionResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $option: String!) {
            setCartShippingOption(cartId: $cartId, shippingOption: $option) {
                id
                shippingTotal
                total
            }
        }
    ', [
        'cartId' => $cartId,
        'option' => $chosenOption,
    ]);
    $setOptionResponse->assertSuccessful();

    // Step 5: Convert Cart into Order
    $orderResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            createOrderFromCart(cartId: $cartId) {
                id
                reference
                paymentStatus
                fulfilmentStatus
                subTotal
                shippingTotal
                total
                shippingAddress {
                    firstName
                    deliveryInstructions
                    lineOne
                    city
                }
                billingAddress {
                    companyName
                    taxIdentifier
                    vatNo
                    lineOne
                    city
                }
            }
        }
    ', ['cartId' => $cartId]);

    $orderResponse->assertSuccessful();
    $orderData = $orderResponse->json('data.createOrderFromCart');
    $orderId = $orderData['id'];
    $orderRef = $orderData['reference'];
    $totalAmount = $orderData['total'];

    expect($orderData['paymentStatus'])->toBe('pending')
        ->and($orderData['shippingAddress']['deliveryInstructions'])->toBe('Citofonare interno 4, secondo piano')
        ->and($orderData['billingAddress']['companyName'])->toBe('Rossi Consulting S.r.l.')
        ->and($orderData['billingAddress']['taxIdentifier'])->toBe('IT98765432109')
        ->and($orderData['billingAddress']['vatNo'])->toBe('IT98765432109');

    // Step 6: Query payment providers & initiate payment intent
    $providersResponse = $this->graphQL(/** @lang GraphQL */ '
        query {
            paymentProviders {
                id
                handle
                name
            }
        }
    ');
    $providersResponse->assertSuccessful();

    $initiateResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            initiatePayment(orderId: $orderId, provider: "stripe") {
                clientSecret
                transactionId
                status
                requiresAction
            }
        }
    ', ['orderId' => $orderId]);
    $initiateResponse->assertSuccessful();

    // Step 7: Record successful payment transaction (capture)
    $captureResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!, $amount: Int!) {
            recordOrderTransaction(
                orderId: $orderId
                amount: $amount
                type: "capture"
                driver: "stripe"
                reference: "ch_stripe_round_test_999"
                status: "success"
                cardType: "visa"
                lastFour: "4242"
                notes: "Successful test payment capture"
                success: true
            ) {
                id
                amount
                type
                status
                success
                driver
                reference
                cardType
                lastFour
            }
        }
    ', [
        'orderId' => $orderId,
        'amount' => $totalAmount,
    ]);

    $captureResponse->assertSuccessful();
    $transaction = $captureResponse->json('data.recordOrderTransaction');
    expect($transaction['success'])->toBeTrue()
        ->and($transaction['amount'])->toBe($totalAmount)
        ->and($transaction['reference'])->toBe('ch_stripe_round_test_999');

    // Step 8: Verify Order state is now 'paid' with recorded transactions and addresses intact
    $finalOrderResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($orderId: ID!) {
            order(id: $orderId) {
                id
                reference
                paymentStatus
                total
                transactions {
                    id
                    amount
                    type
                    status
                    success
                    reference
                }
                shippingAddress {
                    firstName
                    deliveryInstructions
                }
                billingAddress {
                    companyName
                    taxIdentifier
                    vatNo
                }
            }
        }
    ', ['orderId' => $orderId]);

    $finalOrderResponse->assertSuccessful();
    $finalOrder = $finalOrderResponse->json('data.order');

    expect($finalOrder['paymentStatus'])->toBe('paid')
        ->and($finalOrder['transactions'])->toHaveCount(1)
        ->and($finalOrder['transactions'][0]['status'])->toBe('success')
        ->and($finalOrder['transactions'][0]['amount'])->toBe($totalAmount)
        ->and($finalOrder['shippingAddress']['deliveryInstructions'])->toBe('Citofonare interno 4, secondo piano')
        ->and($finalOrder['billingAddress']['taxIdentifier'])->toBe('IT98765432109')
        ->and($finalOrder['billingAddress']['vatNo'])->toBe('IT98765432109');
});

it('automatically falls back billing address to shipping address during checkout if billing was omitted', function () {
    // 1. Add variant to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);
    $cartId = $addResponse->json('data.addProductVariantToCart.id');

    // 2. Set ONLY shipping address (omitting billing address)
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $shipping: CartAddressInput!) {
            setCartShippingAddress(cartId: $cartId, address: $shipping) {
                id
            }
        }
    ', [
        'cartId' => $cartId,
        'shipping' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Francesco',
            'lastName' => 'Totti',
            'companyName' => 'Totti 10',
            'lineOne' => 'Via del Corso 10',
            'city' => 'Roma',
            'postcode' => '00100',
            'taxIdentifier' => 'TTTFNC76P27H501Z',
            'deliveryInstructions' => 'Lasciare alla reception',
        ],
    ]);

    // Select shipping option
    $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            setCartShippingOption(cartId: $cartId, shippingOption: "EXPRESS_CORRIERE") {
                id
            }
        }
    ', ['cartId' => $cartId]);

    // 3. Checkout without having called setCartBillingAddress
    $orderResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            createOrderFromCart(cartId: $cartId) {
                id
                shippingAddress {
                    firstName
                    lastName
                    lineOne
                    deliveryInstructions
                }
                billingAddress {
                    firstName
                    lastName
                    lineOne
                    taxIdentifier
                    vatNo
                }
            }
        }
    ', ['cartId' => $cartId]);

    $orderResponse->assertSuccessful();
    $orderData = $orderResponse->json('data.createOrderFromCart');

    // Both addresses should be populated, with billing falling back to shipping
    expect($orderData['shippingAddress']['firstName'])->toBe('Francesco')
        ->and($orderData['shippingAddress']['deliveryInstructions'])->toBe('Lasciare alla reception')
        ->and($orderData['billingAddress']['firstName'])->toBe('Francesco')
        ->and($orderData['billingAddress']['lineOne'])->toBe('Via del Corso 10')
        ->and($orderData['billingAddress']['taxIdentifier'])->toBe('TTTFNC76P27H501Z')
        ->and($orderData['billingAddress']['vatNo'])->toBe('TTTFNC76P27H501Z');
});

it('rejects order creation if shippable cart lacks shipping address', function () {
    // Add shippable item to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);
    $cartId = $addResponse->json('data.addProductVariantToCart.id');

    // Attempt checkout without setting shipping address
    $orderResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            createOrderFromCart(cartId: $cartId) {
                id
            }
        }
    ', ['cartId' => $cartId]);

    $orderResponse->assertJsonStructure([
        'errors' => [
            ['message'],
        ],
    ]);
    expect($orderResponse->json('errors.0.message'))->toContain('shipping');
});

it('supports saved customer addresses with taxIdentifier and sync flags on cart', function () {
    $this->actingAs($this->user, 'sanctum');

    // 1. Create a customer profile address with taxIdentifier
    $createAddressResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($address: AddressInput!) {
            createCustomerAddress(address: $address) {
                id
                firstName
                lastName
                companyName
                taxIdentifier
                vatNo
                city
                lineOne
            }
        }
    ', [
        'address' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'companyName' => 'Rossi & Partners S.p.A.',
            'taxIdentifier' => 'IT11223344556',
            'lineOne' => 'Via Dante 10',
            'city' => 'Milano',
            'postcode' => '20121',
        ],
    ]);

    $createAddressResponse->assertSuccessful();
    $savedAddress = $createAddressResponse->json('data.createCustomerAddress');
    $addressId = $savedAddress['id'];
    expect($savedAddress['taxIdentifier'])->toBe('IT11223344556')
        ->and($savedAddress['vatNo'])->toBe('IT11223344556');

    // 2. Add product to cart
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($variantId: ID!) {
            addProductVariantToCart(productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', ['variantId' => $this->variant->id]);
    $cartId = $addResponse->json('data.addProductVariantToCart.id');

    // 3. Set cart customer shipping address with sameAsBilling: true
    $setCustomerShippingResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $addressId: ID!) {
            setCartCustomerShippingAddress(cartId: $cartId, customerAddressId: $addressId, sameAsBilling: true) {
                id
                shippingAddress {
                    companyName
                    lineOne
                    city
                }
                billingAddress {
                    companyName
                    lineOne
                    taxIdentifier
                    vatNo
                }
            }
        }
    ', [
        'cartId' => $cartId,
        'addressId' => $addressId,
    ]);

    $setCustomerShippingResponse->assertSuccessful();
    $cartShipping = $setCustomerShippingResponse->json('data.setCartCustomerShippingAddress.shippingAddress');
    $cartBilling = $setCustomerShippingResponse->json('data.setCustomerShippingResponse.billingAddress')
        ?? $setCustomerShippingResponse->json('data.setCartCustomerShippingAddress.billingAddress');

    expect($cartShipping['companyName'])->toBe('Rossi & Partners S.p.A.')
        ->and($cartBilling['companyName'])->toBe('Rossi & Partners S.p.A.')
        ->and($cartBilling['taxIdentifier'])->toBe('IT11223344556')
        ->and($cartBilling['vatNo'])->toBe('IT11223344556');
});

