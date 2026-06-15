<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/migrations',
        __DIR__.'/src',
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
