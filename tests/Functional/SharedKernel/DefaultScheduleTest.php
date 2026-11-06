<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DefaultScheduleTest extends KernelTestCase
{
    public function testSchedulerDefaultIsRegisteredAsAMessengerReceiver(): void
    {
        self::bootKernel();

        $receivers = self::getContainer()->get('messenger.receiver_locator');

        self::assertArrayHasKey('scheduler_default', $receivers->getProvidedServices());
    }
}
