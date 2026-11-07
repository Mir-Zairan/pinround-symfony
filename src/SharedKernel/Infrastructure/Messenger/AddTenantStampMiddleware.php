<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\TenantContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps a message dispatched while an organization is set with that organization, so it runs under it wherever it
 * is handled: at once, after the outbox relay, in a worker or after a retry (ADR-0005). A message that already has a
 * TenantStamp keeps it, a received one stays as it was sent, and with no organization set none is added.
 * It must come before dispatch_after_current_bus, which releases a held-back message after the organization of the
 * handler that dispatched it is cleared.
 */
final readonly class AddTenantStampMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $organizationId = $this->tenantContext->get();
        if (null !== $organizationId && null === $envelope->last(TenantStamp::class) && null === $envelope->last(ReceivedStamp::class)) {
            $envelope = $envelope->with(new TenantStamp($organizationId));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
