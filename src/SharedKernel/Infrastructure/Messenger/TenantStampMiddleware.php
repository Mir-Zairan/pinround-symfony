<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Runs a message under the organization of its TenantStamp (ADR-0005). It sits directly before doctrine_transaction
 * on every bus (messenger.yaml), so TenantContext holds the organization when that transaction begins and
 * TenantMiddleware sets it for row-level security.
 *
 * - No stamp: passes the message on unchanged, under whatever organization is set (in a worker, none).
 * - Stamp, no organization set, no open transaction and no loaded entity: sets the stamp's organization, passes the
 *   message on, and afterwards (also when it throws) clears the entity manager and then TenantContext, so nothing
 *   loaded for that organization stays for the next message. A transaction the message leaves open is rolled back;
 *   on success that throws a \LogicException, because nothing in it was saved.
 * - Stamp, no organization set, but a transaction open or an entity loaded (a request without a tenant queuing a
 *   message for a new organization): passes on without setting it. A worker sets it when the message is received;
 *   handled here and now it runs without a tenant, so row-level security returns no rows and TenantFilter throws.
 * - Stamp with the same organization set (a dispatch inside a request or a handler): passes on and keeps it.
 * - Stamp with another organization set: MessageForAnotherTenantRefused before anything runs; never switches.
 */
final readonly class TenantStampMiddleware implements MiddlewareInterface
{
    /**
     * @param \Closure(): Connection             $connection    a closure, so building a bus does not build the connection
     * @param \Closure(): EntityManagerInterface $entityManager a closure, for the same reason
     */
    public function __construct(
        private TenantContext $tenantContext,
        private TenantSwitchGuard $switchGuard,
        #[AutowireServiceClosure('doctrine.dbal.default_connection')]
        private \Closure $connection,
        #[AutowireServiceClosure('doctrine.orm.default_entity_manager')]
        private \Closure $entityManager,
    ) {
    }

    /**
     * @throws MessageForAnotherTenantRefused when another organization is set
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(TenantStamp::class);
        if (!$stamp instanceof TenantStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $organizationId = $stamp->organizationId();
        $currentOrganizationId = $this->tenantContext->get();
        if (null !== $currentOrganizationId) {
            if (!$currentOrganizationId->equals($organizationId)) {
                throw new MessageForAnotherTenantRefused();
            }

            return $stack->next()->handle($envelope, $stack);
        }

        // TenantContext does not ask the guard for its first organization; this middleware must, because it moves
        // back to none afterwards and that move needs no transaction and no entities from before the message.
        // When it refuses, the message goes on without an organization: queued it gets one in the worker, handled
        // now it fails closed.
        try {
            $this->switchGuard->prepareSwitch();
        } catch (TenantSwitchRefused) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->tenantContext->set($organizationId);

        try {
            $handledEnvelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $failure) {
            $this->leaveOrganization();

            throw $failure;
        }

        if ($this->leaveOrganization()) {
            throw new \LogicException('The message left a database transaction open. It was rolled back, so nothing the message wrote in it was saved.');
        }

        return $handledEnvelope;
    }

    /**
     * @return bool whether the message had left a transaction open that had to be rolled back
     */
    private function leaveOrganization(): bool
    {
        $wasTransactionLeftOpen = $this->rollBackOpenTransaction();

        $entityManager = ($this->entityManager)();
        // An entity manager that is not built yet holds nothing, and clearing it must not build it.
        if (!new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager)) {
            $entityManager->clear();
        }

        $this->tenantContext->clear();

        return $wasTransactionLeftOpen;
    }

    /**
     * No transaction was open when the message started, so whatever is open now belongs to its organization.
     */
    private function rollBackOpenTransaction(): bool
    {
        $connection = ($this->connection)();
        if (!$connection->isTransactionActive()) {
            return false;
        }

        try {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable) {
            // Closing ends the transaction on the server and resets DBAL's nesting level; the failure itself
            // changes nothing for the caller, who gets the message's own outcome.
            $connection->close();
        }

        return true;
    }
}
