<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Web\Infrastructure\Debug\WorkerRequestCountSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class WorkerRequestCountTest extends WebTestCase
{
    public function testCountGrowsAcrossRequestsHandledByTheSameKernel(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->request('GET', '/');
        self::assertSame('1', $client->getResponse()->headers->get('X-Worker-Request-Count'));

        $client->request('GET', '/');
        self::assertSame('2', $client->getResponse()->headers->get('X-Worker-Request-Count'));
    }

    public function testSubscriberIsNotRegisteredInProd(): void
    {
        // The prod container has no test service locator, so read the public dispatcher from the kernel.
        $kernel = self::bootKernel(['environment' => 'prod', 'debug' => false]);

        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        $listenerClasses = array_map(
            static fn (mixed $listener): ?string => \is_array($listener) && \is_object($listener[0]) ? $listener[0]::class : null,
            $dispatcher->getListeners(KernelEvents::RESPONSE),
        );

        self::assertNotContains(WorkerRequestCountSubscriber::class, $listenerClasses);
    }
}
