<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Web\Infrastructure\Debug\WorkerRequestCountSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
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

    public function testSubscriberIsRegisteredInTheTestEnvironment(): void
    {
        static::createClient();

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        \assert($dispatcher instanceof EventDispatcherInterface);

        $listenerClasses = array_map(
            static fn (mixed $listener): ?string => \is_array($listener) && \is_object($listener[0]) ? $listener[0]::class : null,
            $dispatcher->getListeners(KernelEvents::RESPONSE),
        );

        self::assertContains(WorkerRequestCountSubscriber::class, $listenerClasses);
    }

    public function testSubscriberIsOnlyEnabledForDevAndTest(): void
    {
        $attributes = (new \ReflectionClass(WorkerRequestCountSubscriber::class))->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame(['dev', 'test'], $environments);
    }
}
