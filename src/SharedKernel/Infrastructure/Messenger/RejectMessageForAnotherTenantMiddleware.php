<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Refuses a message stamped for another organization than the one set. It runs before dispatch_after_current_bus,
 * which holds a message back until TenantStampMiddleware has already cleared the organization of the handler that
 * dispatched it; TenantStampMiddleware alone could not refuse it then. See data-model.md › Row-level security.
 */
final readonly class RejectMessageForAnotherTenantMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    /**
     * @throws MessageForAnotherTenantRefused
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stampedOrganizationId = $envelope->last(TenantStamp::class)?->organizationId();
        $currentOrganizationId = $this->tenantContext->get();
        if (null !== $stampedOrganizationId && null !== $currentOrganizationId && !$currentOrganizationId->equals($stampedOrganizationId)) {
            throw new MessageForAnotherTenantRefused();
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
