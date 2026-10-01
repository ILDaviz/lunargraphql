<?php

use Lunar\Core\Models\Brand;
use Lunar\Core\Models\Collection;
use Lunar\Core\Models\CollectionGroup;
use Lunar\Core\Models\Product;
use Lunar\Core\Models\ProductType;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\Models\Tag;

beforeEach(function () {
    $this->productType = ProductType::firstOrCreate(['handle' => 'default-type'], [
        'name' => 'Default Type',
        'status' => 'active',
    ]);

    $this->brand = Brand::create([
        'name' => 'Nike Global',
        'handle' => 'nike-global',
        'status' => 'active',
        'description' => ['en' => 'Just do it.'],
    ]);

    $this->collectionGroup = CollectionGroup::firstOrCreate(['handle' => 'main-group'], [
        'name' => 'Main Group',
    ]);

    $this->collection = Collection::create([
        'name' => ['en' => 'Footwear'],
        'description' => ['en' => 'Footwear collection description.'],
        'handle' => 'footwear',
        'collection_group_id' => $this->collectionGroup->id,
    ]);

    $this->product = Product::create([
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
        'status' => 'published',
        'name' => [
            'en' => 'Air Pegasus Runner',
        ],
        'description' => [
            'en' => 'High performance running shoes.',
        ],
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'tax_class_id' => $this->defaultTaxClass->id,
        'sku' => 'AIR-PEGASUS-01',
        'unit_quantity' => 1,
        'selling_policy' => 'always',
        'enabled' => true,
        'stock_on_hand' => 20,
        'stock_available' => 20,
    ]);
});

it('can query product by URL slug and fetch SEO details and tags', function () {
    $this->product->urls()->delete();

    $tag = Tag::create(['value' => 'RUNNING']);
    $this->product->tags()->sync([$tag->id]);

    $this->product->urls()->create([
        'slug' => 'air-pegasus-runner',
        'default' => true,
        'language_id' => $this->defaultLanguage->id,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            productBySlug(slug: "air-pegasus-runner") {
                id
                name
                status
                defaultUrl {
                    slug
                    default
                }
                urls {
                    slug
                    default
                    language {
                        code
                    }
                }
                tags {
                    value
                }
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.productBySlug');

    expect($data['name'])->toBe('Air Pegasus Runner')
        ->and($data['status'])->toBe('published')
        ->and($data['defaultUrl']['slug'])->toBe('air-pegasus-runner')
        ->and($data['defaultUrl']['default'])->toBeTrue()
        ->and($data['urls'])->toHaveCount(1)
        ->and($data['urls'][0]['language']['code'])->toBe('en')
        ->and($data['tags'])->toHaveCount(1)
        ->and($data['tags'][0]['value'])->toBe('RUNNING');
});

it('can query collection by URL slug', function () {
    $this->collection->urls()->create([
        'slug' => 'footwear-collection',
        'default' => true,
        'language_id' => $this->defaultLanguage->id,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            collectionBySlug(slug: "footwear-collection") {
                id
                name
                handle
                description
                defaultUrl {
                    slug
                    default
                }
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.collectionBySlug');

    expect($data['name'])->toBe('Footwear')
        ->and($data['handle'])->toBe('footwear')
        ->and($data['description'])->toBe('Footwear collection description.')
        ->and($data['defaultUrl']['slug'])->toBe('footwear-collection');
});

it('can query url element directly by slug', function () {
    $this->product->urls()->create([
        'slug' => 'direct-runner-slug',
        'default' => true,
        'language_id' => $this->defaultLanguage->id,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            url(slug: "direct-runner-slug") {
                id
                slug
                default
                language {
                    code
                }
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.url');

    expect($data['slug'])->toBe('direct-runner-slug')
        ->and($data['default'])->toBeTrue()
        ->and($data['language']['code'])->toBe('en');
});

it('can query brand by handle', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query {
            brandByHandle(handle: "nike-global") {
                id
                name
                handle
                status
                description
            }
        }
    ');

    $response->assertSuccessful();
    $data = $response->json('data.brandByHandle');

    expect($data['name'])->toBe('Nike Global')
        ->and($data['handle'])->toBe('nike-global')
        ->and($data['status'])->toBe('active')
        ->and($data['description'])->toBe('Just do it.');
});

it('can navigate nested set collection hierarchy', function () {
    $root = Collection::create([
        'name' => ['en' => 'Root Category'],
        'collection_group_id' => $this->collectionGroup->id,
    ]);

    $child = Collection::create([
        'name' => ['en' => 'Men'],
        'collection_group_id' => $this->collectionGroup->id,
    ], $root);

    $grandchild = Collection::create([
        'name' => ['en' => 'Sneakers'],
        'collection_group_id' => $this->collectionGroup->id,
    ], $child);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query($rootId: ID!, $childId: ID!, $grandchildId: ID!) {
            root: collection(id: $rootId) {
                name
                children {
                    name
                }
                descendants {
                    name
                }
            }
            child: collection(id: $childId) {
                name
                parent {
                    name
                }
            }
            grandchild: collection(id: $grandchildId) {
                name
                ancestors {
                    name
                }
                breadcrumbs {
                    name
                }
            }
        }
    ', [
        'rootId' => $root->id,
        'childId' => $child->id,
        'grandchildId' => $grandchild->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data');

    expect($data['root']['children'])->toHaveCount(1)
        ->and($data['root']['children'][0]['name'])->toBe('Men')
        ->and($data['root']['descendants'])->toHaveCount(2)
        ->and($data['child']['parent']['name'])->toBe('Root Category')
        ->and($data['grandchild']['ancestors'])->toHaveCount(2)
        ->and($data['grandchild']['breadcrumbs'])->toHaveCount(3);
});

it('can query product associations including cross-sells and up-sells', function () {
    $crossSellProduct = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Running Socks'],
    ]);

    $upSellProduct = Product::create([
        'product_type_id' => $this->productType->id,
        'status' => 'published',
        'name' => ['en' => 'Pro Marathon Shoe'],
    ]);

    $this->product->associations()->create([
        'product_target_id' => $crossSellProduct->id,
        'type' => 'cross-sell',
        'sort' => 1,
    ]);

    $this->product->associations()->create([
        'product_target_id' => $upSellProduct->id,
        'type' => 'up-sell',
        'sort' => 2,
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
        query($id: ID!) {
            product(id: $id) {
                associations {
                    type
                    target {
                        name
                    }
                }
            }
        }
    ', [
        'id' => $this->product->id,
    ]);

    $response->assertSuccessful();
    $associations = $response->json('data.product.associations');

    expect($associations)->toHaveCount(2)
        ->and($associations[0]['type'])->toBe('cross-sell')
        ->and($associations[0]['target']['name'])->toBe('Running Socks')
        ->and($associations[1]['type'])->toBe('up-sell')
        ->and($associations[1]['target']['name'])->toBe('Pro Marathon Shoe');
});

it('can query media and images for product and variant', function () {
    $response = $this->graphQL(/** @lang GraphQL */ '
        query($id: ID!, $variantId: ID!) {
            product(id: $id) {
                thumbnail {
                    id
                    fileName
                }
                images {
                    id
                }
            }
            productVariant(id: $variantId) {
                thumbnail {
                    id
                }
                images {
                    id
                }
            }
        }
    ', [
        'id' => $this->product->id,
        'variantId' => $this->variant->id,
    ]);

    $response->assertSuccessful();
    $data = $response->json('data');

    // Without attached media, thumbnail is null and images is empty list
    expect($data['product']['thumbnail'])->toBeNull()
        ->and($data['product']['images'])->toBeEmpty()
        ->and($data['productVariant']['thumbnail'])->toBeNull()
        ->and($data['productVariant']['images'])->toBeEmpty();

    // Now attach a media item
    $media = $this->product->media()->create([
        'collection_name' => config('lunar.media.collection', 'images'),
        'name' => 'shoe-hero',
        'file_name' => 'shoe-hero.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 12345,
        'manipulations' => [],
        'custom_properties' => ['primary' => true],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    $responseWithMedia = $this->graphQL(/** @lang GraphQL */ '
        query($id: ID!) {
            product(id: $id) {
                thumbnail {
                    fileName
                    mimeType
                    size
                    url
                }
                images {
                    fileName
                    mimeType
                }
            }
        }
    ', [
        'id' => $this->product->id,
    ]);

    $responseWithMedia->assertSuccessful();
    $productData = $responseWithMedia->json('data.product');

    expect($productData['thumbnail']['fileName'])->toBe('shoe-hero.jpg')
        ->and($productData['thumbnail']['mimeType'])->toBe('image/jpeg')
        ->and($productData['thumbnail']['size'])->toBe(12345)
        ->and($productData['images'])->toHaveCount(1)
        ->and($productData['images'][0]['fileName'])->toBe('shoe-hero.jpg');
});
