<?php

use Lunar\Core\Models\Channel;
use Lunargraphql\GraphQL\GlobalId\SmartGlobalId;
use Lunargraphql\Traits\WithGlobalID;

$dummyClass = new class {
    use WithGlobalID;
};

it('can encode and decode relay global IDs', function () use ($dummyClass) {
    $encoded = $dummyClass->encodeGlobalId('Product', 42);
    expect($encoded)->toBe(base64_encode('Product:42'));

    $decoded = $dummyClass->decodeGlobalId($encoded);
    expect($decoded)->toBe(['Product', '42']);
});

it('can decode colon-separated IDs and raw IDs gracefully', function () use ($dummyClass) {
    $colonDecoded = $dummyClass->decodeGlobalId('Product:100');
    expect($colonDecoded)->toBe(['Product', '100']);

    $rawDecoded = $dummyClass->decodeGlobalId('999');
    expect($rawDecoded)->toBe(['', '999']);
});

it('can extract raw IDs from various argument formats', function () use ($dummyClass) {
    // Array format from Lighthouse
    expect($dummyClass->extractIdFromArgs(['id' => ['Product', '15']], 'id'))->toBe('15');
    expect($dummyClass->extractIdFromArgs(['id' => ['type' => 'Product', 'id' => '25']], 'id'))->toBe('25');

    // Numeric format
    expect($dummyClass->extractIdFromArgs(['id' => 50], 'id'))->toBe(50);

    // Base64 global ID format
    $base64 = base64_encode('Channel:7');
    expect($dummyClass->extractIdFromArgs(['channelID' => $base64], 'channelID'))->toBe('7');

    // Null argument
    expect($dummyClass->extractIdFromArgs([], 'missing'))->toBeNull();
});

it('can retrieve model by global ID or public_id', function () use ($dummyClass) {
    $channel = Channel::first();

    // Find via numeric ID
    $found = $dummyClass->getModelFromGlobalId(['id' => $channel->id], 'id', Channel::class);
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($channel->id);

    // Find via Relay Base64 Global ID
    $globalId = base64_encode("Channel:{$channel->id}");
    $foundViaGlobal = $dummyClass->getModelFromGlobalId(['id' => $globalId], 'id', Channel::class);
    expect($foundViaGlobal)->not->toBeNull()
        ->and($foundViaGlobal->id)->toBe($channel->id);

    // Missing model returns null
    $missing = $dummyClass->getModelFromGlobalId(['id' => 99999], 'id', Channel::class);
    expect($missing)->toBeNull();
});

it('smart global id handles relay, colon, and raw IDs correctly', function () {
    $smart = new SmartGlobalId();

    $encoded = $smart->encode('Order', 123);
    expect($encoded)->toBe(base64_encode('Order:123'));

    // Base64 decode
    expect($smart->decode($encoded))->toBe(['Order', '123']);
    expect($smart->decodeID($encoded))->toBe('123');
    expect($smart->decodeType($encoded))->toBe('Order');

    // Plain colon decode
    expect($smart->decode('Order:456'))->toBe(['Order', '456']);
    expect($smart->decodeID('Order:456'))->toBe('456');
    expect($smart->decodeType('Order:456'))->toBe('Order');

    // Raw integer / ULID decode
    expect($smart->decode('789'))->toBe(['', '789']);
    expect($smart->decodeID('789'))->toBe('789');
    expect($smart->decodeType('789'))->toBe('');
});
