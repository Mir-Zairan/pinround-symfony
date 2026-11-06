<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Guard against outdated patterns only; code style is PHP-CS-Fixer's job.
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/reference.php',
        __DIR__.'/tests/Fixtures', // deliberate violations, see tests/Fixtures/Deptrac
    ])
    ->withPhpSets()
    ->withComposerBased(symfony: true, doctrine: true, phpunit: true)
;
