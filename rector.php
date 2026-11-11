<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

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
        // The locking repositories refresh these entities, and Doctrine then sets their dates again: they stay writable.
        // A ticket moves through workflow steps and its title, assignee and updatedAt change after construction, so
        // those properties stay writable even though today's code only sets them in the constructor.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__.'/src/Organization/Domain/Membership.php',
            __DIR__.'/src/Organization/Domain/Team.php',
            __DIR__.'/src/Ticketing/Domain/Ticket.php',
        ],
        ReadOnlyPropertyRector::class => [
            __DIR__.'/src/Organization/Domain/Membership.php',
            __DIR__.'/src/Organization/Domain/Team.php',
            __DIR__.'/src/Ticketing/Domain/Ticket.php',
        ],
    ])
    ->withPhpSets()
    ->withComposerBased(symfony: true, doctrine: true, phpunit: true)
;
