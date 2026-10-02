<?php

use Lunar\Core\Models\Order;
use Lunar\Core\Models\OrderAddress;
use Lunar\Core\Models\OrderLine;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\ValueObjects\Cart\TaxBreakdown;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'order-type'], [
        'name' => 'Order Product Type',
        'status' => 'active',
    ]);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Order Item'],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'TEST-ORD-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'stock_on_hand' => 10,
        'stock_available' => 10,
    ]);

    $this->order = Order::create([
        'channel_id' => $this->defaultChannel->id,
        'currency_code' => $this->defaultCurrency->code,
        'payment_status' => 'paid',
        'fulfilment_status' => 'unfulfilled',
        'sub_total' => 6000,
        'discount_total' => 1000,
        'shipping_total' => 500,
        'tax_total' => 1100,
        'total' => 6600,
        'tax_breakdown' => new TaxBreakdown,
        'reference' => 'ORD-GUEST-12345',
        'placed_at' => now(),
    ]);

    // Product line
    $this->productLine = OrderLine::create([
        'order_id' => $this->order->id,
        'purchasable_type' => $this->variant->getMorphClass(),
        'purchasable_id' => $this->variant->id,
        'type' => 'physical',
        'description' => 'Test Product Line',
        'identifier' => $this->variant->sku,
        'unit_price' => 3000,
        'unit_quantity' => 1,
        'quantity' => 2,
        'sub_total' => 6000,
        'discount_total' => 1000,
        'tax_total' => 1000,
        'total' => 6000,
        'requires_shipping' => true,
        'requires_fulfilment' => true,
        'tax_breakdown' => new TaxBreakdown,
    ]);

    // Shipping line
    $this->shippingLine = OrderLine::create([
        'order_id' => $this->order->id,
        'type' => 'shipping',
        'description' => 'Express Shipping',
        'identifier' => 'exp_ship',
        'unit_price' => 500,
        'unit_quantity' => 1,
        'quantity' => 1,
        'sub_total' => 500,
        'discount_total' => 0,
        'tax_total' => 100,
        'total' => 600,
        'requires_shipping' => false,
        'requires_fulfilment' => false,
        'tax_breakdown' => new TaxBreakdown,
    ]);

    // Order Address
    OrderAddress::create([
        'order_id' => $this->order->id,
        'type' => 'billing',
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'contact_email' => 'mario.rossi@example.com',
        'line_one' => 'Via Roma 1',
        'city' => 'Roma',
        'postcode' => '00100',
    ]);
});

it('can query order with lifecycle status, cancellation flags, and formatted totals', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetOrder($ref: String!, $email: String!) {
            guestOrder(reference: $ref, email: $email) {
                id
                reference
                isOpen
                isClosed
                isCancelled
                isPlaced
                isDraft
                lifecycleStatus
                subTotal
                subTotalFormatted
                discountTotal
                discountTotalFormatted
                shippingTotal
                shippingTotalFormatted
                taxTotal
                taxTotalFormatted
                total
                totalFormatted
            }
        }
    ', [
        'ref' => 'ORD-GUEST-12345',
        'email' => 'mario.rossi@example.com',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.guestOrder');

    expect($data['reference'])->toBe('ORD-GUEST-12345')
        ->and($data['isOpen'])->toBeTrue()
        ->and($data['isClosed'])->toBeFalse()
        ->and($data['isCancelled'])->toBeFalse()
        ->and($data['isPlaced'])->toBeTrue()
        ->and($data['isDraft'])->toBeFalse()
        ->and($data['lifecycleStatus'])->toBe('open')
        ->and($data['subTotalFormatted'])->toContain('60.00')
        ->and($data['discountTotalFormatted'])->toContain('10.00')
        ->and($data['shippingTotalFormatted'])->toContain('5.00')
        ->and($data['totalFormatted'])->toContain('66.00');
});

it('can query order with line subsets like shippingLines and productLines', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetOrder($ref: String!, $email: String!) {
            guestOrder(reference: $ref, email: $email) {
                id
                lines {
                    id
                }
                productLines {
                    id
                    description
                    type
                }
                shippingLines {
                    id
                    description
                    type
                }
            }
        }
    ', [
        'ref' => 'ORD-GUEST-12345',
        'email' => 'mario.rossi@example.com',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.guestOrder');

    expect($data['lines'])->toHaveCount(2)
        ->and($data['productLines'])->toHaveCount(1)
        ->and($data['productLines'][0]['description'])->toBe('Test Product Line')
        ->and($data['shippingLines'])->toHaveCount(1)
        ->and($data['shippingLines'][0]['description'])->toBe('Express Shipping');
});

it('can query order lines with formatted price strings', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetOrder($ref: String!, $email: String!) {
            guestOrder(reference: $ref, email: $email) {
                id
                lines {
                    id
                    description
                    requiresShipping
                    requiresFulfilment
                    unitPrice
                    unitPriceFormatted
                    subTotal
                    subTotalFormatted
                    total
                    totalFormatted
                }
            }
        }
    ', [
        'ref' => 'ORD-GUEST-12345',
        'email' => 'mario.rossi@example.com',
    ]);

    $response->assertSuccessful();
    $lines = $response->json('data.guestOrder.lines');

    expect($lines[0]['requiresShipping'])->toBeTrue()
        ->and($lines[0]['requiresFulfilment'])->toBeTrue()
        ->and($lines[0]['unitPriceFormatted'])->toContain('30.00')
        ->and($lines[0]['subTotalFormatted'])->toContain('60.00')
        ->and($lines[0]['totalFormatted'])->toContain('60.00');
});

it('can query guest order securely by reference and email', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetGuestOrder($ref: String!, $email: String!) {
            guestOrder(reference: $ref, email: $email) {
                id
                reference
                total
                billingAddress {
                    firstName
                    contactEmail
                }
            }
        }
    ', [
        'ref' => 'ORD-GUEST-12345',
        'email' => 'mario.rossi@example.com',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.guestOrder');

    expect($data)->not->toBeNull()
        ->and($data['reference'])->toBe('ORD-GUEST-12345')
        ->and($data['billingAddress']['contactEmail'])->toBe('mario.rossi@example.com');
});

it('rejects guest order query when email does not match', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetGuestOrder($ref: String!, $email: String!) {
            guestOrder(reference: $ref, email: $email) {
                id
                reference
            }
        }
    ', [
        'ref' => 'ORD-GUEST-12345',
        'email' => 'wrong.email@example.com',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.guestOrder');

    expect($data)->toBeNull();
});

it('does not expose guest orders by reference without email verification or session ownership', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            orderByReference(reference: "ORD-GUEST-12345") {
                reference
                total
            }
        }
    ');

    expect($response->json('errors'))->not->toBeNull()
        ->and($response->json('data.orderByReference'))->toBeNull();
});
