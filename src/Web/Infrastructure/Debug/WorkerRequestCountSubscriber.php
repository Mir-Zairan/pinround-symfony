<?php

declare(strict_types=1);

namespace App\Web\Infrastructure\Debug;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proves FrankenPHP worker mode in dev: the count grows because the kernel survives between requests.
 * With several workers the count is per worker.
 */
#[When('dev')]
#[When('test')]
final class WorkerRequestCountSubscriber
{
    // Deliberately not a ResetInterface: the count must span requests, it holds no request data.
    private int $requestCount = 0;

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function addRequestCountHeader(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        ++$this->requestCount;
        $event->getResponse()->headers->set('X-Worker-Request-Count', (string) $this->requestCount);
    }
}
