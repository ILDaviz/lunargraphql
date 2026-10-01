<?php

use Illuminate\Support\Facades\Hash;
use Lunar\Core\FieldTypes\Text;
use Lunar\Core\Models\Attribute;
use Lunar\Core\Models\AttributeGroup;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Collection;
use Lunar\Core\Models\CollectionGroup;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Customer;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\ValueObjects\Cart\TaxBreakdown;
use Lunargraphql\Tests\Models\User;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'default-type'], [
        'name' => 'Default Type',
        'status' => 'active',
    ]);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Test Sneaker'],
    ]);

    $this->product->channels()->sync([
        $this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'VAR-TEST-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'stock_on_hand' => 20,
        'stock_available' => 20,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 5000,
        'min_quantity' => 1,
    ]);
});

it('rejects registration with invalid email format', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($name: String!, $email: String!, $password: String!) {
            createUser(name: $name, email: $email, password: $password) {
                token
            }
        }
    ', [
        'name' => 'Bad Email User',
        'email' => 'not-an-email',
        'password' => 'validPassword123',
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('Invalid email');
});

it('rejects registration with weak password under 8 characters', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($name: String!, $email: String!, $password: String!) {
            createUser(name: $name, email: $email, password: $password) {
                token
            }
        }
    ', [
        'name' => 'Short Pass User',
        'email' => 'valid@example.com',
        'password' => 'short',
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('at least 8 characters');
});

it('enforces password confirmation check on resetPassword', function () {
    $user = User::create([
        'name' => 'Charlie Brown',
        'email' => 'charlie@example.com',
        'password' => Hash::make('oldpassword123'),
    ]);

    $response = $this->actingAs($user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($password: String!, $confirmation: String) {
            resetPassword(password: $password, passwordConfirmation: $confirmation)
        }
    ', [
        'password' => 'newPassword123',
        'confirmation' => 'mismatchingPassword',
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('confirmation does not match');
});

it('prevents user from updating profile email to an existing email', function () {
    $user1 = User::create([
        'name' => 'User One',
        'email' => 'user1@example.com',
        'password' => Hash::make('password123'),
    ]);

    $user2 = User::create([
        'name' => 'User Two',
        'email' => 'user2@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->actingAs($user1, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($email: String) {
            updateUserProfile(email: $email) {
                id
                email
            }
        }
    ', [
        'email' => 'user2@example.com',
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('already in use');
});

it('supports headless cart operations with explicit cartId', function () {
    // 1. Create a cart explicitly
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
    ]);

    // 2. Fetch cart by cartId without relying on PHP session
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($cartId: ID!) {
            getCart(cartId: $cartId) {
                id
                totalQuantity
                subTotal
            }
        }
    ', [
        'cartId' => $cart->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('data.getCart.totalQuantity'))->toBe(0);

    // 3. Add item with cartId
    $addResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: $variantId, quantity: 2) {
                id
                totalQuantity
                subTotal
            }
        }
    ', [
        'cartId' => $cart->id,
        'variantId' => $this->variant->id,
    ]);

    $addResponse->assertSuccessful();
    expect($addResponse->json('data.addProductVariantToCart.totalQuantity'))->toBe(2);
    expect($addResponse->json('data.addProductVariantToCart.subTotal'))->toBe(10000);
});

it('associates guest cart with user upon login', function () {
    $user = User::create([
        'name' => 'Cart Owner',
        'email' => 'cartowner@example.com',
        'password' => Hash::make('secretPassword123'),
    ]);

    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
    ]);
    $cart->add($this->variant, 1);

    expect($cart->fresh()->user_id)->toBeNull();

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($email: String!, $password: String!, $cartId: ID) {
            login(email: $email, password: $password, cartId: $cartId) {
                token
                user {
                    id
                    email
                }
            }
        }
    ', [
        'email' => 'cartowner@example.com',
        'password' => 'secretPassword123',
        'cartId' => $cart->id,
    ]);

    $response->assertSuccessful();
    expect($cart->fresh()->user_id)->toBe($user->id);
});

it('can query payment providers and initiate payment intent', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
    ]);
    $cart->add($this->variant, 1);

    // Query payment providers
    $providersResponse = $this->graphQL(/** @lang GraphQL */ '
        query {
            paymentProviders {
                handle
                name
                driver
                enabled
            }
        }
    ');

    $providersResponse->assertSuccessful();
    expect($providersResponse->json('data.paymentProviders'))->not->toBeEmpty();

    // Initiate payment intent
    $intentResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            initiatePayment(cartId: $cartId, provider: "stripe") {
                success
                clientSecret
                status
                requiresAction
            }
        }
    ', [
        'cartId' => $cart->id,
    ]);

    $intentResponse->assertSuccessful();
    $intent = $intentResponse->json('data.initiatePayment');
    expect($intent['success'])->toBeTrue();
    expect($intent['clientSecret'])->toStartWith('pi_');
    expect($intent['status'])->toBe('requires_payment_method');
});

it('filters out draft products by default in catalog query', function () {
    Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
        'name' => ['en' => 'Unpublished Secret Sneaker'],
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog {
                data {
                    id
                    status
                    name
                }
            }
        }
    ');

    $response->assertSuccessful();
    $products = $response->json('data.catalog.data');

    foreach ($products as $p) {
        expect($p['status'])->toBe('published');
    }
});

it('supports sorting catalog products by id and created_at', function () {
    $product2 = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Zebra Runner'],
    ]);

    $responseDesc = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog(filter: { sortBy: "id", sortDir: "desc" }) {
                data {
                    id
                    name
                }
            }
        }
    ');

    $responseDesc->assertSuccessful();
    $items = $responseDesc->json('data.catalog.data');
    expect(count($items))->toBeGreaterThanOrEqual(2);
    expect($items[0]['name'])->toBe('Zebra Runner');
    expect($items[1]['name'])->toBe('Test Sneaker');

    $responseAsc = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog(filter: { sortBy: "id", sortDir: "asc" }) {
                data {
                    id
                    name
                }
            }
        }
    ');

    $responseAsc->assertSuccessful();
    $itemsAsc = $responseAsc->json('data.catalog.data');
    expect($itemsAsc[0]['name'])->toBe('Test Sneaker');
    expect($itemsAsc[1]['name'])->toBe('Zebra Runner');
});

it('prevents unauthenticated guest from accessing a registered user cart via cartId', function () {
    $user = User::create([
        'name' => 'Protected User',
        'email' => 'protected@example.com',
        'password' => Hash::make('secretPassword123'),
    ]);

    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
        'user_id' => $user->id,
    ]);

    // Unauthenticated guest request providing the registered user's cartId
    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($cartId: ID!) {
            getCart(cartId: $cartId) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('Cart not found');
});

it('prevents modifying a cart after its order has been completed', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
    ]);
    $cart->add($this->variant, 1);

    Order::create([
        'cart_id' => $cart->id,
        'channel_id' => $this->defaultChannel->id,
        'currency_code' => $this->defaultCurrency->code,
        'placed_at' => now(),
        'sub_total' => 5000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'total' => 5000,
        'tax_breakdown' => new TaxBreakdown,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: $variantId, quantity: 1) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
        'variantId' => $this->variant->id,
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('already been converted into an order');
});

it('enforces stock availability limit on updateCartLine', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
    ]);

    $limitedVariant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'STOCK-LIMITED-001',
        'unit_quantity' => 1,
        'selling_policy' => 'in_stock',
        'enabled' => true,
        'stock_on_hand' => 5,
        'stock_available' => 5,
    ]);
    $limitedVariant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 2000,
        'min_quantity' => 1,
    ]);

    $cartLine = $cart->add($limitedVariant, 2);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $cartLineId: ID!, $qty: Int!) {
            updateCartLine(cartId: $cartId, cartLineID: $cartLineId, quantity: $qty) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
        'cartLineId' => $cartLine->id,
        'qty' => 10,
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('Insufficient stock available');
});

it('can assign saved customer address to cart and save new address during checkout', function () {
    $user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@example.com',
        'password' => Hash::make('password123'),
    ]);

    $customer = Customer::create([
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
    ]);
    $user->customers()->attach($customer);

    $customerAddress = $customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'line_one' => 'Corso Buenos Aires 10',
        'city' => 'Milano',
        'postcode' => '20124',
    ]);

    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
        'user_id' => $user->id,
    ]);
    $cart->add($this->variant, 1);

    // 1. Assign saved customer address to cart as shipping address
    $shippingResponse = $this->actingAs($user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $addressId: ID!) {
            setCartCustomerShippingAddress(cartId: $cartId, customerAddressId: $addressId) {
                id
                shippingAddress {
                    lineOne
                    city
                    postcode
                }
            }
        }
    ', [
        'cartId' => $cart->id,
        'addressId' => $customerAddress->id,
    ]);

    $shippingResponse->assertSuccessful();
    expect($shippingResponse->json('data.setCartCustomerShippingAddress.shippingAddress.lineOne'))->toBe('Corso Buenos Aires 10');

    // 2. Set new billing address with saveAddress = true
    $billingResponse = $this->actingAs($user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $address: CartAddressInput!) {
            setCartBillingAddress(cartId: $cartId, address: $address) {
                id
                billingAddress {
                    lineOne
                    city
                }
            }
        }
    ', [
        'cartId' => $cart->id,
        'address' => [
            'countryId' => $this->defaultCountry->id,
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'lineOne' => 'Via Montenapoleone 8',
            'city' => 'Milano',
            'postcode' => '20121',
            'saveAddress' => true,
        ],
    ]);

    $billingResponse->assertSuccessful();
    expect($billingResponse->json('data.setCartBillingAddress.billingAddress.lineOne'))->toBe('Via Montenapoleone 8');

    // Verify it was saved to customer profile
    expect($customer->addresses()->count())->toBe(2);
    expect($customer->addresses()->where('line_one', 'Via Montenapoleone 8')->exists())->toBeTrue();
});

it('supports updating customer address with country ISO code', function () {
    $user = User::create([
        'name' => 'Luigi Verdi',
        'email' => 'luigi@example.com',
        'password' => Hash::make('password123'),
    ]);

    $customer = Customer::create([
        'first_name' => 'Luigi',
        'last_name' => 'Verdi',
    ]);
    $user->customers()->attach($customer);

    $address = $customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Luigi',
        'last_name' => 'Verdi',
        'line_one' => 'Via Roma 1',
        'city' => 'Torino',
        'postcode' => '10100',
    ]);

    $response = $this->actingAs($user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($id: ID!, $address: AddressInput!) {
            updateCustomerAddress(id: $id, address: $address) {
                id
                city
                lineOne
                country {
                    id
                    iso2
                }
            }
        }
    ', [
        'id' => $address->id,
        'address' => [
            'country' => $this->defaultCountry->iso2,
            'firstName' => 'Luigi',
            'lastName' => 'Verdi',
            'lineOne' => 'Via Roma 50',
            'city' => 'Torino',
            'postcode' => '10100',
        ],
    ]);

    $response->assertSuccessful();
    expect($response->json('data.updateCustomerAddress.lineOne'))->toBe('Via Roma 50');
    expect($response->json('data.updateCustomerAddress.country.iso2'))->toBe($this->defaultCountry->iso2);
});

it('supports querying product and variant price in specific currency', function () {
    $usdCurrency = Currency::firstOrCreate(['code' => 'USD'], [
        'name' => 'US Dollar',
        'exchange_rate' => 1.1,
        'decimal_places' => 2,
        'enabled' => true,
        'default' => false,
    ]);

    $this->variant->prices()->create([
        'currency_id' => $usdCurrency->id,
        'price' => 6000,
        'min_quantity' => 1,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($productId: ID!, $variantId: ID!) {
            product(id: $productId) {
                id
                price(currency: "USD") {
                    price
                    priceFormatted
                }
            }
            productVariant(id: $variantId) {
                id
                price(currency: "USD") {
                    price
                    priceFormatted
                }
            }
        }
    ', [
        'productId' => $this->product->id,
        'variantId' => $this->variant->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('data.product.price.price'))->toBe(6000);
    expect($response->json('data.productVariant.price.price'))->toBe(6000);
});

it('can paginate products directly on collection type via paginatedProducts', function () {
    $collectionGroup = CollectionGroup::create([
        'name' => 'Main Group',
        'handle' => 'main-group',
    ]);

    $collection = Collection::create([
        'name' => ['en' => 'Footwear'],
        'collection_group_id' => $collectionGroup->id,
    ]);

    $collection->products()->attach($this->product);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($collectionId: ID!) {
            collection(id: $collectionId) {
                id
                paginatedProducts(first: 5) {
                    data {
                        id
                        name
                    }
                    paginatorInfo {
                        total
                        count
                    }
                }
            }
        }
    ', [
        'collectionId' => $collection->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('data.collection.paginatedProducts.paginatorInfo.total'))->toBe(1);
    expect($response->json('data.collection.paginatedProducts.data.0.name'))->toBe('Test Sneaker');
});

it('prevents guests from querying draft products via product query and productBySlug', function () {
    $draftProduct = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
        'name' => ['en' => 'Unreleased Shoe'],
    ]);

    $draftProduct->urls()->create([
        'slug' => 'unreleased-shoe-secret',
        'default' => true,
        'language_id' => $this->defaultLanguage->id,
    ]);

    // 1. Guest querying product by ID returns null
    $responseId = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            product(id: $id) {
                id
                name
            }
        }
    ', [
        'id' => $draftProduct->id,
    ]);

    $responseId->assertSuccessful();
    expect($responseId->json('data.product'))->toBeNull();

    // 2. Guest querying product by slug returns null
    $responseSlug = $this->graphQL(/** @lang GraphQL */ '
        query ($slug: String!) {
            productBySlug(slug: $slug) {
                id
                name
            }
        }
    ', [
        'slug' => 'unreleased-shoe-secret',
    ]);

    $responseSlug->assertSuccessful();
    expect($responseSlug->json('data.productBySlug'))->toBeNull();

    // 3. Authenticated user can preview draft product
    $user = User::create([
        'name' => 'Store Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
    ]);

    $authResponse = $this->actingAs($user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            product(id: $id) {
                id
                name
            }
        }
    ', [
        'id' => $draftProduct->id,
    ]);

    $authResponse->assertSuccessful();
    expect($authResponse->json('data.product.name'))->toBe('Unreleased Shoe');
});

it('prevents guests from querying disabled product variants via productVariant query', function () {
    $disabledVariant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'DISABLED-VAR-001',
        'unit_quantity' => 1,
        'selling_policy' => 'in_stock',
        'enabled' => false,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            productVariant(id: $id) {
                id
                sku
            }
        }
    ', [
        'id' => $disabledVariant->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('data.productVariant'))->toBeNull();
});

it('validates transaction amount and updates order payment_status to paid when total captured matches order total', function () {
    $order = Order::create([
        'channel_id' => $this->defaultChannel->id,
        'currency_code' => $this->defaultCurrency->code,
        'sub_total' => 5000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'total' => 5000,
        'payment_status' => 'pending',
        'tax_breakdown' => new TaxBreakdown,
    ]);

    // 1. Rejects non-positive amount
    $zeroResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            recordOrderTransaction(orderId: $orderId, amount: 0) {
                id
            }
        }
    ', [
        'orderId' => $order->id,
    ]);

    expect($zeroResponse->json('errors'))->not->toBeNull();
    expect($zeroResponse->json('errors.0.message'))->toContain('greater than zero');

    // 2. Rejects amount exceeding order total
    $exceedResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            recordOrderTransaction(orderId: $orderId, amount: 999999) {
                id
            }
        }
    ', [
        'orderId' => $order->id,
    ]);

    expect($exceedResponse->json('errors'))->not->toBeNull();
    expect($exceedResponse->json('errors.0.message'))->toContain('cannot exceed order total');

    // 3. Captures full amount and updates order payment_status to paid
    $captureResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            recordOrderTransaction(
                orderId: $orderId
                amount: 5000
                type: "capture"
                driver: "manual"
                status: "success"
                success: true
            ) {
                id
                status
                amount
            }
        }
    ', [
        'orderId' => $order->id,
    ]);

    $captureResponse->assertSuccessful();
    expect((string) $order->fresh()->payment_status)->toBe('paid');

    // 4. Rejects subsequent capture if already paid
    $duplicateCapture = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            recordOrderTransaction(
                orderId: $orderId
                amount: 5000
                type: "capture"
                driver: "manual"
                status: "success"
                success: true
            ) {
                id
            }
        }
    ', [
        'orderId' => $order->id,
    ]);

    expect($duplicateCapture->json('errors'))->not->toBeNull();
    expect($duplicateCapture->json('errors.0.message'))->toContain('already been paid');
});

it('rejects changing currency to a disabled currency or on a completed cart', function () {
    $disabledCurrency = Currency::where('code', 'GBP')->first();
    $disabledCurrency->update(['enabled' => false]);

    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'region_id' => $this->defaultRegion->id,
    ]);

    // 1. Rejects disabled currency
    $disabledResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $code: String!) {
            setCartCurrency(cartId: $cartId, currencyCode: $code) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
        'code' => 'GBP',
    ]);

    expect($disabledResponse->json('errors'))->not->toBeNull();
    expect($disabledResponse->json('errors.0.message'))->toContain('disabled');

    // 2. Rejects currency change on completed cart
    Order::create([
        'cart_id' => $cart->id,
        'channel_id' => $this->defaultChannel->id,
        'currency_code' => $this->defaultCurrency->code,
        'placed_at' => now(),
        'sub_total' => 1000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'total' => 1000,
        'tax_breakdown' => new TaxBreakdown,
    ]);

    $completedResponse = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $code: String!) {
            setCartCurrency(cartId: $cartId, currencyCode: $code) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
        'code' => $this->defaultCurrency->code,
    ]);

    expect($completedResponse->json('errors'))->not->toBeNull();
    expect($completedResponse->json('errors.0.message'))->toContain('already been converted into an order');
});

it('promotes the next address to default when deleting the default address', function () {
    $user = User::create([
        'name' => 'Address User',
        'email' => 'addressuser@example.com',
        'password' => Hash::make('password123'),
    ]);

    $customer = Customer::create([
        'first_name' => 'Address',
        'last_name' => 'User',
    ]);
    $user->customers()->attach($customer);

    $addr1 = $customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Address',
        'last_name' => 'One',
        'line_one' => 'Via Uno 1',
        'city' => 'Roma',
        'postcode' => '00100',
        'shipping_default' => true,
        'billing_default' => true,
    ]);

    $addr2 = $customer->addresses()->create([
        'country_id' => $this->defaultCountry->id,
        'first_name' => 'Address',
        'last_name' => 'Two',
        'line_one' => 'Via Due 2',
        'city' => 'Roma',
        'postcode' => '00100',
        'shipping_default' => false,
        'billing_default' => false,
    ]);

    $response = $this->actingAs($user, 'sanctum')->graphQL(/** @lang GraphQL */ '
        mutation ($id: ID!) {
            deleteCustomerAddress(id: $id)
        }
    ', [
        'id' => $addr1->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('data.deleteCustomerAddress'))->toBeTrue();

    // Verify addr2 was promoted to default
    expect($addr2->fresh()->shipping_default)->toBeTrue();
    expect($addr2->fresh()->billing_default)->toBeTrue();
});

it('enforces total cart line stock limit across multiple additions', function () {
    $limitedVariant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'VAR-LIMITED-001',
        'unit_quantity' => 1,
        'selling_policy' => 'in_stock',
        'enabled' => true,
        'stock_on_hand' => 5,
        'stock_available' => 5,
    ]);

    $limitedVariant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 2000,
        'min_quantity' => 1,
    ]);

    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    // First addition: 3 items (allowed, 3 <= 5)
    $res1 = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!, $quantity: Int!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: $variantId, quantity: $quantity) {
                id
                lines {
                    id
                    quantity
                }
            }
        }
    ', [
        'cartId' => $cart->id,
        'variantId' => $limitedVariant->id,
        'quantity' => 3,
    ]);
    $res1->assertSuccessful();
    expect($res1->json('data.addProductVariantToCart.lines.0.quantity'))->toBe(3);

    // Second addition: another 3 items (cumulative 6 > 5 -> should fail)
    $res2 = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!, $quantity: Int!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: $variantId, quantity: $quantity) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
        'variantId' => $limitedVariant->id,
        'quantity' => 3,
    ]);
    expect($res2->json('errors'))->not->toBeNull();
    expect($res2->json('errors.0.message'))->toContain('Insufficient stock available');

    // Third addition: 2 items (cumulative 3 + 2 = 5 <= 5 -> should succeed)
    $res3 = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!, $quantity: Int!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: $variantId, quantity: $quantity) {
                id
                lines {
                    id
                    quantity
                }
            }
        }
    ', [
        'cartId' => $cart->id,
        'variantId' => $limitedVariant->id,
        'quantity' => 2,
    ]);
    $res3->assertSuccessful();
    expect($res3->json('data.addProductVariantToCart.lines.0.quantity'))->toBe(5);
});

it('prevents user from hijacking another user cart upon login or registration', function () {
    $userA = User::create([
        'name' => 'User Alpha',
        'email' => 'alpha@example.com',
        'password' => Hash::make('password123'),
    ]);

    $cartA = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
        'user_id' => $userA->id,
    ]);

    // Attacker registers and attempts to claim cartA
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($name: String!, $email: String!, $password: String!, $cartId: ID!) {
            createUser(name: $name, email: $email, password: $password, cartId: $cartId) {
                token
                user {
                    id
                }
            }
        }
    ', [
        'name' => 'Attacker Beta',
        'email' => 'attacker@example.com',
        'password' => 'password123',
        'cartId' => $cartA->id,
    ]);

    $response->assertSuccessful();
    $cartA->refresh();
    expect($cartA->user_id)->toBe($userA->id);
});

it('can sort catalog products by price in asc and desc directions', function () {
    $prodCheap = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Budget Item'],
    ]);
    $prodCheap->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);
    $varCheap = ProductVariant::create([
        'product_id' => $prodCheap->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'CHEAP-01',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
    ]);
    $varCheap->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 1000,
        'min_quantity' => 1,
    ]);

    $prodExpensive = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Luxury Item'],
    ]);
    $prodExpensive->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);
    $varExpensive = ProductVariant::create([
        'product_id' => $prodExpensive->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'EXP-01',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
    ]);
    $varExpensive->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 99000,
        'min_quantity' => 1,
    ]);

    // Ascending price sort
    $resAsc = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog(filter: { sortBy: "price", sortDir: "asc" }) {
                data {
                    id
                    name
                }
            }
        }
    ');
    $resAsc->assertSuccessful();
    $dataAsc = $resAsc->json('data.catalog.data');
    expect($dataAsc[0]['name'])->toBe('Budget Item');

    // Descending price sort
    $resDesc = $this->graphQL(/** @lang GraphQL */ '
        query {
            catalog(filter: { sortBy: "price", sortDir: "desc" }) {
                data {
                    id
                    name
                }
            }
        }
    ');
    $resDesc->assertSuccessful();
    $dataDesc = $resDesc->json('data.catalog.data');
    expect($dataDesc[0]['name'])->toBe('Luxury Item');
});

it('can query filterable attributes without SQL error', function () {
    $group = AttributeGroup::firstOrCreate([
        'handle' => 'test-group',
    ], [
        'name' => 'Test Group',
        'position' => 1,
    ]);

    $attr = Attribute::create([
        'attribute_group_id' => $group->id,
        'name' => 'Color',
        'handle' => 'color',
        'type' => Text::class,
        'position' => 1,
        'searchable' => true,
        'filterable' => true,
        'system' => false,
    ]);

    $attr->models()->create([
        'model_type' => (new Product)->getMorphClass(),
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            getFilterableAttributes {
                id
                handle
                name
            }
        }
    ');

    $response->assertSuccessful();
    $attributes = $response->json('data.getFilterableAttributes');
    expect($attributes)->toBeArray();
    expect(collect($attributes)->pluck('handle')->contains('color'))->toBeTrue();
});

it('supports filtering products within collection paginatedProducts', function () {
    $collectionGroup = CollectionGroup::firstOrCreate([
        'handle' => 'sports',
    ], [
        'name' => 'Sports',
    ]);

    $collection = Collection::create([
        'name' => ['en' => 'Apparel'],
        'collection_group_id' => $collectionGroup->id,
    ]);

    $runningShoe = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Running Shoe'],
    ]);
    $runningShoe->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);

    $yogaMat = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Yoga Mat'],
    ]);
    $yogaMat->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);

    $collection->products()->attach([$runningShoe->id, $yogaMat->id]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($collectionId: ID!) {
            collection(id: $collectionId) {
                id
                paginatedProducts(filter: { search: "Running" }, first: 10) {
                    data {
                        id
                        name
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        }
    ', [
        'collectionId' => $collection->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('data.collection.paginatedProducts.paginatorInfo.total'))->toBe(1);
    expect($response->json('data.collection.paginatedProducts.data.0.name'))->toBe('Running Shoe');
});

it('rejects empty coupon code in applyCouponToCart', function () {
    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            applyCouponToCart(cartId: $cartId, coupon: "   ") {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('valid coupon code is required');
});

it('rejects password reset with invalid email format or empty token', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($email: String!, $token: String!, $password: String!, $passwordConfirmation: String) {
            sendResetPasswordFromToken(email: $email, token: $token, password: $password, passwordConfirmation: $passwordConfirmation)
        }
    ', [
        'email' => 'invalid-email',
        'token' => 'sample-token',
        'password' => 'newPassword123',
        'passwordConfirmation' => 'newPassword123',
    ]);

    expect($response->json('errors'))->not->toBeNull();
    expect($response->json('errors.0.message'))->toContain('Invalid email');

    $response2 = $this->graphQL(/** @lang GraphQL */ '
        mutation ($email: String!, $password: String!, $passwordConfirmation: String) {
            sendResetPasswordFromToken(email: $email, token: "   ", password: $password, passwordConfirmation: $passwordConfirmation)
        }
    ', [
        'email' => 'valid@example.com',
        'password' => 'newPassword123',
        'passwordConfirmation' => 'newPassword123',
    ]);

    expect($response2->json('errors'))->not->toBeNull();
    expect($response2->json('errors.0.message'))->toContain('Invalid token');
});
