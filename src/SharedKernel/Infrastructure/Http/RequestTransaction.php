<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runs each main web request in one transaction on the default connection, so row-level security sees the
 * request's tenant (ADR-0005). Commits on the response, rolls back on an exception, and rolls back anything
 * it opened that is still open when the request finishes, terminates or the worker resets (ADR-0008).
 *
 * A route whose defaults set ROUTE_DEFAULT to false runs without one, so it still answers while the database is down
 * (the health routes); it must then read no tenant data.
 *
 * A streamed response body runs after the commit, outside any transaction, so it sees no tenant rows (fails closed).
 * Whatever fills TenantContext must do it on kernel.request at a priority above 6. Organization's
 * EnterChosenOrganization does it at 7, after the firewall (8) has authenticated the user, so the firewall must not be
 * lazy: a lazy firewall would authenticate after BEGIN.
 */
final class RequestTransaction implements ResetInterface
{
    /** The route default that, set to false, keeps the request out of a transaction. */
    public const string ROUTE_DEFAULT = '_request_transaction';

    // Below EnterChosenOrganization (7), which fills TenantContext; TenantMiddleware reads it at BEGIN.
    private const int BEGIN_PRIORITY = 6;
    // Before any other exception listener can turn the exception into a response and stop propagation.
    private const int ROLL_BACK_PRIORITY = 2048;
    // After the other response listeners, so a failure in one of them still rolls the request back.
    private const int COMMIT_PRIORITY = -1024;

    /** The DBAL nesting level of the transaction this class opened; 0 when it has none open. */
    private int $openNestingLevel = 0;

    public function __construct(private readonly Connection $connection, private readonly LoggerInterface $logger)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::BEGIN_PRIORITY)]
    public function beginTransaction(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->rollBackOpenTransaction();
        if (false === $event->getRequest()->attributes->get(self::ROUTE_DEFAULT, true)) {
            return;
        }

        $this->connection->beginTransaction();
        $this->openNestingLevel = $this->connection->getTransactionNestingLevel();
    }

    /**
     * A throw here reaches HttpKernel::handle(), which dispatches kernel.exception (rolling back what is still
     * open) and sends the error response, so a failed commit never goes out as a success.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::COMMIT_PRIORITY)]
    public function commitTransaction(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || 0 === $this->openNestingLevel) {
            return;
        }

        if ($this->connection->getTransactionNestingLevel() !== $this->openNestingLevel) {
            throw new \LogicException('The request did not end the transactions it began, or ended the request transaction itself. Nothing was committed.');
        }

        $this->connection->commit();
        $this->openNestingLevel = 0;
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::ROLL_BACK_PRIORITY)]
    public function rollBackOnException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->rollBackOpenTransaction();
        }
    }

    #[AsEventListener(event: KernelEvents::FINISH_REQUEST)]
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function rollBackWhenStillOpen(FinishRequestEvent|TerminateEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->rollBackOpenTransaction();
        }
    }

    public function reset(): void
    {
        $this->rollBackOpenTransaction();
    }

    private function rollBackOpenTransaction(): void
    {
        if (0 === $this->openNestingLevel) {
            return;
        }

        $openNestingLevel = $this->openNestingLevel;
        $this->openNestingLevel = 0;

        try {
            while ($this->connection->getTransactionNestingLevel() >= $openNestingLevel) {
                $this->connection->rollBack();
            }
        } catch (\Throwable $rollBackFailure) {
            // Class and code only: a database message can quote row values.
            $this->logger->warning('Rolling back the request transaction failed; closing the connection instead.', [
                'exception_class' => $rollBackFailure::class,
                'sqlstate' => $rollBackFailure instanceof DriverException ? $rollBackFailure->getSQLState() : null,
                'code' => $rollBackFailure->getCode(),
            ]);
            // Closing the connection ends the transaction on the server, so nothing it wrote is committed.
            $this->connection->close();
        }
    }
}
