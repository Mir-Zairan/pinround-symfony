<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRounds on the real rvw_round, rvw_screenshot and rvw_pin tables as pinround_app, under row-level security. Every
 * time is a fixed one in March 2027; nothing reads the clock. Each test commits its rounds, so a refusal is proven
 * against rows another transaction wrote, and removes them through the migrator.
 */
final class DbalRoundsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5101';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f5102';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5103';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f5104';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5111';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f5112';
    private const string THIRD_TICKET = '0197a3c0-0000-7000-8000-0000000f5113';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f5121';
    private const string OTHER_TICKET_REPORT = '0197a3c0-0000-7000-8000-0000000f5122';
    private const string OTHER_ORGANIZATION_REPORT = '0197a3c0-0000-7000-8000-0000000f5123';
    private const string REFUSED_ROUND = '0197a3c0-0000-7000-8000-0000000f5124';
    private const string THIRD_TICKET_REPORT = '0197a3c0-0000-7000-8000-0000000f5125';
    private const string OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000f5126';
    private const string LENA_DRAFT = '0197a3c0-0000-7000-8000-0000000f5127';
    private const string OMAR_OTHER_TICKET_DRAFT = '0197a3c0-0000-7000-8000-0000000f5128';
    private const string OMAR_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5129';
    private const string OMAR_THIRD_TICKET_DRAFT = '0197a3c0-0000-7000-8000-0000000f512a';
    private const string FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5131';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5132';
    private const string OTHER_TICKET_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5133';
    private const string OTHER_ORGANIZATION_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5134';
    private const string REFUSED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5135';
    private const string THIRD_TICKET_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5136';
    private const string FIRST_PIN = '0197a3c0-0000-7000-8000-0000000f5141';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-0000000f5142';
    private const string THIRD_PIN = '0197a3c0-0000-7000-8000-0000000f5143';
    private const string OTHER_TICKET_PIN = '0197a3c0-0000-7000-8000-0000000f5144';
    private const string OTHER_ORGANIZATION_PIN = '0197a3c0-0000-7000-8000-0000000f5145';
    private const string REFUSED_PIN = '0197a3c0-0000-7000-8000-0000000f5146';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000f5151';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000f5152';
    // An offset other than UTC, so a time written without its offset would read back an hour off.
    private const string REPORTED_AT = '2027-03-15 11:20:30+01:00';
    private const string REPORTED_AT_UTC = '2027-03-15 10:20:30';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function aReportRoundIsWrittenWithItsScreenshotsAndPinsAsTheyAre(): void
    {
        $this->addCommitted(self::otherOrganizationReport());
        $this->addCommitted(self::report());
        $this->addCommitted(self::otherTicketReport());

        self::assertSame(
            [[
                'id' => self::REPORT, 'ticket_id' => self::TICKET, 'number' => 0, 'kind' => 'report', 'owner_id' => self::LENA,
                'shared_at_utc' => self::REPORTED_AT_UTC, 'state' => 'open', 'version' => 1, 'created_at_utc' => self::REPORTED_AT_UTC,
            ]],
            $this->rowsAs(self::ACME_FOODS, "SELECT id, ticket_id, number, kind, owner_id, shared_at AT TIME ZONE 'UTC' AS shared_at_utc, state, version, created_at AT TIME ZONE 'UTC' AS created_at_utc FROM rvw_round WHERE ticket_id = ?", [self::TICKET]),
        );
        self::assertSame(
            [
                ['id' => self::FIRST_SCREENSHOT, 'media_id' => self::FIRST_MEDIA, 'position' => 0, 'variant_label' => null, 'verdict' => null, 'title' => 'Checkout — the total', 'summary' => null, 'created_at_utc' => self::REPORTED_AT_UTC],
                ['id' => self::SECOND_SCREENSHOT, 'media_id' => self::SECOND_MEDIA, 'position' => 1, 'variant_label' => null, 'verdict' => null, 'title' => null, 'summary' => 'The button sits under the footer on a small phone.', 'created_at_utc' => self::REPORTED_AT_UTC],
            ],
            $this->rowsAs(self::ACME_FOODS, "SELECT id, media_id, position, variant_label, verdict, title, summary, created_at AT TIME ZONE 'UTC' AS created_at_utc FROM rvw_screenshot WHERE round_id = ? ORDER BY position", [self::REPORT]),
        );
        self::assertSame(
            [
                ['id' => self::FIRST_PIN, 'screenshot_id' => self::FIRST_SCREENSHOT, 'number' => 1, 'x_pct' => '0.00', 'y_pct' => '100.00', 'note' => 'Prix affiché en €', 'verdict' => null, 'source' => 'human', 'ai_state' => null, 'author_id' => self::LENA, 'created_at_utc' => self::REPORTED_AT_UTC],
                ['id' => self::THIRD_PIN, 'screenshot_id' => self::FIRST_SCREENSHOT, 'number' => 2, 'x_pct' => '12.35', 'y_pct' => '7.10', 'note' => '', 'verdict' => null, 'source' => 'human', 'ai_state' => null, 'author_id' => self::LENA, 'created_at_utc' => self::REPORTED_AT_UTC],
                ['id' => self::SECOND_PIN, 'screenshot_id' => self::SECOND_SCREENSHOT, 'number' => 1, 'x_pct' => '99.99', 'y_pct' => '50.00', 'note' => 'Hidden here', 'verdict' => null, 'source' => 'human', 'ai_state' => null, 'author_id' => self::LENA, 'created_at_utc' => self::REPORTED_AT_UTC],
            ],
            $this->rowsAs(
                self::ACME_FOODS,
                "SELECT p.id, p.screenshot_id, p.number, p.x_pct, p.y_pct, p.note, p.verdict, p.source, p.ai_state, p.author_id, p.created_at AT TIME ZONE 'UTC' AS created_at_utc"
                .' FROM rvw_pin p JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id WHERE s.round_id = ? ORDER BY s.position, p.number',
                [self::REPORT],
            ),
        );
    }

    /**
     * The savepoint DbalRounds::add opens in the caller's transaction: a round refused by the database halfway, after
     * its round row went in, leaves none of itself, and the caller's transaction goes on and commits the rounds
     * around it. Without the savepoint the refusal would abort the whole transaction.
     */
    #[Test]
    public function aRoundTheDatabaseRefusesHalfwayLeavesNothingAndTheCallersTransactionGoesOn(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\Throwable {
            $this->rounds()->add(self::report());
            $nestingLevel = $this->connection()->getTransactionNestingLevel();
            try {
                // Its screenshot reuses the report's first screenshot id, so its rvw_screenshot insert fails on the key.
                $this->rounds()->add(self::reportOf(self::REFUSED_ROUND, self::OTHER_TICKET, [self::screenshot(self::FIRST_SCREENSHOT, 0, [self::pin(self::REFUSED_PIN, 1)])]));
            } catch (UniqueConstraintViolationException $refusal) {
                self::assertSame($nestingLevel, $this->connection()->getTransactionNestingLevel());
                $this->rounds()->add(self::reportOf(self::THIRD_TICKET_REPORT, self::THIRD_TICKET, [self::screenshot(self::THIRD_TICKET_SCREENSHOT, 0, [])]));

                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(UniqueConstraintViolationException::class, $refusal, 'The second round was not refused, so nothing below is proven.');
        self::assertStringContainsString('rvw_screenshot_pkey', $refusal->getMessage());
        self::assertSame(
            [[self::REPORT, self::TICKET], [self::THIRD_TICKET_REPORT, self::THIRD_TICKET]],
            $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, ticket_id FROM rvw_round ORDER BY id'),
        );
        self::assertSame(
            [[self::FIRST_SCREENSHOT, self::REPORT], [self::SECOND_SCREENSHOT, self::REPORT], [self::THIRD_TICKET_SCREENSHOT, self::THIRD_TICKET_REPORT]],
            $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, round_id FROM rvw_screenshot ORDER BY id'),
        );
        self::assertSame([self::FIRST_PIN, self::SECOND_PIN, self::THIRD_PIN], $this->idsAs(self::ACME_FOODS, 'rvw_pin'));
    }

    /**
     * A second Round 0 of a ticket takes no row: ON CONFLICT DO NOTHING finds the number slot taken without an error,
     * so the caller's transaction stays usable, and none of the round's screenshots or pins are written.
     */
    #[Test]
    public function aSecondReportOfATicketIsRefusedAsRoundSlotTakenAndWritesNothing(): void
    {
        $this->addCommitted(self::report());

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?RoundSlotTaken {
            try {
                $this->rounds()->add(self::reportOf(self::REFUSED_ROUND, self::TICKET, [self::screenshot(self::REFUSED_SCREENSHOT, 0, [self::pin(self::REFUSED_PIN, 1)])]));
            } catch (RoundSlotTaken $refusal) {
                $this->rounds()->add(self::otherTicketReport());

                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(RoundSlotTaken::class, $refusal);
        self::assertSame('The ticket has this round already.', $refusal->getMessage());
        self::assertSame([self::REPORT, self::OTHER_TICKET_REPORT], $this->idsAs(self::ACME_FOODS, 'rvw_round'));
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT, self::OTHER_TICKET_SCREENSHOT], $this->idsAs(self::ACME_FOODS, 'rvw_screenshot'));
        self::assertSame([self::FIRST_PIN, self::SECOND_PIN, self::THIRD_PIN, self::OTHER_TICKET_PIN], $this->idsAs(self::ACME_FOODS, 'rvw_pin'));
    }

    /**
     * A round id met again, the identical round or the id on a free slot, is a programming error and not a ticket's
     * slot: the primary key clash is told apart from the slot ones, writes nothing and leaves the transaction usable.
     */
    #[Test]
    public function aRoundAddedAgainIsRefusedAsAProgrammingErrorNotAsRoundSlotTaken(): void
    {
        $this->addCommitted(self::report());

        $refusals = $this->transactions()->committedAs(self::ACME_FOODS, function (): array {
            $refusals = [];
            foreach ([self::report(), self::reportOf(self::REPORT, self::THIRD_TICKET, [self::screenshot(self::REFUSED_SCREENSHOT, 0, [self::pin(self::REFUSED_PIN, 1)])])] as $roundAddedAgain) {
                try {
                    $this->rounds()->add($roundAddedAgain);
                } catch (\LogicException $refusal) {
                    $refusals[] = [$refusal::class, $refusal->getMessage()];
                }
            }
            $this->rounds()->add(self::otherTicketReport());

            return $refusals;
        });

        $expectedRefusal = [\LogicException::class, 'Round '.self::REPORT.' is added already; a round is added once.'];
        self::assertSame([$expectedRefusal, $expectedRefusal], $refusals);
        self::assertSame([[self::REPORT, self::TICKET], [self::OTHER_TICKET_REPORT, self::OTHER_TICKET]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, ticket_id FROM rvw_round ORDER BY id'));
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT, self::OTHER_TICKET_SCREENSHOT], $this->idsAs(self::ACME_FOODS, 'rvw_screenshot'));
        self::assertSame([self::FIRST_PIN, self::SECOND_PIN, self::THIRD_PIN, self::OTHER_TICKET_PIN], $this->idsAs(self::ACME_FOODS, 'rvw_pin'));
    }

    #[Test]
    public function aSecondOpenDraftOfAnOwnerOnATicketIsRefusedAsRoundSlotTaken(): void
    {
        $this->addCommitted(self::draft(self::OMAR_DRAFT, self::TICKET, self::OMAR));
        $this->addCommitted(self::draft(self::LENA_DRAFT, self::TICKET, self::LENA));
        $this->addCommitted(self::draft(self::OMAR_OTHER_TICKET_DRAFT, self::OTHER_TICKET, self::OMAR));

        $refusal = $this->refusalOfAddingBefore(self::draft(self::REFUSED_ROUND, self::TICKET, self::OMAR), self::draft(self::OMAR_THIRD_TICKET_DRAFT, self::THIRD_TICKET, self::OMAR));

        self::assertInstanceOf(RoundSlotTaken::class, $refusal);
        self::assertSame('The ticket has this round already.', $refusal->getMessage());
        self::assertSame(
            [
                [self::OMAR_DRAFT, self::TICKET, self::OMAR], [self::LENA_DRAFT, self::TICKET, self::LENA],
                [self::OMAR_OTHER_TICKET_DRAFT, self::OTHER_TICKET, self::OMAR], [self::OMAR_THIRD_TICKET_DRAFT, self::THIRD_TICKET, self::OMAR],
            ],
            $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, ticket_id, owner_id FROM rvw_round ORDER BY id'),
        );
    }

    /**
     * A shared draft leaves the one-open-draft slot, so its owner may start another, and takes the number slot, so a
     * second round with its number is refused.
     */
    #[Test]
    public function aSharedDraftFreesTheOpenDraftSlotAndTakesItsNumber(): void
    {
        $sharedDraft = self::draft(self::OMAR_SHARED_DRAFT, self::TICKET, self::OMAR);
        $sharedDraft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2027-03-16 09:00:00+00:00'));
        $this->addCommitted($sharedDraft);
        $this->addCommitted(self::draft(self::OMAR_DRAFT, self::TICKET, self::OMAR));
        $secondRoundOne = self::draft(self::REFUSED_ROUND, self::TICKET, self::LENA);
        $secondRoundOne->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2027-03-16 09:05:00+00:00'));

        $refusal = $this->refusalOfAddingBefore($secondRoundOne, self::draft(self::LENA_DRAFT, self::TICKET, self::LENA));

        self::assertInstanceOf(RoundSlotTaken::class, $refusal);
        self::assertSame('The ticket has this round already.', $refusal->getMessage());
        self::assertSame(
            [[self::OMAR_DRAFT, null], [self::LENA_DRAFT, null], [self::OMAR_SHARED_DRAFT, 1]],
            $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, number FROM rvw_round ORDER BY id'),
        );
    }

    #[Test]
    public function anotherOrganizationSeesNoneOfTheRowsAndNoTenantSeesNothing(): void
    {
        $this->addCommitted(self::report());
        $this->addCommitted(self::otherOrganizationReport());
        $countsOfAcmeFoods = 'SELECT (SELECT count(*) FROM rvw_round WHERE organization_id = ?), (SELECT count(*) FROM rvw_screenshot WHERE organization_id = ?), (SELECT count(*) FROM rvw_pin WHERE organization_id = ?)';

        self::assertSame([[1, 2, 3]], $this->numericRowsAs(self::ACME_FOODS, $countsOfAcmeFoods, [self::ACME_FOODS, self::ACME_FOODS, self::ACME_FOODS]));
        self::assertSame([[0, 0, 0]], $this->numericRowsAs(self::OTHER_ORGANIZATION, $countsOfAcmeFoods, [self::ACME_FOODS, self::ACME_FOODS, self::ACME_FOODS]));
        self::assertSame([self::OTHER_ORGANIZATION_REPORT], $this->idsAs(self::OTHER_ORGANIZATION, 'rvw_round'));
        self::assertSame([0, 0, 0], $this->connection()->fetchNumeric('SELECT (SELECT count(*) FROM rvw_round), (SELECT count(*) FROM rvw_screenshot), (SELECT count(*) FROM rvw_pin)'));
    }

    #[Test]
    public function aRoundInTheNameOfAnotherOrganizationIsRefusedByTheTenantPolicy(): void
    {
        try {
            $this->addCommittedAs(self::OTHER_ORGANIZATION, self::report());
            self::fail('Row-level security did not refuse a round of another organization.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "rvw_round"', $refusal->getMessage());
        }

        self::assertSame([], $this->idsAs(self::ACME_FOODS, 'rvw_round'));
        self::assertSame([], $this->idsAs(self::OTHER_ORGANIZATION, 'rvw_round'));
    }

    #[Test]
    public function addingWithoutAnOpenTransactionIsRefusedAndWritesNothing(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->rounds()->add(self::report());
            self::fail('A round was added outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A round is added in a transaction under its organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            $tenantContext->clear();
        }

        self::assertSame([], $this->idsAs(self::ACME_FOODS, 'rvw_round'));
    }

    private static function report(): Round
    {
        // Screenshots and pins out of order, so the stored positions and numbers come from them, not from the order.
        return self::reportOf(self::REPORT, self::TICKET, [
            Screenshot::of(new ScreenshotId(self::SECOND_SCREENSHOT), strtoupper(self::SECOND_MEDIA), 1, '  ', ' The button sits under the footer on a small phone. ', [
                Pin::placedBy(new PinId(self::SECOND_PIN), 1, 99.99, 50.0, 'Hidden here', new UserId(self::LENA)),
            ]),
            Screenshot::of(new ScreenshotId(self::FIRST_SCREENSHOT), self::FIRST_MEDIA, 0, 'Checkout — the total', null, [
                Pin::placedBy(new PinId(self::THIRD_PIN), 2, 12.349, 7.1, "\r\n", new UserId(self::LENA)),
                Pin::placedBy(new PinId(self::FIRST_PIN), 1, 0.0, 100.0, 'Prix affiché en €', new UserId(self::LENA)),
            ]),
        ]);
    }

    private static function otherTicketReport(): Round
    {
        return self::reportOf(self::OTHER_TICKET_REPORT, self::OTHER_TICKET, [self::screenshot(self::OTHER_TICKET_SCREENSHOT, 0, [self::pin(self::OTHER_TICKET_PIN, 1)])]);
    }

    private static function otherOrganizationReport(): Round
    {
        return Round::report(new RoundId(self::OTHER_ORGANIZATION_REPORT), new OrganizationId(self::OTHER_ORGANIZATION), new TicketId(self::TICKET), new UserId(self::OMAR), [
            self::screenshot(self::OTHER_ORGANIZATION_SCREENSHOT, 0, [self::pin(self::OTHER_ORGANIZATION_PIN, 1)]),
        ], new \DateTimeImmutable(self::REPORTED_AT));
    }

    /**
     * @param list<Screenshot> $screenshots
     */
    private static function reportOf(string $roundId, string $ticketId, array $screenshots): Round
    {
        return Round::report(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId(self::LENA), $screenshots, new \DateTimeImmutable(self::REPORTED_AT));
    }

    private static function draft(string $roundId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId($ownerId), new \DateTimeImmutable('2027-03-16 08:00:00+00:00'));
    }

    /**
     * @param list<Pin> $pins
     */
    private static function screenshot(string $screenshotId, int $position, array $pins): Screenshot
    {
        return Screenshot::of(new ScreenshotId($screenshotId), self::FIRST_MEDIA, $position, null, null, $pins);
    }

    private static function pin(string $pinId, int $number): Pin
    {
        return Pin::placedBy(new PinId($pinId), $number, 40.0, 60.0, 'Looks off', new UserId(self::LENA));
    }

    private function addCommitted(Round $round): void
    {
        $this->addCommittedAs($round->organizationId()->toString(), $round);
    }

    private function addCommittedAs(string $organizationId, Round $round): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($round): void {
            $this->rounds()->add($round);
        });
    }

    /**
     * Commits, so the rows read afterwards prove the refused round left nothing and the transaction went on to add the
     * next one.
     */
    private function refusalOfAddingBefore(Round $refusedRound, Round $nextRound): ?RoundSlotTaken
    {
        return $this->transactions()->committedAs($refusedRound->organizationId()->toString(), function () use ($refusedRound, $nextRound): ?RoundSlotTaken {
            try {
                $this->rounds()->add($refusedRound);
            } catch (RoundSlotTaken $refusal) {
                $this->rounds()->add($nextRound);

                return $refusal;
            }

            return null;
        });
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rowsAs(string $organizationId, string $sql, array $parameters): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllAssociative($sql, $parameters));
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<list<mixed>>
     */
    private function numericRowsAs(string $organizationId, string $sql, array $parameters = []): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric($sql, $parameters));
    }

    /**
     * @return list<mixed>
     */
    private function idsAs(string $organizationId, string $table): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)));
    }

    private function rounds(): DbalRounds
    {
        return new DbalRounds($this->connection());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
