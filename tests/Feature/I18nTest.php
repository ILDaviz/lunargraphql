<?php

use Illuminate\Support\Facades\Lang;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Collection;
use Lunar\Core\Models\CollectionGroup;
use Lunar\Core\Models\Language;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductOption;
use Lunar\Core\Models\ProductOptionValue;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunargraphql\Exceptions\ApplicationException;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'default-type'], [
        'name' => 'Default Type',
        'status' => 'active',
    ]);
});

it('loads package English translations for errors and messages', function () {
    expect(Lang::has('lunargraphql::errors.product_variant_not_found'))->toBeTrue();
    expect(Lang::has('lunargraphql::errors.insufficient_stock'))->toBeTrue();
    expect(Lang::has('lunargraphql::errors.coupon_required'))->toBeTrue();
    expect(Lang::has('lunargraphql::errors.weak_password'))->toBeTrue();
    expect(Lang::has('lunargraphql::messages.currency_switched'))->toBeTrue();

    // Verify translated strings in English
    expect(__('lunargraphql::errors.insufficient_stock'))->toBe('Insufficient stock available for this product variant.');
    expect(__('lunargraphql::errors.product_variant_not_found'))->toBe('Product variant not found');
    expect(__('lunargraphql::errors.cart_not_found'))->toBe('Cart not found');
    expect(__('lunargraphql::errors.invalid_address', ['type' => 'shipping']))->toBe('A valid shipping address is required');
    expect(__('lunargraphql::messages.currency_switched'))->toBe('Currency successfully updated.');

    // Verify fallback when key is missing
    $fallback = ApplicationException::trans('non_existent_key', 'Default Fallback String');
    expect($fallback)->toBe('Default Fallback String');
});

it('returns localized English error messages in GraphQL mutations', function () {
    $product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Stock Test Item'],
    ]);
    $product->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'VAR-STOCK-I18N',
        'unit_quantity' => 1,
        'selling_policy' => 'in_stock',
        'enabled' => true,
        'stock_on_hand' => 2,
        'stock_available' => 2,
    ]);
    $variant->prices()->create([
        'currency_id' => $this->defaultCurrency->id,
        'price' => 2500,
        'min_quantity' => 1,
    ]);

    $cart = Cart::create([
        'currency_id' => $this->defaultCurrency->id,
        'channel_id' => $this->defaultChannel->id,
    ]);

    // 1. Exceed stock -> returns English translation
    $resStock = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!, $variantId: ID!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: $variantId, quantity: 10) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
        'variantId' => $variant->id,
    ]);

    expect($resStock->json('errors.0.message'))
        ->toBe(__('lunargraphql::errors.insufficient_stock'));

    // 2. Non-existent product variant -> returns English translation
    $resNotFound = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            addProductVariantToCart(cartId: $cartId, productVariantID: 999999, quantity: 1) {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
    ]);

    expect($resNotFound->json('errors.0.message'))
        ->toBe(__('lunargraphql::errors.product_variant_not_found'));

    // 3. Empty coupon -> returns English translation
    $resCoupon = $this->graphQL(/** @lang GraphQL */ '
        mutation ($cartId: ID!) {
            applyCouponToCart(cartId: $cartId, coupon: "   ") {
                id
            }
        }
    ', [
        'cartId' => $cart->id,
    ]);

    expect($resCoupon->json('errors.0.message'))
        ->toBe(__('lunargraphql::errors.coupon_required'));
});

it('queries Lunar languages and default language in English', function () {
    $resAll = $this->graphQL(/** @lang GraphQL */ '
        query {
            languages {
                id
                code
                name
                default
            }
        }
    ');
    $resAll->assertSuccessful();

    $languages = $resAll->json('data.languages');
    expect($languages)->toBeArray();
    expect(collect($languages)->firstWhere('code', 'en'))->not->toBeNull();
    expect(collect($languages)->firstWhere('code', 'en')['default'])->toBeTrue();

    // Query defaultLanguage
    $resDefault = $this->graphQL(/** @lang GraphQL */ '
        query {
            defaultLanguage {
                code
                name
                default
            }
        }
    ');
    $resDefault->assertSuccessful();
    expect($resDefault->json('data.defaultLanguage.code'))->toBe('en');
    expect($resDefault->json('data.defaultLanguage.name'))->toBe('English');
    expect($resDefault->json('data.defaultLanguage.default'))->toBeTrue();

    // Query single language by code
    $resSingle = $this->graphQL(/** @lang GraphQL */ '
        query {
            language(code: "en") {
                code
                name
            }
        }
    ');
    $resSingle->assertSuccessful();
    expect($resSingle->json('data.language.code'))->toBe('en');
    expect($resSingle->json('data.language.name'))->toBe('English');
});

it('resolves product translatable attributes in English', function () {
    $product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => [
            'en' => 'English Sneaker',
            'fr' => 'Baskets Françaises',
        ],
        'description' => [
            'en' => 'Comfortable running sneaker designed for speed.',
            'fr' => 'Chaussure de course confortable conçue pour la vitesse.',
        ],
        'short_description' => [
            'en' => 'Speed sneaker',
            'fr' => 'Baskets de vitesse',
        ],
    ]);
    $product->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            product(id: $id) {
                id
                nameDefault: name
                nameEn: name(lang: "en")
                descDefault: description
                descEn: description(lang: "en")
                shortDescDefault: shortDescription
                shortDescEn: shortDescription(lang: "en")
                nameTranslations {
                    lang
                    value
                }
            }
        }
    ', [
        'id' => $product->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.product');

    expect($data['nameDefault'])->toBe('English Sneaker');
    expect($data['nameEn'])->toBe('English Sneaker');
    expect($data['descDefault'])->toBe('Comfortable running sneaker designed for speed.');
    expect($data['descEn'])->toBe('Comfortable running sneaker designed for speed.');
    expect($data['shortDescDefault'])->toBe('Speed sneaker');
    expect($data['shortDescEn'])->toBe('Speed sneaker');

    $translations = $data['nameTranslations'];
    expect($translations)->toBeArray();
    expect(collect($translations)->firstWhere('lang', 'en')['value'])->toBe('English Sneaker');
});

it('resolves collection translatable attributes in English', function () {
    $group = CollectionGroup::firstOrCreate(['handle' => 'fashion'], ['name' => 'Fashion']);

    $collection = Collection::create([
        'collection_group_id' => $group->id,
        'name' => [
            'en' => 'Summer Collection',
            'es' => 'Colección de Verano',
        ],
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($id: ID!) {
            collection(id: $id) {
                id
                nameDefault: name
                nameEn: name(lang: "en")
            }
        }
    ', [
        'id' => $collection->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data.collection');

    expect($data['nameDefault'])->toBe('Summer Collection');
    expect($data['nameEn'])->toBe('Summer Collection');
});

it('resolves product option and option value names in English', function () {
    $product = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'T-Shirt'],
    ]);
    $product->channels()->sync([$this->defaultChannel->id => ['enabled' => true, 'starts_at' => now()]]);

    $option = ProductOption::create([
        'name' => ['en' => 'Size'],
        'label' => ['en' => 'Choose Size'],
        'handle' => 'size',
    ]);

    $optionValue = ProductOptionValue::create([
        'product_option_id' => $option->id,
        'name' => ['en' => 'Medium'],
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'TSHIRT-M',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
    ]);

    $variant->values()->attach($optionValue);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query ($variantId: ID!) {
            productVariant(id: $variantId) {
                id
                sku
                values {
                    id
                    nameDefault: name
                    nameEn: name(lang: "en")
                    option {
                        nameDefault: name
                        nameEn: name(lang: "en")
                        labelDefault: label
                        labelEn: label(lang: "en")
                    }
                }
            }
        }
    ', [
        'variantId' => $variant->id,
    ]);

    $response->assertSuccessful();
    $val = $response->json('data.productVariant.values.0');

    expect($val['nameDefault'])->toBe('Medium');
    expect($val['nameEn'])->toBe('Medium');
    expect($val['option']['nameDefault'])->toBe('Size');
    expect($val['option']['nameEn'])->toBe('Size');
    expect($val['option']['labelDefault'])->toBe('Choose Size');
    expect($val['option']['labelEn'])->toBe('Choose Size');
});
