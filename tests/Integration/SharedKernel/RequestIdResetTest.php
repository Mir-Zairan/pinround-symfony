<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel;

use App\SharedKernel\Infrastructure\Http\RequestId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * In worker mode (ADR-0008) the kernel resets services between requests; a request id must not survive that.
 */
final class RequestIdResetTest extends KernelTestCase
{
    #[Test]
    public function theKernelResetBetweenRequestsClearsTheRequestId(): void
    {
        $container = self::getContainer();
        $requestId = $container->get(RequestId::class);
        $requestId->start(new RequestEvent(self::$kernel ?? self::fail('No kernel.'), new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertNotNull($requestId->get());

        $container->get('services_resetter')->reset();

        self::assertNull($requestId->get());
    }

    #[Test]
    public function theRequestIdStartsBeforeEveryOtherRequestListener(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $requestId = self::getContainer()->get(RequestId::class);

        $startPriority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$requestId, 'start']);
        $priorities = array_map(
            static fn (callable $listener): ?int => $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener),
            $dispatcher->getListeners(KernelEvents::REQUEST),
        );

        self::assertIsInt($startPriority);
        self::assertSame([$startPriority], array_values(array_filter($priorities, static fn (?int $priority): bool => $priority >= $startPriority)));
    }
}
