<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The id of the current main web request, a new UUID for each one, that log records carry as request_id. Sub-requests
 * keep their main request's id. The kernel resets it between requests, so worker mode never reuses one (ADR-0008);
 * outside a web request (console, Messenger worker) there is none.
 */
final class RequestId implements ResetInterface
{
    // Before every other kernel.request listener, so the records they log carry the id.
    private const int START_PRIORITY = 4096;

    private ?string $value = null;

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::START_PRIORITY)]
    public function start(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->value = Uuid::v7()->toRfc4122();
        }
    }

    public function get(): ?string
    {
        return $this->value;
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
