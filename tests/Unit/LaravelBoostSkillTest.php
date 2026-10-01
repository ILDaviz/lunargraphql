<?php

use Symfony\Component\Yaml\Yaml;

it('provides a valid Laravel Boost skill definition', function () {
    $skillPath = dirname(__DIR__, 2) . '/resources/boost/skills/lunar-graphql/SKILL.md';

    expect(file_exists($skillPath))->toBeTrue();

    $content = file_get_contents($skillPath);
    expect($content)->not()->toBeEmpty();

    // Check YAML frontmatter exists
    expect(preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches))->toBe(1);

    $frontmatter = $matches[1];
    
    // Check required frontmatter keys
    expect($frontmatter)
        ->toContain('name: lunar-graphql')
        ->toContain('license: MIT')
        ->toContain('description:');

    // Verify key architectural topics are documented in the skill body
    expect($content)
        ->toContain('catalog')
        ->toContain('addToCart')
        ->toContain('estimateShippingOptions')
        ->toContain('createOrderFromCart')
        ->toContain('PriceResolver')
        ->toContain('php artisan lighthouse:cache');
});
