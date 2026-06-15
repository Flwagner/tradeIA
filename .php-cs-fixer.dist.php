<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/migrations',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->append([
        __DIR__.'/config/preload.php',
        __DIR__.'/public/index.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
