<?php

use Lunar\Core\Models\Price;
use Lunargraphql\GraphQL\Resolvers\PriceResolver;

it('formats prices correctly through PriceResolver', function () {
    $resolver = new PriceResolver;

    $price = new Price([
        'price' => 1999, // 19.99 EUR
        'list_price' => 2999, // 29.99 EUR
        'currency_id' => $this->defaultCurrency->id,
    ]);
    $price->setRelation('currency', $this->defaultCurrency);

    expect($resolver->getPriceDecimal($price, []))->toBe(19.99);
    expect($resolver->getListPriceDecimal($price, []))->toBe(29.99);

    $formatted = $resolver->getPriceFormatted($price, []);
    expect($formatted)->toContain('19.99');

    $listFormatted = $resolver->getListPriceFormatted($price, []);
    expect($listFormatted)->toContain('29.99');

    expect($resolver->getPriceIncTax($price, []))->toBe(1999);
    expect($resolver->getPriceExTax($price, []))->toBe(1999);
});

it('handles null list price correctly in PriceResolver', function () {
    $resolver = new PriceResolver;

    $price = new Price([
        'price' => 5000,
        'list_price' => null,
        'currency_id' => $this->defaultCurrency->id,
    ]);
    $price->setRelation('currency', $this->defaultCurrency);

    expect($resolver->getListPriceDecimal($price, []))->toBeNull();
    expect($resolver->getListPriceFormatted($price, []))->toBeNull();
});
