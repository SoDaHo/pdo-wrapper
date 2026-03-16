<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP81Migration' => true,

        // Strict
        'strict_param' => true,
        'declare_strict_types' => true,

        // Imports
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],

        // Syntax
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_empty_statement' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,

        // PHPDoc
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => true],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_align' => ['align' => 'left'],
    ])
    ->setFinder($finder);
