<?php

use Lunar\Core\Models\Channel;
use Lunar\Core\Models\CollectionGroup;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\CustomerGroup;
use Lunar\Core\Models\Discount;
use Lunar\Core\Models\Language;
use Lunar\Core\Models\Price;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductOption;
use Lunar\Core\Models\ProductOptionValue;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\Models\Region;
use Lunar\Core\Models\Tag;

it('can query product with options and direct price', function () {
    $product = Product::factory()->create([
        'status' => 'published',
    ]);

    $currency = Currency::getDefault();
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_quantity' => 1,
    ]);

    Price::factory()->create([
        'priceable_type' => $variant->getMorphClass(),
        'priceable_id' => $variant->id,
        'currency_id' => $currency->id,
        'price' => 4500,
        'min_quantity' => 1,
    ]);

    $option = ProductOption::create([
        'name' => ['en' => 'Size'],
        'handle' => 'size',
        'shared' => true,
    ]);

    $optionValue = ProductOptionValue::create([
        'product_option_id' => $option->id,
        'name' => ['en' => 'Large'],
        'position' => 1,
    ]);

    $product->productOptions()->attach($option, ['position' => 1]);
    $variant->values()->attach($optionValue);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetProduct($id: ID!) {
            product(id: $id) {
                id
                hasVariants
                price {
                    price
                    priceFormatted
                }
                productOptions {
                    id
                    handle
                    name
                    values {
                        id
                        name
                    }
                }
            }
        }
    ', [
        'id' => $product->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.product');

    expect($data['price']['price'])->toBe(4500)
        ->and($data['productOptions'])->toHaveCount(1)
        ->and($data['productOptions'][0]['handle'])->toBe('size')
        ->and($data['productOptions'][0]['name'])->toBe('Size')
        ->and($data['productOptions'][0]['values'][0]['name'])->toBe('Large');
});

it('can query product options list and single product option by handle', function () {
    $option = ProductOption::create([
        'name' => ['en' => 'Color'],
        'handle' => 'color-option',
        'shared' => true,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetOptions($handle: String!) {
            productOptions {
                id
                handle
            }
            productOption(handle: $handle) {
                id
                handle
                name
            }
        }
    ', [
        'handle' => 'color-option',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['productOptions'])->not->toBeEmpty()
        ->and($data['productOption']['handle'])->toBe('color-option')
        ->and($data['productOption']['name'])->toBe('Color');
});

it('can query product variant with pricing, inventory and fulfillment details', function () {
    $product = Product::factory()->create(['status' => 'published']);
    $currency = Currency::getDefault();

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'shippable' => true,
        'stock_available' => 15,
        'stock_on_hand' => 20,
        'backorder' => 5,
        'enabled' => true,
    ]);

    // Base price
    Price::factory()->create([
        'priceable_type' => $variant->getMorphClass(),
        'priceable_id' => $variant->id,
        'currency_id' => $currency->id,
        'price' => 3000,
        'min_quantity' => 1,
    ]);

    // Price break (min_quantity 10+)
    Price::factory()->create([
        'priceable_type' => $variant->getMorphClass(),
        'priceable_id' => $variant->id,
        'currency_id' => $currency->id,
        'price' => 2500,
        'min_quantity' => 10,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetVariant($id: ID!) {
            productVariant(id: $id) {
                id
                isPurchasable
                requiresFulfilment
                totalInventory
                stockAvailable
                price {
                    price
                    priceFormatted
                }
                basePrices {
                    price
                }
                priceBreaks {
                    price
                }
            }
        }
    ', [
        'id' => $variant->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.productVariant');

    expect($data['isPurchasable'])->toBeTrue()
        ->and($data['requiresFulfilment'])->toBeTrue()
        ->and($data['stockAvailable'])->toBe(15)
        ->and($data['price']['price'])->toBe(3000)
        ->and($data['basePrices'])->toHaveCount(1)
        ->and($data['priceBreaks'])->toHaveCount(1)
        ->and($data['priceBreaks'][0]['price'])->toBe(2500);
});

it('can query single currency, channel, language, and region and their defaults', function () {
    $defaultCurrency = Currency::getDefault();
    $defaultChannel = Channel::getDefault();
    $defaultLanguage = Language::getDefault();
    $defaultRegion = Region::getDefault();

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetDefaults($currencyCode: String!, $channelHandle: String!, $languageCode: String!, $regionHandle: String!) {
            defaultCurrency {
                code
                default
            }
            currency(code: $currencyCode) {
                code
            }
            defaultChannel {
                handle
                default
            }
            channel(handle: $channelHandle) {
                handle
            }
            defaultLanguage {
                code
                default
            }
            language(code: $languageCode) {
                code
            }
            defaultRegion {
                handle
                default
            }
            region(handle: $regionHandle) {
                handle
            }
        }
    ', [
        'currencyCode' => $defaultCurrency->code,
        'channelHandle' => $defaultChannel->handle,
        'languageCode' => $defaultLanguage->code,
        'regionHandle' => $defaultRegion->handle,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['defaultCurrency'])->not->toBeNull()
        ->and($data['defaultCurrency']['code'])->toBe($defaultCurrency->code)
        ->and($data['currency']['code'])->toBe($defaultCurrency->code)
        ->and($data['defaultChannel'])->not->toBeNull()
        ->and($data['defaultChannel']['handle'])->toBe($defaultChannel->handle)
        ->and($data['channel']['handle'])->toBe($defaultChannel->handle)
        ->and($data['defaultLanguage'])->not->toBeNull()
        ->and($data['defaultLanguage']['code'])->toBe($defaultLanguage->code)
        ->and($data['language']['code'])->toBe($defaultLanguage->code)
        ->and($data['defaultRegion'])->not->toBeNull()
        ->and($data['defaultRegion']['handle'])->toBe($defaultRegion->handle)
        ->and($data['region']['handle'])->toBe($defaultRegion->handle);
});

it('can query single country, tag, productType, and groups by handle or value', function () {
    Tag::create(['value' => 'NEW_ARRIVAL']);
    ProductType::create(['name' => 'Clothing', 'handle' => 'clothing']);
    CollectionGroup::create(['name' => 'Main Navigation', 'handle' => 'main-nav']);
    CustomerGroup::create(['name' => 'Wholesale', 'handle' => 'wholesale', 'default' => false]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            country(iso2: "IT") {
                iso2
                name
            }
            tag(value: "NEW_ARRIVAL") {
                value
            }
            productType(handle: "clothing") {
                name
                handle
            }
            collectionGroup(handle: "main-nav") {
                name
                handle
            }
            customerGroup(handle: "wholesale") {
                name
                handle
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['country']['iso2'])->toBe('IT')
        ->and($data['country']['name'])->toBe('Italy')
        ->and($data['tag']['value'])->toBe('NEW_ARRIVAL')
        ->and($data['productType']['handle'])->toBe('clothing')
        ->and($data['collectionGroup']['handle'])->toBe('main-nav')
        ->and($data['customerGroup']['handle'])->toBe('wholesale');
});

it('can query tax classes and tax zones', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            taxClasses {
                id
                name
                default
            }
            taxZones {
                id
                name
                active
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['taxClasses'])->not->toBeEmpty()
        ->and($data['taxZones'])->not->toBeEmpty();
});

it('can query discounts and single discount by handle or coupon', function () {
    $discount = Discount::create([
        'name' => 'Summer Sale 20%',
        'handle' => 'summer-sale-20',
        'coupon' => 'SUMMER20',
        'type' => 'Lunar\Core\DiscountTypes\AmountOff',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'uses' => 0,
        'max_uses' => 100,
        'priority' => 1,
        'stop' => false,
        'data' => [],
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query GetDiscounts($handle: String!, $coupon: String!) {
            discounts {
                id
                handle
                name
            }
            byHandle: discount(handle: $handle) {
                id
                handle
                coupon
                status
            }
            byCoupon: discount(coupon: $coupon) {
                id
                handle
                coupon
            }
        }
    ', [
        'handle' => 'summer-sale-20',
        'coupon' => 'SUMMER20',
    ]);

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['discounts'])->not->toBeEmpty()
        ->and($data['byHandle']['handle'])->toBe('summer-sale-20')
        ->and($data['byHandle']['status'])->toBe('active')
        ->and($data['byCoupon']['handle'])->toBe('summer-sale-20');
});
