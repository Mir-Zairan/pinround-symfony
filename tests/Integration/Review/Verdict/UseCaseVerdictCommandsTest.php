<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Verdict;

use App\Review\Api\Event\AreaVerdictSaved;
use App\Review\Api\Event\PinVerdictSet;
use App\Review\Api\Event\PinVerdictUndone;
use App\Review\Api\VerdictCommands;
use App\Review\Api\VerdictRefused;
use App\Review\Application\Verdict\UseCaseVerdictCommands;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\ReviewedRound;
use App\Review\Infrastructure\Doctrine\DbalAreas;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Tests\Unit\Review\Application\RecordingReviewEvents;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * VerdictCommands as the container wires it, on the real tables, outbox and relay. Mia gives verdicts in Acme Foods'
 * open review round 2 of SHOP-1, which compares Round 0's picture; no verdict moves the ticket. A refusal is caught
 * inside a transaction that then commits, so "unchanged" proves the check comes before the write.
 */
final class UseCaseVerdictCommandsTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f7c01';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f7c02';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f7c03';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f7c04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7c05';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f7c06';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f7c11';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f7c12';
    private const string CLOSED_REVIEW = '0197a3c0-0000-7000-8000-0000000f7c13';
    private const string BEFORE = '0197a3c0-0000-7000-8000-0000000f7c21';
    private const string AFTER = '0197a3c0-0000-7000-8000-0000000f7c22';
    private const string AREA = '0197a3c0-0000-7000-8000-0000000f7c31';
    private const string PIN = '0197a3c0-0000-7000-8000-0000000f7c41';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f7c51';
    private const string NOTE = 'The total still shows the old price.';
    private const string NOW = '2027-03-16 09:30:00+00:00';
    private const string UNDONE_AT = '2027-03-16 09:45:00+00:00';

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::REPORT, ['kind' => 'report', 'number' => 0, 'state' => 'closed']),
            self::round(self::CLOSED_REVIEW, ['kind' => 'review', 'number' => 1, 'state' => 'closed']),
            self::round(self::REVIEW, ['kind' => 'review', 'number' => 2]),
            self::screenshot(self::BEFORE, self::REPORT),
            self::screenshot(self::AFTER, self::REVIEW),
            ['rvw_pin', [
                'organization_id' => self::ACME_FOODS, 'id' => self::PIN, 'screenshot_id' => self::BEFORE, 'number' => 1, 'x_pct' => '40.00',
                'y_pct' => '60.00', 'note' => 'Looks off', 'source' => 'human', 'author_id' => self::LENA, 'created_at' => '2027-03-15 10:00:00+00',
            ]],
            ['rvw_area', [
                'organization_id' => self::ACME_FOODS, 'id' => self::AREA, 'round_id' => self::REVIEW, 'name' => 'Per product', 'position' => 0,
                'before_screenshot_id' => self::BEFORE, 'after_screenshot_id' => self::AFTER, 'created_at' => '2027-03-16 08:00:00+00',
            ]],
        ]);
        $this->deleteOutboxRowsNaming(self::TICKET);
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->deleteOutboxRowsNaming(self::TICKET);
        parent::tearDown();
    }

    #[Test]
    public function aPinVerdictIsSavedWithOnePinVerdictSetInTheOutboxAndTheTicketAndRoundStayWhereTheyWere(): void
    {
        $ticketAndRoundBefore = $this->ticketStepAndVersionAndRoundVersion();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW)));

        self::assertSame(['fixed'], $this->rowsAs('SELECT verdict FROM rvw_pin WHERE id = ?', [self::PIN]));
        $outboxBodies = $this->outboxBodiesNaming(self::TICKET);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', PinVerdictSet::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringContainsString(self::PIN, $outboxBodies[0]);
        self::assertSame($ticketAndRoundBefore, $this->ticketStepAndVersionAndRoundVersion());
    }

    #[Test]
    public function anAreaVerdictIsSavedAndItsEventCarriesNoNoteText(): void
    {
        $ticketAndRoundBefore = $this->ticketStepAndVersionAndRoundVersion();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->saveAreaVerdict(self::REVIEW, self::AREA, new UserId(self::MIA), VerdictCommands::AREA_NEEDS_FIX, self::NOTE, new \DateTimeImmutable(self::NOW)));

        self::assertSame(['needs_fix', self::NOTE, self::MIA], $this->rowsAs('SELECT verdict, note, decided_by FROM rvw_area WHERE id = ?', [self::AREA], all: true)[0] ?? null);
        $outboxBodies = $this->outboxBodiesNaming(self::TICKET);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', AreaVerdictSaved::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringNotContainsString('old price', $outboxBodies[0]);
        self::assertSame($ticketAndRoundBefore, $this->ticketStepAndVersionAndRoundVersion());
    }

    #[Test]
    public function theRelayHandsTheVerdictEventToAsyncNotify(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_STILL_WRONG, new \DateTimeImmutable(self::NOW)));

        self::runTheWorkerUntilIdle('outbox');

        $relayed = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->asyncNotify()->getSent()),
            static fn (object $message): bool => $message instanceof PinVerdictSet && self::REVIEW === $message->roundId,
        ));
        self::assertEquals(
            [new PinVerdictSet(self::ACME_FOODS, self::TICKET, self::REVIEW, self::PIN, 'still_wrong', self::MIA, new \DateTimeImmutable(self::NOW))],
            $relayed,
        );
    }

    #[Test]
    public function anUndoClearsTheVerdictWithOnePinVerdictUndoneInTheOutboxAfterThePinVerdictSet(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW)));
        $ticketAndRoundBefore = $this->ticketStepAndVersionAndRoundVersion();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->undoPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), new \DateTimeImmutable(self::UNDONE_AT)));

        self::assertSame([null], $this->rowsAs('SELECT verdict FROM rvw_pin WHERE id = ?', [self::PIN]));
        $outboxBodies = array_map(static fn (string $body): string => str_replace('\\', '', $body), $this->outboxBodiesNaming(self::TICKET));
        self::assertCount(2, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', PinVerdictSet::class), $outboxBodies[0]);
        self::assertStringContainsString(str_replace('\\', '', PinVerdictUndone::class), $outboxBodies[1]);
        self::assertStringNotContainsString(str_replace('\\', '', PinVerdictUndone::class), $outboxBodies[0]);
        self::assertSame($ticketAndRoundBefore, $this->ticketStepAndVersionAndRoundVersion());
    }

    #[Test]
    public function theRelayHandsTheUndoneVerdictEventToAsyncNotify(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_STILL_WRONG, new \DateTimeImmutable(self::NOW)));
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->undoPinVerdict(self::REVIEW, self::PIN, new UserId(self::OMAR), new \DateTimeImmutable(self::UNDONE_AT)));

        self::runTheWorkerUntilIdle('outbox');

        $relayed = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->asyncNotify()->getSent()),
            static fn (object $message): bool => $message instanceof PinVerdictUndone && self::REVIEW === $message->roundId,
        ));
        self::assertEquals(
            [new PinVerdictUndone(self::ACME_FOODS, self::TICKET, self::REVIEW, self::PIN, 'still_wrong', self::OMAR, new \DateTimeImmutable(self::UNDONE_AT))],
            $relayed,
        );
    }

    #[Test]
    public function aVerdictInAClosedRoundIsRefusedAndWritesNothing(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?VerdictRefused {
            try {
                $this->commands()->setPinVerdict(self::CLOSED_REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW));
            } catch (VerdictRefused $refused) {
                return $refused;
            }

            return null;
        });

        self::assertSame(ReviewedRound::CLOSED, $refusal?->getMessage());
        self::assertSame([null], $this->rowsAs('SELECT verdict FROM rvw_pin WHERE id = ?', [self::PIN]));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
    }

    /**
     * Two testers give the same pin a verdict at once: the second waits until the first commits, then finds the verdict
     * given and is refused, so one PinVerdictSet is published.
     */
    #[Test]
    public function aSecondVerdictOnThePinWaitsForTheFirstAndIsThenRefused(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        $secondSessionEvents = new RecordingReviewEvents();
        $secondSessionCommands = new UseCaseVerdictCommands(new DbalAreas($secondSession->entityManager->getConnection(), $this->tenantContext()), $secondSessionEvents, $this->tenantContext());
        $omarsVerdict = static fn () => $secondSessionCommands->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::OMAR), VerdictCommands::PIN_STILL_WRONG, new \DateTimeImmutable(self::NOW));
        $connection = $this->connection();
        $refusalAfterTheFirst = null;

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $connection->beginTransaction();
            $this->commands()->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW));
            $sqlStateWhileTheFirstRuns = $secondSession->sqlStateOfFailedAttempt($omarsVerdict);
            $connection->commit();

            try {
                $secondSession->entityManager->wrapInTransaction($omarsVerdict);
            } catch (VerdictRefused $refusal) {
                $refusalAfterTheFirst = $refusal->getMessage();
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager()->clear();
            $secondSession->entityManager->clear();
            $this->tenantContext()->clear();
        }

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlStateWhileTheFirstRuns, 'The second verdict ran while the first held the round.');
        self::assertSame(PinToCheck::VERDICT_GIVEN, $refusalAfterTheFirst);
        self::assertSame(['fixed'], $this->rowsAs('SELECT verdict FROM rvw_pin WHERE id = ?', [self::PIN]));
        self::assertCount(1, $this->outboxBodiesNaming(self::TICKET));
        self::assertSame([], $secondSessionEvents->published(), 'The refused verdict published.');
    }

    /**
     * A pin verdict and an area verdict in the same round at once: the area verdict waits on the round until the pin
     * verdict commits, writes nothing while it waits, and is then saved.
     */
    #[Test]
    public function anAreaVerdictWaitsForAPinVerdictInTheSameRound(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        $secondSessionEvents = new RecordingReviewEvents();
        $secondSessionCommands = new UseCaseVerdictCommands(new DbalAreas($secondSession->entityManager->getConnection(), $this->tenantContext()), $secondSessionEvents, $this->tenantContext());
        $omarsAreaVerdict = static fn () => $secondSessionCommands->saveAreaVerdict(self::REVIEW, self::AREA, new UserId(self::OMAR), VerdictCommands::AREA_QUESTION, null, new \DateTimeImmutable(self::NOW));
        $connection = $this->connection();

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $connection->beginTransaction();
            $this->commands()->setPinVerdict(self::REVIEW, self::PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW));
            $sqlStateWhileThePinVerdictRuns = $secondSession->sqlStateOfFailedAttempt($omarsAreaVerdict);
            $connection->commit();
            $eventsWhileThePinVerdictRan = $secondSessionEvents->published();
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager()->clear();
            $this->tenantContext()->clear();
        }
        $areaAfterTheWait = $this->rowsAs('SELECT verdict, decided_by FROM rvw_area WHERE id = ?', [self::AREA], all: true)[0] ?? null;

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $secondSession->entityManager->wrapInTransaction($omarsAreaVerdict);
        } finally {
            $secondSession->entityManager->clear();
            $this->tenantContext()->clear();
        }

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlStateWhileThePinVerdictRuns, 'The area verdict ran while the pin verdict held the round.');
        self::assertSame([], $eventsWhileThePinVerdictRan, 'The waiting area verdict published.');
        self::assertSame([null, null], $areaAfterTheWait);
        self::assertSame(['question', self::OMAR], $this->rowsAs('SELECT verdict, decided_by FROM rvw_area WHERE id = ?', [self::AREA], all: true)[0] ?? null);
        self::assertSame(['fixed'], $this->rowsAs('SELECT verdict FROM rvw_pin WHERE id = ?', [self::PIN]));
        self::assertCount(1, $secondSessionEvents->published());
    }

    /**
     * @return array{mixed, mixed, mixed} the ticket's step and version, and the review round's version
     */
    private function ticketStepAndVersionAndRoundVersion(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->connection()->fetchOne('SELECT step_id FROM tkt_ticket WHERE id = ?', [self::TICKET]),
            $this->connection()->fetchOne('SELECT version FROM tkt_ticket WHERE id = ?', [self::TICKET]),
            $this->connection()->fetchOne('SELECT version FROM rvw_round WHERE id = ?', [self::REVIEW]),
        ]);
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed> the first column, or each row's values with $all
     */
    private function rowsAs(string $sql, array $parameters, bool $all = false): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $all
            ? $this->connection()->fetchAllNumeric($sql, $parameters)
            : $this->connection()->fetchFirstColumn($sql, $parameters));
    }

    /**
     * Only the outbox rows naming this test's ids: other tests delete outbox rows at the same time.
     *
     * @return list<string>
     */
    private function outboxBodiesNaming(string $id): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ? ORDER BY id", ['%'.$id.'%']) as $body) {
            self::assertIsString($body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    private function deleteOutboxRowsNaming(string $id): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$id.'%']);
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $roundId, array $change): array
    {
        return ['rvw_round', [
            'organization_id' => self::ACME_FOODS,
            'id' => $roundId,
            'ticket_id' => self::TICKET,
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'open',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function screenshot(string $screenshotId, string $roundId): array
    {
        return ['rvw_screenshot', [
            'organization_id' => self::ACME_FOODS,
            'id' => $screenshotId,
            'round_id' => $roundId,
            'media_id' => self::MEDIA,
            'position' => 0,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    private function commands(): VerdictCommands
    {
        $commands = self::getContainer()->get('test.review_verdict_commands');
        self::assertInstanceOf(UseCaseVerdictCommands::class, $commands);

        return $commands;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
