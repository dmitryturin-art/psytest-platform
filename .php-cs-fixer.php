<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/core')
    ->in(__DIR__ . '/controllers')
    ->in(__DIR__ . '/services')
    ->in(__DIR__ . '/modules')
    ->in(__DIR__ . '/tests')
    ->notPath('*/vendor/*');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder);
