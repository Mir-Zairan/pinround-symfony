<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\LockedTicketTurnQueries;
use Doctrine\DBAL\Connection;

/**
 * The ticket is read by its primary key, naming organization_id as well as row-level security. Its step comes from the
 * team's cached steps, and from StepQueries::groupOf for a step they no longer list (removed) or a ticket with no team;
 * such a step gets no core code. The team's lead and people come from the team people cache, so a warm read is one query.
 */
final readonly class DbalTicketTurnQueries implements LockedTicketTurnQueries
{
    private const string NO_LOCK = '';
    private const string SHARE_LOCK = ' FOR SHARE OF ticket';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketTeamLookup $teamLookup,
        private SettingsQueries $settingsQueries,
    ) {
    }

    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        return $this->factsReadWith($ticketId, self::NO_LOCK);
    }

    public function lockedFactsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        return $this->factsReadWith($ticketId, self::SHARE_LOCK);
    }

    private function factsReadWith(TicketId $ticketId, string $lockClause): ?TicketTurnSnapshot
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s turn facts are read in a transaction under the organization as tenant; none is open.');
        }

        $row = $this->connection->fetchAssociative(
            'SELECT '.TurnSnapshotRow::COLUMNS.' FROM tkt_ticket AS ticket WHERE ticket.organization_id = ? AND ticket.id = ?'.$lockClause,
            [$this->tenantContext->require()->toString(), $ticketId->toString()],
        );
        if (false === $row) {
            return null;
        }

        $stepId = self::text($row['step_id'], 'step_id');
        $teamId = TurnSnapshotRow::teamIdOf($row);
        [$groupCode, $coreCode] = $this->teamLookup->groupAndCoreCodeOf($stepId, $teamId);
        [$leadId, $leadDisplayName, $developerIds, $testerIds] = null === $teamId ? [null, null, [], []] : $this->teamLookup->peopleOf($teamId);

        $triagerId = TurnSnapshotRow::needsTheTriager($row) ? $this->settingsQueries->triagerOf($this->tenantContext->require()) : null;

        return TurnSnapshotRow::snapshotOf($ticketId, $row, $groupCode, $coreCode, $leadId, $leadDisplayName, $developerIds, $testerIds, $triagerId);
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A ticket %s from the database is not a string.', $column));
    }
}
