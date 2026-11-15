<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Demo;

use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\Notification\Infrastructure\Doctrine\DbalBellQueries;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\UuidV7;

/**
 * Keeps the bell of Omar, whom the e2e specs log in as, to his KEPT_GROUPS newest open groups, and gives Person0042 of
 * Beta Bakery's team Crowd the big bell instead: one open notification from each of BULK_SENDERS crowd people on each
 * ticket given to Person0042, more groups than DbalBellQueries::MAX_BELL_GROUPS. The e2e specs never mark their own
 * notifications done, and a bell past either cap drops a new group or a new ticket out of view (bell.spec.ts checks
 * the caps first). A second run finishes only what came in meanwhile and adds no notification Person0042 still has
 * open. Dev only, as the crowd steps it runs after.
 */
#[AsTaggedItem(priority: 10)]
#[When('dev')]
final readonly class SeedDemoBells implements DemoSeedStep
{
    public const int KEPT_GROUPS = 10;
    public const int BULK_SENDERS = 60;
    private const string ACME_FOODS = 'acme-foods';
    private const string SHOP_TEAM = 'Shop';
    private const string OMAR = 'Omar';
    private const string BETA_BAKERY = 'beta-bakery';
    private const string CROWD_TEAM = 'Crowd';
    private const string BULK_RECIPIENT = 'Person0042';
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    // Rows with no sender or ticket are no bell item, so they are left as they are.
    private const string FINISH_ALL_BUT_NEWEST_GROUPS = <<<'SQL'
        WITH kept AS (
            SELECT actor_id, ticket_id FROM ntf_notification
            WHERE organization_id = :organization AND user_id = :user AND done_at IS NULL
                AND actor_id IS NOT NULL AND ticket_id IS NOT NULL
            GROUP BY actor_id, ticket_id
            ORDER BY max(created_at) DESC, ticket_id, actor_id
            LIMIT :kept
        )
        UPDATE ntf_notification AS bell SET done_at = :now
        WHERE bell.organization_id = :organization AND bell.user_id = :user AND bell.done_at IS NULL
            AND bell.actor_id IS NOT NULL AND bell.ticket_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM kept WHERE kept.actor_id = bell.actor_id AND kept.ticket_id = bell.ticket_id)
        SQL;

    private const string OPEN_GROUPS = 'SELECT DISTINCT actor_id::text, ticket_id::text FROM ntf_notification'
        .' WHERE organization_id = ? AND user_id = ? AND done_at IS NULL AND actor_id IS NOT NULL AND ticket_id IS NOT NULL';

    private const string INSERT = 'INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data,'
        .' created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    public function __construct(
        private DemoOrganizations $demoOrganizations,
        private TeamQueries $teamQueries,
        private TeamPeopleQueries $teamPeopleQueries,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
        private BellCountCache $bellCountCache,
        private TenantContext $tenantContext,
        private Connection $connection,
        private ClockInterface $clock,
        private int $bulkSenders = self::BULK_SENDERS,
    ) {
    }

    public function seed(): string
    {
        $acmeFoods = $this->demoOrganizations->idBySlug(self::ACME_FOODS)
            ?? throw new DemoSeedRefused('The demo organization Acme Foods does not exist yet, so the bells cannot be seeded.');
        $betaBakery = $this->demoOrganizations->idBySlug(self::BETA_BAKERY)
            ?? throw new DemoSeedRefused('The demo organization Beta Bakery does not exist yet, so the bells cannot be seeded.');

        // Every lookup that may refuse runs before the first write, so a refused run changes nothing.
        $omar = $this->underTenant($acmeFoods, fn (): PossibleAssignee => $this->memberNamed($acmeFoods, self::SHOP_TEAM, self::OMAR));
        [$recipient, $senders, $tickets] = $this->underTenant($betaBakery, fn (): array => $this->bulkBellOf($betaBakery));

        $finished = $this->underTenant($acmeFoods, function () use ($acmeFoods, $omar): int {
            $finished = (int) $this->connection->executeStatement(self::FINISH_ALL_BUT_NEWEST_GROUPS, [
                'organization' => $acmeFoods->toString(),
                'user' => $omar->userId->toString(),
                'kept' => self::KEPT_GROUPS,
                'now' => $this->clock->now()->format(self::TIMESTAMP_FORMAT),
            ], ['kept' => ParameterType::INTEGER]);
            if ($finished > 0) {
                $this->bellCountCache->invalidateAfterCommit($omar->userId);
            }

            return $finished;
        });
        $added = $this->underTenant($betaBakery, fn (): int => $this->addBulkBell($betaBakery, $recipient, $senders, $tickets));

        return \sprintf('Bells: finished %d of Omar\'s notifications, gave %s %d new ones.', $finished, self::BULK_RECIPIENT, $added);
    }

    /**
     * @return array{PossibleAssignee, list<PossibleAssignee>, non-empty-list<TicketListRow>} the recipient, the senders
     *                                                                                        and the recipient's tickets
     */
    private function bulkBellOf(OrganizationId $betaBakery): array
    {
        $crowd = $this->teamPeopleQueries->namedPossibleAssigneesOf($this->teamNamed($betaBakery, self::CROWD_TEAM)->id());
        $recipient = array_find($crowd, static fn (PossibleAssignee $person): bool => self::BULK_RECIPIENT === $person->firstName)
            ?? throw new DemoSeedRefused(\sprintf('The team Crowd has no %s yet, so the bells cannot be seeded. Seed the Crowd team first.', self::BULK_RECIPIENT));
        $senders = \array_slice(
            array_values(array_filter($crowd, static fn (PossibleAssignee $person): bool => !$person->isLead && !$person->userId->equals($recipient->userId))),
            0,
            $this->bulkSenders,
        );
        $tickets = $this->ticketsGivenTo($betaBakery, $recipient);
        if ([] === $tickets) {
            throw new DemoSeedRefused(\sprintf('No ticket is given to %s yet, so the bells cannot be seeded. Seed the Crowd tickets first.', self::BULK_RECIPIENT));
        }

        return [$recipient, $senders, $tickets];
    }

    /**
     * @param list<PossibleAssignee>        $senders
     * @param non-empty-list<TicketListRow> $tickets
     */
    private function addBulkBell(OrganizationId $betaBakery, PossibleAssignee $recipient, array $senders, array $tickets): int
    {
        $openGroups = [];
        foreach ($this->connection->fetchAllNumeric(self::OPEN_GROUPS, [$betaBakery->toString(), $recipient->userId->toString()]) as [$actorId, $ticketId]) {
            \assert(\is_string($actorId) && \is_string($ticketId));
            $openGroups[$actorId.'|'.$ticketId] = true;
        }

        $now = $this->clock->now();
        $added = 0;
        foreach ($tickets as $ticket) {
            foreach ($senders as $sender) {
                if (isset($openGroups[$sender->userId->toString().'|'.$ticket->id->toString()])) {
                    continue;
                }
                // A second apart, so the bell's newest-first order is the same on every read.
                $createdAt = $now->modify(\sprintf('-%d seconds', $added + 1));
                $this->connection->executeStatement(self::INSERT, [
                    $betaBakery->toString(),
                    new UuidV7(UuidV7::generate($createdAt))->toRfc4122(),
                    $recipient->userId->toString(),
                    NotificationKind::Assigned->value,
                    $sender->userId->toString(),
                    $ticket->id->toString(),
                    json_encode(['actorName' => $sender->displayName, 'ticketKey' => $ticket->key], \JSON_THROW_ON_ERROR),
                    $createdAt->format(self::TIMESTAMP_FORMAT),
                ]);
                ++$added;
            }
        }
        if ($added > 0) {
            $this->bellCountCache->invalidateAfterCommit($recipient->userId);
        }

        return $added;
    }

    /**
     * @return list<TicketListRow>
     */
    private function ticketsGivenTo(OrganizationId $organizationId, PossibleAssignee $person): array
    {
        $filter = new ListFilter($this->ticketVisibilities->of($organizationId, $person->userId), assigneeId: $person->userId->toString());
        $tickets = [];
        foreach ($this->ticketQueries->list($filter) as $group) {
            foreach ($group->steps as $step) {
                array_push($tickets, ...$step->rows);
            }
        }

        return $tickets;
    }

    private function memberNamed(OrganizationId $organizationId, string $teamName, string $firstName): PossibleAssignee
    {
        return array_find(
            $this->teamPeopleQueries->namedPossibleAssigneesOf($this->teamNamed($organizationId, $teamName)->id()),
            static fn (PossibleAssignee $person): bool => $firstName === $person->firstName,
        ) ?? throw new DemoSeedRefused(\sprintf('The team %s has no %s yet, so the bells cannot be seeded.', $teamName, $firstName));
    }

    private function teamNamed(OrganizationId $organizationId, string $name): TeamSummary
    {
        return array_find(
            $this->teamQueries->allSummariesOf($organizationId),
            static fn (TeamSummary $summary): bool => 0 === strcasecmp($summary->name(), $name),
        ) ?? throw new DemoSeedRefused(\sprintf('The team %s does not exist yet, so the bells cannot be seeded.', $name));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function underTenant(OrganizationId $organizationId, \Closure $work): mixed
    {
        $this->tenantContext->set($organizationId);
        try {
            return $this->connection->transactional($work);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
