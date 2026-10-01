<?php

use Lunar\Core\Models\Location;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\ValueObjects\Cart\TaxBreakdown;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'default-type'], [
        'name' => 'Default Type',
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
        'sku' => 'ORD-VAR-001',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'stock_on_hand' => 10,
        'stock_available' => 10,
    ]);

    $this->order = Order::create([
        'channel_id' => $this->defaultChannel->id,
        'currency_code' => $this->defaultCurrency->code,
        'payment_status' => 'pending',
        'fulfilment_status' => 'unfulfilled',
        'sub_total' => 5000,
        'discount_total' => 0,
        'shipping_total' => 500,
        'tax_total' => 1100,
        'total' => 6600,
        'tax_breakdown' => new TaxBreakdown,
        'reference' => 'ORD-REF-777',
        'customer_reference' => 'CUST-REF-888',
    ]);
});

it('can query an order by reference', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            orderByReference(reference: "ORD-REF-777") {
                id
                reference
                customerReference
                paymentStatus
                fulfilmentStatus
                total
                subTotal
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.orderByReference');

    expect($data['reference'])->toBe('ORD-REF-777')
        ->and($data['customerReference'])->toBe('CUST-REF-888')
        ->and($data['paymentStatus'])->toBe('pending')
        ->and($data['fulfilmentStatus'])->toBe('unfulfilled')
        ->and($data['total'])->toBe(6600)
        ->and($data['subTotal'])->toBe(5000);
});

it('can record a payment transaction on an order and query transactions', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($orderId: ID!) {
            recordOrderTransaction(
                orderId: $orderId
                amount: 6600
                type: "capture"
                driver: "stripe"
                reference: "ch_stripe_test_123"
                status: "success"
                cardType: "visa"
                lastFour: "4242"
                notes: "Authorized via Stripe test card"
                success: true
            ) {
                id
                success
                type
                driver
                amount
                reference
                status
                cardType
                lastFour
                notes
            }
        }
    ', [
        'orderId' => $this->order->id,
    ]);

    $response->assertSuccessful();
    $trx = $response->json('data.recordOrderTransaction');

    expect($trx['success'])->toBeTrue()
        ->and($trx['type'])->toBe('capture')
        ->and($trx['driver'])->toBe('stripe')
        ->and($trx['amount'])->toBe(6600)
        ->and($trx['reference'])->toBe('ch_stripe_test_123')
        ->and($trx['status'])->toBe('success')
        ->and($trx['cardType'])->toBe('visa')
        ->and($trx['lastFour'])->toBe('4242')
        ->and($trx['notes'])->toBe('Authorized via Stripe test card');

    // Query order and verify transactions relation
    $orderResponse = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            order(id: $id) {
                id
                transactions {
                    id
                    amount
                    reference
                    driver
                }
            }
        }
    ', [
        'id' => $this->order->id,
    ]);

    $orderResponse->assertSuccessful();
    $transactions = $orderResponse->json('data.order.transactions');

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]['amount'])->toBe(6600)
        ->and($transactions[0]['reference'])->toBe('ch_stripe_test_123')
        ->and($transactions[0]['driver'])->toBe('stripe');
});

it('can query fulfilments relation on order', function () {
    $location = Location::firstOrCreate(['handle' => 'main-warehouse'], [
        'name' => 'Main Warehouse',
        'default' => true,
    ]);

    $fulfilment = $this->order->fulfilments()->create([
        'location_id' => $location->id,
        'state' => 'shipped',
        'notes' => 'Dispatched via DHL Express',
    ]);

    $fulfilment->trackings()->create([
        'tracking_number' => 'TRACK-999-DHL',
        'tracking_url' => 'https://dhl.com/track/TRACK-999-DHL',
        'carrier' => 'dhl',
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            order(id: $id) {
                id
                fulfilments {
                    id
                    status
                    state
                    trackingReference
                    trackingUrl
                    notes
                }
            }
        }
    ', [
        'id' => $this->order->id,
    ]);

    $response->assertSuccessful();
    $fulfilments = $response->json('data.order.fulfilments');

    expect($fulfilments)->toHaveCount(1)
        ->and($fulfilments[0]['status'])->toBe('shipped')
        ->and($fulfilments[0]['state'])->toBe('shipped')
        ->and($fulfilments[0]['trackingReference'])->toBe('TRACK-999-DHL')
        ->and($fulfilments[0]['trackingUrl'])->toBe('https://dhl.com/track/TRACK-999-DHL')
        ->and($fulfilments[0]['notes'])->toBe('Dispatched via DHL Express');
});
