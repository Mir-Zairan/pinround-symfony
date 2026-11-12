<?php

declare(strict_types=1);

namespace App\Tests\PHPUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Symfony\Component\Uid\UuidV7;

/**
 * UuidV7 keeps the last time it used in static state and never goes back from it on its own. A test that freezes the
 * clock in the future and reaches code that generates a UUIDv7 at the clock's time (the audit log, login attempts)
 * would leave that future time to every later Uuid::v7() in the process. After each test, one UUIDv7 generated at an
 * explicit real time moves the state back.
 */
final class RestoreUuidV7TimeExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                UuidV7::generate(new \DateTimeImmutable());
            }
        });
    }
}
