<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DefaultScheduleTest extends KernelTestCase
{
    public function testSchedulerDefaultIsRegisteredAsAMessengerReceiver(): void
    {
        self::bootKernel();

        $receivers = self::getContainer()->get('messenger.receiver_locator');
        \assert($receivers instanceof ServiceLocator);

        self::assertTrue($receivers->has('scheduler_default'));
    }
}
