<?php

declare(strict_types=1);

namespace App\Web\Infrastructure\Debug;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proves FrankenPHP worker mode in dev: the count grows because the kernel survives between requests.
 * With several workers the count is per worker.
 */
#[When('dev')]
#[When('test')]
final class WorkerRequestCountSubscriber implements EventSubscriberInterface
{
    // Deliberately not a ResetInterface: the count must span requests, it holds no request data.
    private int $requestCount = 0;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'addRequestCountHeader'];
    }

    public function addRequestCountHeader(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        ++$this->requestCount;
        $event->getResponse()->headers->set('X-Worker-Request-Count', (string) $this->requestCount);
    }
}
