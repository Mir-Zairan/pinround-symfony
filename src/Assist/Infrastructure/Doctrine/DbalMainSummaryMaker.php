<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Doctrine;

use App\Assist\Api\LocalPackageId;
use App\Assist\Api\MainSummaryRefused;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\LocalAi\MainSummaryMaker;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\CurrentSummaryChanged;
use App\Ticketing\Api\GeneratedSummaryRefused;
use App\Ticketing\Api\TicketSummaryCommands;
use Doctrine\DBAL\Connection;

/**
 * The savepoint is DBAL's nested transaction, not the entity manager's: a failure inside its wrapInTransaction would
 * close it for the rest of the request.
 */
final readonly class DbalMainSummaryMaker implements MainSummaryMaker
{
    public function __construct(
        private Connection $connection,
        private LocalPackages $localPackages,
        private TicketSummaryCommands $ticketSummaryCommands,
    ) {
    }

    public function makeMain(LocalPackageId $packageId, TicketId $ticketId, ?\DateTimeImmutable $currentSummaryCreatedAt, UserId $chooserId, OrganizationId $organizationId): void
    {
        // Outside a transaction DBAL would open and commit one of its own, under no tenant (TenantConnection).
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Use as main summary is part of an open transaction; none is open.');
        }

        $this->connection->transactional(function () use ($packageId, $ticketId, $currentSummaryCreatedAt, $chooserId, $organizationId): void {
            $posted = $this->localPackages->markMadeMain($organizationId, $packageId, $ticketId, $currentSummaryCreatedAt)
                ?? throw MainSummaryRefused::notAProposal();
            $madeMainAt = $posted->madeMainAt ?? throw new \UnexpectedValueException('A result marked made the main summary has no time.');
            try {
                $this->ticketSummaryCommands->storeChosenLaptopSummary(
                    $organizationId,
                    $ticketId,
                    $posted->result->text,
                    $posted->result->posterId,
                    $posted->result->model->value,
                    $posted->promptVersion,
                    $chooserId,
                    $madeMainAt,
                    $currentSummaryCreatedAt,
                );
            } catch (GeneratedSummaryRefused $refused) {
                throw MainSummaryRefused::summaryRejected($refused->getMessage());
            } catch (CurrentSummaryChanged) {
                throw MainSummaryRefused::notAProposal();
            }
        });
    }
}
