<?php

declare(strict_types=1);

$dirs = array_filter(
    ['src', 'tests', 'examples', 'tools'],
    static fn (string $dir): bool => is_dir(__DIR__ . '/' . $dir)
);

$finder = PhpCsFixer\Finder::create()
    ->in($dirs)
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache')
;
