<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Review\Domain\DraftDecision;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\DraftDecisionNeeded;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Which shared round a move closes (ADR-0048), through TicketCommands::move and moveTo on the real tables, policy, turn
 * rules and DbalRounds, as pinround_app under row-level security. Acme Foods' SHOP-7 is reported by Mia and assigned to
 * Omar; Lena leads the team and Mia is its tester. SHOP-8 has an open findings round of its own, which no move of SHOP-7
 * may close.
 */
final class MoveTicketRoundClosingTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c4701';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000c4711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c4721';
    private const string IN_PROGRESS_STEP = '0197a3c0-0000-7000-8000-0000000c4741';
    private const string CODE_REVIEW_STEP = '0197a3c0-0000-7000-8000-0000000c4742';
    private const string TESTING_STEP = '0197a3c0-0000-7000-8000-0000000c4743';
    private const string ON_HOLD_STEP = '0197a3c0-0000-7000-8000-0000000c4745';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000c4746';
    private const string DESIGN_CHECK_STEP = '0197a3c0-0000-7000-8000-0000000c4747';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c4751';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c4752';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c4753';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000c4781';
    private const string MIAS_CLOSED_FINDINGS = '0197a3c0-0000-7000-8000-0000000c4782';
    private const string OMARS_HAND_OFF_ROUND = '0197a3c0-0000-7000-8000-0000000c4783';
    private const string MIAS_OPEN_FINDINGS = '0197a3c0-0000-7000-8000-0000000c4784';
    private const string OMARS_SHARED_NOW_ROUND = '0197a3c0-0000-7000-8000-0000000c4785';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000c4786';
    private const string LENAS_DRAFT = '0197a3c0-0000-7000-8000-0000000c4787';
    private const string OTHER_TICKETS_FINDINGS = '0197a3c0-0000-7000-8000-0000000c4788';
    private const string REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000c4791';
    private const string MIAS_DRAFT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000c4792';
    private const string LENAS_DRAFT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000c4793';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000c47a1';
    private const string KEY = 'SHOP-7';
    private const string STARTED_AT = '2027-03-15 11:00:00+00:00';
    private const string SHARED_AT = '2027-03-15 15:00:00+00:00';
    private const string MOVED_AT = '2027-03-16 09:30:00+00:00';
    private const string OPEN = 'open';
    private const string CLOSED = 'closed';

    private TicketId $ticketId;
    private TicketId $otherTicketId;

    protected function setUp(): void
    {
        self::mockTime(self::MOVED_AT);
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        $this->addAcmeSteps();
        $shopTickets = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection());
        $this->ticketId = $shopTickets->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $this->otherTicketId = $shopTickets->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $this->addRound(self::sharedRound(self::OTHER_TICKETS_FINDINGS, $this->otherTicketId, self::MIA, 1, RoundState::Open, DraftDecision::Shared));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * The report and the closed Round 1 are no open shared rounds, and SHOP-8's round is another ticket's, so only
     * Round 2 is closed.
     */
    #[Test]
    public function theTesterWhoseTurnItIsClosesTheOpenRoundWithNeedsChanges(): void
    {
        $this->setColumns(['step_id' => self::TESTING_STEP, 'tester_id' => self::MIA]);
        $this->addRound(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), $this->ticketId, new UserId(self::MIA), [
            Screenshot::of(new ScreenshotId(self::REPORT_SCREENSHOT), self::MEDIA, 0, null, null, []),
        ], new \DateTimeImmutable(self::STARTED_AT)));
        $this->addRound(self::sharedRound(self::MIAS_CLOSED_FINDINGS, $this->ticketId, self::MIA, 1, RoundState::Closed, DraftDecision::Shared));
        $this->addRound(self::sharedRound(self::OMARS_HAND_OFF_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, DraftDecision::Shared));

        $this->move(self::MIA, 'needs_changes');

        self::assertSame(self::IN_PROGRESS_STEP, $this->stepId());
        self::assertSame(
            [
                self::REPORT => [0, self::OPEN, null],
                self::MIAS_CLOSED_FINDINGS => [1, self::CLOSED, 'shared'],
                self::OMARS_HAND_OFF_ROUND => [2, self::CLOSED, 'shared'],
            ],
            $this->roundsOf($this->ticketId),
        );
        $this->assertOtherTicketsRoundIsOpen();
    }

    /**
     * On hold it is Lena's turn, so Go on would close the round if lead-only moves were not left out.
     */
    #[Test]
    public function theLeadsPutOnHoldAndGoOnLeaveTheOpenRoundOpen(): void
    {
        $this->setColumns(['step_id' => self::TESTING_STEP, 'tester_id' => self::MIA]);
        $this->addRound(self::sharedRound(self::OMARS_HAND_OFF_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, DraftDecision::Shared));

        $this->move(self::LENA, 'put_on_hold');

        self::assertSame(self::ON_HOLD_STEP, $this->stepId());
        self::assertSame([self::OMARS_HAND_OFF_ROUND => [2, self::OPEN, 'shared']], $this->roundsOf($this->ticketId));

        $this->move(self::LENA, 'go_on');

        self::assertSame(self::TESTING_STEP, $this->stepId());
        self::assertSame([self::OMARS_HAND_OFF_ROUND => [2, self::OPEN, 'shared']], $this->roundsOf($this->ticketId));
    }

    /**
     * Round 2 is Omar's own hand-off round, no findings round of his, so only his turn in In progress closes it.
     */
    #[Test]
    public function afterTheLeadMovesTheTicketOnTheDeveloperWhoseTurnItIsNowClosesTheRound(): void
    {
        $this->setColumns(['step_id' => self::ON_HOLD_STEP, 'previous_step_id' => self::TESTING_STEP, 'tester_id' => self::MIA]);
        $this->addRound(self::sharedRound(self::OMARS_HAND_OFF_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, DraftDecision::Shared));

        $this->moveTo(self::LENA, self::IN_PROGRESS_STEP);

        self::assertSame(self::IN_PROGRESS_STEP, $this->stepId());
        self::assertSame([self::OMARS_HAND_OFF_ROUND => [2, self::OPEN, 'shared']], $this->roundsOf($this->ticketId));

        $this->move(self::OMAR, 'send_to_code_review');

        self::assertSame(self::CODE_REVIEW_STEP, $this->stepId());
        self::assertSame([self::OMARS_HAND_OFF_ROUND => [2, self::CLOSED, 'shared']], $this->roundsOf($this->ticketId));
    }

    /**
     * In a checking step it is the tester's turn, not Omar's, yet the assignee moving on closes Round 3, Mia's findings
     * for him. Round 2, which is no findings round, waits for whoever's turn it is, so it stays open; two open rounds
     * tell which one the assignee's move closes.
     */
    #[Test]
    public function theAssigneeMovingOnClosesTheirFindingsRoundThoughItIsTheTestersTurn(): void
    {
        $this->setColumns(['step_id' => self::DESIGN_CHECK_STEP, 'previous_step_id' => self::IN_PROGRESS_STEP, 'tester_id' => self::MIA]);
        $this->addRound(self::sharedRound(self::OMARS_HAND_OFF_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, DraftDecision::Shared));
        $this->addRound(self::sharedRound(self::MIAS_OPEN_FINDINGS, $this->ticketId, self::MIA, 3, RoundState::Open, DraftDecision::Shared));

        $this->move(self::OMAR, 'move_on');

        self::assertSame(self::TESTING_STEP, $this->stepId());
        self::assertSame(
            [
                self::OMARS_HAND_OFF_ROUND => [2, self::OPEN, 'shared'],
                self::MIAS_OPEN_FINDINGS => [3, self::CLOSED, 'shared'],
            ],
            $this->roundsOf($this->ticketId),
        );
        $this->assertOtherTicketsRoundIsOpen();
    }

    /**
     * Omar shared Round 2 with "Share now", outside a move, so his own move does not end it though it is his turn.
     */
    #[Test]
    public function aRoundTheMoverSharedOutsideAMoveStaysOpenOnTheirOwnMove(): void
    {
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP]);
        $this->addRound(self::sharedRound(self::OMARS_SHARED_NOW_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, null));

        $this->move(self::OMAR, 'send_to_code_review');

        self::assertSame(self::CODE_REVIEW_STEP, $this->stepId());
        self::assertSame([self::OMARS_SHARED_NOW_ROUND => [2, self::OPEN, null]], $this->roundsOf($this->ticketId));
    }

    /**
     * The move closes Round 2 before Mia's draft is numbered, so the share is not blocked and takes Round 3, all in the
     * one transaction of the move.
     */
    #[Test]
    public function aClosingHandOffClosesTheRoundThenSharesTheMoversDraftAsTheNextNumber(): void
    {
        $this->setColumns(['step_id' => self::TESTING_STEP, 'tester_id' => self::MIA]);
        $this->addRound(self::sharedRound(self::OMARS_HAND_OFF_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, DraftDecision::Shared));
        $this->addRound(self::draftWithAScreenshot(self::MIAS_DRAFT, $this->ticketId, self::MIA, self::MIAS_DRAFT_SCREENSHOT));

        $question = $this->refusalOf(fn () => $this->move(self::MIA, 'needs_changes', null));

        self::assertInstanceOf(DraftDecisionNeeded::class, $question);
        self::assertSame([self::MIAS_DRAFT, 3, null, false], [$question->draft->roundId, $question->draft->roundNumber, $question->draft->openRoundNumber, $question->draft->isSharingBlocked()]);
        self::assertSame(self::TESTING_STEP, $this->stepId());

        $this->move(self::MIA, 'needs_changes', DraftChoice::Share);

        self::assertSame(self::IN_PROGRESS_STEP, $this->stepId());
        self::assertSame(
            [
                self::OMARS_HAND_OFF_ROUND => [2, self::CLOSED, 'shared'],
                self::MIAS_DRAFT => [3, self::OPEN, 'shared'],
            ],
            $this->roundsOf($this->ticketId),
        );
        self::assertSame(['2027-03-16 09:30:00', '2027-03-16 09:30:00'], $this->sharedAndDecidedAtOf(self::MIAS_DRAFT));
    }

    /**
     * Lena's Put on hold closes nothing, so Round 2 stays open and blocks her share: the move is refused with the
     * round's reason and rolled back whole.
     */
    #[Test]
    public function aShareBlockedByAnOpenRoundRefusesTheMoveAndSavesNothing(): void
    {
        $this->setColumns(['step_id' => self::TESTING_STEP, 'tester_id' => self::MIA]);
        $this->addRound(self::sharedRound(self::OMARS_HAND_OFF_ROUND, $this->ticketId, self::OMAR, 2, RoundState::Open, DraftDecision::Shared));
        $this->addRound(self::draftWithAScreenshot(self::LENAS_DRAFT, $this->ticketId, self::LENA, self::LENAS_DRAFT_SCREENSHOT));
        $versionBefore = $this->version();

        $question = $this->refusalOf(fn () => $this->move(self::LENA, 'put_on_hold', null));

        self::assertInstanceOf(DraftDecisionNeeded::class, $question);
        self::assertSame([3, 2, null, true], [$question->draft->roundNumber, $question->draft->openRoundNumber, $question->draft->openRoundReadyForUserId, $question->draft->isSharingBlocked()]);

        $refusal = $this->refusalOf(fn () => $this->move(self::LENA, 'put_on_hold', DraftChoice::Share));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertSame('Round 2 is still open. Wait for its verdict before you share.', $refusal->ruleNoteTemplate);
        self::assertSame([self::TESTING_STEP, $versionBefore], [$this->stepId(), $this->version()]);
        self::assertSame(0, $this->movedRowCount());
        self::assertSame(
            [
                self::OMARS_HAND_OFF_ROUND => [2, self::OPEN, 'shared'],
                self::LENAS_DRAFT => [null, self::OPEN, null],
            ],
            $this->roundsOf($this->ticketId),
        );
    }

    private static function sharedRound(string $roundId, TicketId $ticketId, string $ownerId, int $number, RoundState $state, ?DraftDecision $decision): Round
    {
        return Round::restored(
            new RoundId($roundId),
            new OrganizationId(self::ACME_FOODS),
            $ticketId,
            RoundKind::Draft,
            new UserId($ownerId),
            $number,
            new \DateTimeImmutable(self::SHARED_AT),
            $state,
            1,
            [],
            new \DateTimeImmutable(self::STARTED_AT),
            null,
            $decision,
            null === $decision ? null : new \DateTimeImmutable(self::SHARED_AT),
        );
    }

    private static function draftWithAScreenshot(string $roundId, TicketId $ticketId, string $ownerId, string $screenshotId): Round
    {
        $draft = Round::draft(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), $ticketId, new UserId($ownerId), new \DateTimeImmutable(self::STARTED_AT));
        $draft->addScreenshot(new UserId($ownerId), new ScreenshotId($screenshotId), self::MEDIA);

        return $draft;
    }

    private function addRound(Round $round): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($round): void {
            new DbalRounds($this->connection(), $this->tenantContext())->add($round);
        });
    }

    private function move(string $actor, string $verb, ?DraftChoice $draftChoice = DraftChoice::Keep): void
    {
        $version = $this->version();
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId($actor),
            self::KEY,
            $verb,
            $version,
            new \DateTimeImmutable(self::MOVED_AT),
            $draftChoice,
        ));
    }

    private function moveTo(string $actor, string $stepId): void
    {
        $version = $this->version();
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->moveTo(
            new OrganizationId(self::ACME_FOODS),
            new UserId($actor),
            self::KEY,
            $stepId,
            $version,
            null,
            null,
            new \DateTimeImmutable(self::MOVED_AT),
        ));
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function refusalOf(\Closure $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $refusal) {
            self::getContainer()->get('doctrine')->resetManager();

            return $refusal;
        }

        return null;
    }

    private function assertOtherTicketsRoundIsOpen(): void
    {
        self::assertSame([self::OTHER_TICKETS_FINDINGS => [1, self::OPEN, 'shared']], $this->roundsOf($this->otherTicketId));
    }

    /**
     * @return array<string, array{int|null, string, string|null}> each round of the ticket by id: its number, state and
     *                                                             decision
     */
    private function roundsOf(TicketId $ticketId): array
    {
        $rows = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT id, number, state, decision FROM rvw_round WHERE ticket_id = ? ORDER BY id',
            [$ticketId->toString()],
        ));
        $rounds = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertTrue(null === $row['number'] || \is_int($row['number']));
            self::assertIsString($row['state']);
            self::assertTrue(null === $row['decision'] || \is_string($row['decision']));
            $rounds[$row['id']] = [$row['number'], $row['state'], $row['decision']];
        }

        return $rounds;
    }

    /**
     * @return list<mixed> when the round was shared and decided, in UTC
     */
    private function sharedAndDecidedAtOf(string $roundId): array
    {
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchNumeric(
            "SELECT to_char(shared_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(decided_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM rvw_round WHERE id = ?",
            [$roundId],
        ));
        self::assertIsArray($row);

        return $row;
    }

    private function stepId(): string
    {
        $stepId = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT step_id FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsString($stepId);

        return $stepId;
    }

    private function version(): int
    {
        $version = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT version FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsInt($version);

        return $version;
    }

    private function movedRowCount(): int
    {
        $count = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne(
            "SELECT count(*) FROM tkt_ticket_event WHERE type = 'moved' AND ticket_id = ?",
            [$this->ticketId->toString()],
        ));
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param array<string, string> $columns
     */
    private function setColumns(array $columns): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($columns)));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET '.$assignments.' WHERE key = ?',
            [...array_values($columns), self::KEY],
        ));
    }

    /**
     * The core steps past To do, and Design check, a checking step of the team's own, where it is the tester's turn.
     */
    private function addAcmeSteps(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static function (Connection $connection): void {
                foreach ([
                    [self::IN_PROGRESS_STEP, null, 'In progress', 'doing', 'progress', 'core', 0],
                    [self::CODE_REVIEW_STEP, null, 'Code review', 'check', 'review', 'core', 0],
                    [self::TESTING_STEP, null, 'Testing', 'check', 'test', 'core', 1],
                    [self::ON_HOLD_STEP, null, 'On hold', 'wait', 'hold', 'core', 1],
                    [self::DONE_STEP, null, 'Done', 'done', 'done', 'core', 0],
                    [self::DESIGN_CHECK_STEP, self::SHOP_TEAM, 'Design check', 'check', null, 'team', 5],
                ] as [$stepId, $teamId, $name, $groupCode, $coreCode, $origin, $position]) {
                    $connection->executeStatement(
                        'INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [self::ACME_FOODS, $stepId, $teamId, $name, $groupCode, $coreCode, $origin, $position],
                    );
                }
            });
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function removeRows(): void
    {
        new RoundRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS);
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function ticketCommands(): TicketCommands
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return $ticketCommands;
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
