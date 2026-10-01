<?php

use Illuminate\Database\Eloquent\Model;
use Lunar\Core\Enums\SellingPolicy;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunargraphql\GraphQL\Resolvers\CommonResolver;

it('handles product translatable fields and translations list in CommonResolver', function () {
    $resolver = new CommonResolver;

    $productType = ProductType::create(['name' => 'Test Type']);
    $product = Product::create([
        'product_type_id' => $productType->id,
        'status' => 'published',
        'name' => [
            'en' => 'English Name',
            'it' => 'Nome Italiano',
        ],
        'description' => [
            'en' => 'English Description',
            'it' => 'Descrizione Italiana',
        ],
        'short_description' => [
            'en' => 'Short English',
        ],
    ]);

    expect($resolver->productNameField($product, ['lang' => 'en']))->toBe('English Name');
    expect($resolver->productNameField($product, ['lang' => 'it']))->toBe('Nome Italiano');

    expect($resolver->productDescriptionField($product, ['lang' => 'en']))->toBe('English Description');
    expect($resolver->productShortDescriptionField($product, ['lang' => 'en']))->toBe('Short English');

    $translations = $resolver->nameTranslationsField($product, []);
    expect($translations)->toBeArray()
        ->and($translations)->toHaveCount(2)
        ->and($translations[0]['lang'])->toBe('en')
        ->and($translations[0]['value'])->toBe('English Name');
});

it('resolves sellingPolicy and status enums correctly in CommonResolver', function () {
    $resolver = new CommonResolver;

    $dummyModel = new class extends Model
    {
        public $selling_policy = SellingPolicy::Always;

        public $status = 'awaiting-payment';

        public $payment_status = 'pending';

        public $fulfilment_status = 'unfulfilled';

        public $meta = ['color' => 'blue', 'size' => 'M'];
    };

    expect($resolver->sellingPolicyField($dummyModel))->toBe('always');
    expect($resolver->orderStatusField($dummyModel))->toBe('awaiting-payment');
    expect($resolver->paymentStatusField($dummyModel))->toBe('pending');
    expect($resolver->fulfilmentStatusField($dummyModel))->toBe('unfulfilled');

    $meta = $resolver->metaField($dummyModel, []);
    expect($meta)->toContain('"color":"blue"');
});
