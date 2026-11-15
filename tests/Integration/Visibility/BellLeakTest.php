<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Bell\BellInbox;
use App\Notification\Application\Bell\MarkBellGroupDone;
use App\Notification\Application\Bell\OpenBellGroup;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\MockClock;

/**
 * The bell, its count, Done and Open (NotificationQueries::bell, BellInbox, MarkBellGroupDone and OpenBellGroup): every
 * viewer has an open question about each cast ticket, as a notification keeps its ticket after the person loses sight
 * of it, and the bell shows, counts and changes exactly those of tickets they may still see. Raj asks, and Noor asks
 * Raj. Ben's rows are in Acme Foods, which his own organization's bell never reads.
 */
final class BellLeakTest extends VisibilityLeakCast
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string TEAM_ONLY_KEY = 'SHOP-1';
    private const string NO_TEAM_KEY = 'OPS-90';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $rows = [];
        $index = 0;
        foreach (self::VIEWERS as $viewer => [$userId]) {
            foreach ([[self::teamOnlyTicketId(), self::TEAM_ONLY_KEY], [self::NO_TEAM_TICKET, self::NO_TEAM_KEY]] as [$ticketId, $ticketKey]) {
                $actor = self::actorOf($viewer);
                $rows[] = [
                    'organization_id' => self::ACME_FOODS,
                    'id' => \sprintf('0197a3c0-0000-7000-8000-0000000058%02x', $index),
                    'user_id' => $userId,
                    'kind' => 'question',
                    'actor_id' => self::VIEWERS[$actor][0],
                    'ticket_id' => $ticketId,
                    'data' => json_encode(['actorName' => $actor, 'ticketKey' => $ticketKey], \JSON_THROW_ON_ERROR),
                    'created_at' => \sprintf('2027-03-16 %02d:00:00+00', 8 + $index),
                ];
                ++$index;
            }
        }
        new NotificationRows(self::migrator(), self::tenantContext())->addOf(self::ACME_FOODS, 'ntf_notification', $rows);
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->bellCountPool()->clear();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theBellShowsAndCountsOnlyTheTicketsTheViewerMaySee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expected = $this->visibleOf($seesTeamOnly, $seesNoTeamTicket);

        [$bell, $openCount] = self::asViewer($viewer, fn (): array => [
            $this->notificationQueries()->bell(self::userIdOf($viewer)),
            $this->bellInbox()->openCountOf(self::userIdOf($viewer), new \DateTimeImmutable(self::NOW)),
        ]);

        self::assertSame($expected, array_map(static fn ($group): string => $group->ticketId->toString(), $bell->groups));
        self::assertSame(\count($expected), $bell->openCount);
        self::assertSame(\count($expected), $openCount);
    }

    /**
     * Done and Open on a ticket the viewer may not see find nothing and leave its row as it was; on one they may see
     * they mark it, so the refusal is the visibility check's and not a group that never matched.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function doneAndOpenChangeOnlyTheRowsOfTicketsTheViewerMaySee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $isOfAcmeFoods = self::ACME_FOODS === self::VIEWERS[$viewer][1];
        $expectedRows = $isOfAcmeFoods
            ? [self::teamOnlyTicketId() => [$seesTeamOnly, $seesTeamOnly], self::NO_TEAM_TICKET => [$seesNoTeamTicket, $seesNoTeamTicket]]
            : [];
        ksort($expectedRows);

        [$answers, $rows] = self::asViewer($viewer, function () use ($viewer): array {
            $answers = [];
            $visibility = self::visibilityOf($viewer);
            $sender = self::userIdOf(self::actorOf($viewer));
            foreach ([self::teamOnlyTicketId(), self::NO_TEAM_TICKET] as $ticketId) {
                $answers[$ticketId] = [
                    $this->openBellGroup()->open($visibility, $sender, new TicketId($ticketId), new \DateTimeImmutable(self::NOW)),
                    $this->markBellGroupDone()->markDone($visibility, $sender, new TicketId($ticketId), new \DateTimeImmutable(self::NOW)),
                ];
            }

            return [$answers, $this->rowStatesOf($viewer)];
        });

        self::assertSame([
            self::teamOnlyTicketId() => $seesTeamOnly ? [self::TEAM_ONLY_KEY, 1] : [null, 0],
            self::NO_TEAM_TICKET => $seesNoTeamTicket ? [self::NO_TEAM_KEY, 1] : [null, 0],
        ], $answers);
        self::assertSame($expectedRows, $rows);
    }

    /**
     * Lena sees the team-only ticket only as a member of its team. Once she leaves it, a cold count, the bell and Done
     * leave that ticket out, and her row about it stays open.
     */
    #[Test]
    public function aPersonWhoLeavesTheTeamLosesTheTicketFromTheBellItsCountAndDone(): void
    {
        $lena = self::userIdOf('Lena');
        self::assertSame(1, self::asViewer('Lena', fn (): int => $this->bellInbox()->openCountOf($lena, new \DateTimeImmutable(self::NOW))));

        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($lena): void {
            self::assertSame(1, $migrator->executeStatement('DELETE FROM org_team_member WHERE team_id = ? AND user_id = ?', [self::SHOP_TEAM, $lena->toString()]));
        });
        try {
            $this->bellCountPool()->clear();
            [$bell, $openCount, $marked, $rows] = self::asViewer('Lena', fn (): array => [
                $this->notificationQueries()->bell($lena),
                $this->bellInbox()->openCountOf($lena, new \DateTimeImmutable(self::NOW)),
                $this->markBellGroupDone()->markDone(self::visibilityOf('Lena'), self::userIdOf('Raj'), new TicketId(self::teamOnlyTicketId()), new \DateTimeImmutable(self::NOW)),
                $this->rowStatesOf('Lena'),
            ]);
        } finally {
            self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, 'lead', 'developer', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::SHOP_TEAM, $lena->toString()],
            ));
        }

        self::assertSame([[], 0, 0], [$bell->groups, $bell->openCount, $openCount]);
        self::assertSame(0, $marked);
        $openRows = [self::NO_TEAM_TICKET => [false, false], self::teamOnlyTicketId() => [false, false]];
        ksort($openRows);
        self::assertSame($openRows, $rows);
    }

    /**
     * @return list<string> the ids of the cast tickets the viewer may see, newest notification first, as the bell orders them
     */
    private function visibleOf(bool $seesTeamOnly, bool $seesNoTeamTicket): array
    {
        return array_keys(array_filter([self::NO_TEAM_TICKET => $seesNoTeamTicket, self::teamOnlyTicketId() => $seesTeamOnly]));
    }

    /**
     * @return array<string, array{bool, bool}> whether each of the viewer's rows is read and done, by ticket id
     */
    private function rowStatesOf(string $viewer): array
    {
        $rows = self::connection()->fetchAllAssociative(
            'SELECT ticket_id, read_at IS NOT NULL AS is_read, done_at IS NOT NULL AS is_done FROM ntf_notification WHERE user_id = ? ORDER BY ticket_id',
            [self::VIEWERS[$viewer][0]],
        );
        $states = [];
        foreach ($rows as $row) {
            self::assertIsString($row['ticket_id']);
            self::assertIsBool($row['is_read']);
            self::assertIsBool($row['is_done']);
            $states[$row['ticket_id']] = [$row['is_read'], $row['is_done']];
        }

        return $states;
    }

    private static function actorOf(string $viewer): string
    {
        return 'Raj' === $viewer ? 'Noor' : 'Raj';
    }

    private static function userIdOf(string $viewer): UserId
    {
        return new UserId(self::VIEWERS[$viewer][0]);
    }

    private function bellCountPool(): CacheItemPoolInterface
    {
        return self::getContainer()->get('cache.bell_count');
    }

    private function notificationQueries(): NotificationQueries
    {
        $notificationQueries = self::getContainer()->get('test.notification_queries');
        \assert($notificationQueries instanceof NotificationQueries);

        return $notificationQueries;
    }

    private function bellInbox(): BellInbox
    {
        $bellInbox = self::getContainer()->get('test.notification_bell_inbox');
        \assert($bellInbox instanceof BellInbox);

        return $bellInbox;
    }

    private function markBellGroupDone(): MarkBellGroupDone
    {
        $markBellGroupDone = self::getContainer()->get('test.notification_mark_bell_group_done');
        \assert($markBellGroupDone instanceof MarkBellGroupDone);

        return $markBellGroupDone;
    }

    private function openBellGroup(): OpenBellGroup
    {
        $openBellGroup = self::getContainer()->get('test.notification_open_bell_group');
        \assert($openBellGroup instanceof OpenBellGroup);

        return $openBellGroup;
    }

    /**
     * The viewer's visibility as BellController builds it: TicketVisibilities::of() under their own organization.
     */
    private static function visibilityOf(string $viewer): TicketVisibility
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities->of(new OrganizationId(self::VIEWERS[$viewer][1]), self::userIdOf($viewer));
    }
}
