<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Infrastructure\Doctrine\LazyObjectState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Runs a message under the organization of its TenantStamp; the five cases are in data-model.md › Row-level security.
 * It must sit directly before doctrine_transaction, so the organization is set before that transaction begins.
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
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MessageForAnotherTenantRefused when another organization is set
     * @throws \LogicException                when the message succeeded but left a transaction open, now rolled back
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

        // Clearing the organization afterwards needs what the guard checks, and TenantContext skips it on a first set.
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
        if (LazyObjectState::isBuilt($entityManager)) {
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
        } catch (\Throwable $rollBackFailure) {
            // Class and code only: a database message can quote row values.
            $this->logger->warning('Rolling back the transaction a message left open failed; closing the connection instead.', [
                'exception_class' => $rollBackFailure::class,
                'sqlstate' => $rollBackFailure instanceof DriverException ? $rollBackFailure->getSQLState() : null,
                'code' => $rollBackFailure->getCode(),
            ]);
            // Closing ends the transaction on the server and resets DBAL's nesting level.
            $connection->close();
        }

        return true;
    }
}
