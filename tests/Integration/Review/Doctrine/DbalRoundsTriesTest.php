<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Domain\AreaId;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\RetakenScreenshotNotFound;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotTriesFull;
use App\Review\Domain\ScreenshotTry;
use App\Review\Infrastructure\Doctrine\DbalAreas;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tries of a screenshot (ADR-0048) through DbalRounds on the real tables as pinround_app, under row-level security:
 * rvw_screenshot.retake_of is written, read back and moved when a first try goes, and a retake of a screenshot outside
 * the draft is refused by the domain and by the database. Lena's draft has two screenshots, the first with a pin; beside
 * it are Omar's draft and Lena's report on the same ticket, and another organization's draft of Lena's on the same
 * ticket id, the rows a wrong filter would reach. Every time is a fixed one in April 2027.
 */
final class DbalRoundsTriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000014a001';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-00000014a002';
    private const string LENA = '0197a3c0-0000-7000-8000-00000014a003';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000014a004';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000014a011';

    private const string DRAFT = '0197a3c0-0000-7000-8000-00000014a021';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-00000014a022';
    private const string REPORT = '0197a3c0-0000-7000-8000-00000014a023';
    private const string OTHER_ORGANIZATION_DRAFT = '0197a3c0-0000-7000-8000-00000014a024';

    private const string CHECKOUT = '0197a3c0-0000-7000-8000-00000014a031';
    private const string BASKET = '0197a3c0-0000-7000-8000-00000014a032';
    private const string OMARS_SCREENSHOT = '0197a3c0-0000-7000-8000-00000014a033';
    private const string REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-00000014a034';
    private const string OTHER_ORGANIZATION_SCREENSHOT = '0197a3c0-0000-7000-8000-00000014a035';
    private const string CHECKOUT_PIN = '0197a3c0-0000-7000-8000-00000014a041';
    private const string LATEST_TRY_PIN = '0197a3c0-0000-7000-8000-00000014a042';
    private const string REVIEW_ROUND = '0197a3c0-0000-7000-8000-00000014a025';
    private const string REVIEW_SCREENSHOT = '0197a3c0-0000-7000-8000-00000014a036';
    private const string AREA = '0197a3c0-0000-7000-8000-00000014a061';
    private const string MEDIA = '0197a3c0-0000-7000-8000-00000014a051';

    private const string STARTED_AT = '2027-04-06 09:00:00+00:00';
    private const string SAVED_AT = '2027-04-07 10:30:00+00:00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->seedRounds();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function aRetakeIsStoredPointingAtItsChainsFirstTryAndReadBackAsItsNextTry(): void
    {
        $otherRoundsBefore = $this->rowsOfOtherRounds();

        $this->changeDraft(static function (Round $draft): void {
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(1), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(2), self::MEDIA, self::tryId(1));
        });

        self::assertSame(
            [[self::CHECKOUT, null], [self::BASKET, null], [self::tryId(1)->toString(), self::CHECKOUT], [self::tryId(2)->toString(), self::CHECKOUT]],
            $this->chainRowsOfDraft(),
        );
        $draft = $this->readDraft();
        self::assertSame(
            [[self::CHECKOUT, 1, 3], [self::BASKET, 1, 1], [self::CHECKOUT, 2, 3], [self::CHECKOUT, 3, 3]],
            array_map(static fn (ScreenshotTry $try): array => [$try->firstTryId->toString(), $try->number, $try->count], $draft->tries()),
        );
        self::assertSame([self::CHECKOUT_PIN], array_map(static fn (Pin $pin): string => $pin->id()->toString(), $draft->screenshots()[0]->pins()), 'The try-1 pin stays on try 1.');
        self::assertSame([], $draft->screenshots()[2]->pins());
        self::assertSame($otherRoundsBefore, $this->rowsOfOtherRounds());
    }

    #[Test]
    public function readingADraftWithTriesTakesTheSameFixedQueriesAsReadingItWithout(): void
    {
        $queriesWithoutTries = $this->queriesToReadTheDraftAndItsTries();
        $this->changeDraft(static function (Round $draft): void {
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(1), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(2), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(3), self::MEDIA, new ScreenshotId(self::BASKET));
        });

        self::assertSame([3, 3], [$queriesWithoutTries, $this->queriesToReadTheDraftAndItsTries()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screenshotsOutsideTheDraft(): iterable
    {
        yield 'another person\'s draft on the ticket' => [self::OMARS_SCREENSHOT];
        yield 'another round of the same person' => [self::REPORT_SCREENSHOT];
        yield 'another organization\'s draft' => [self::OTHER_ORGANIZATION_SCREENSHOT];
        yield 'no screenshot at all' => ['0197a3c0-0000-7000-8000-00000014a099'];
    }

    #[Test]
    #[DataProvider('screenshotsOutsideTheDraft')]
    public function aRetakeOfAScreenshotOutsideTheDraftIsRefusedAsNotFoundAndNothingIsStored(string $screenshotId): void
    {
        $rowsBefore = [$this->chainRowsOfDraft(), $this->rowsOfOtherRounds()];

        $refusal = $this->committed(self::ACME_FOODS, function () use ($screenshotId): ?RetakenScreenshotNotFound {
            $draft = $this->storedDraft();
            try {
                $draft->addScreenshot(new UserId(self::LENA), self::tryId(1), self::MEDIA, new ScreenshotId($screenshotId));
            } catch (RetakenScreenshotNotFound $refused) {
                return $refused;
            }
            $this->rounds()->save($draft, self::savedAt());

            return null;
        });

        self::assertSame(RetakenScreenshotNotFound::REASON, $refusal?->getMessage());
        self::assertSame($rowsBefore, [$this->chainRowsOfDraft(), $this->rowsOfOtherRounds()]);
    }

    /**
     * Past the domain, as pinround_app under Acme Foods: the foreign key names the organization and the round, so no
     * row of another round or organization can be a first try, even one whose id is known.
     */
    #[Test]
    #[DataProvider('screenshotsOutsideTheDraft')]
    public function theDatabaseRefusesARetakeOfAScreenshotOutsideTheRound(string $screenshotId): void
    {
        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($screenshotId): ?ForeignKeyConstraintViolationException {
            try {
                $this->connection()->executeStatement(
                    'INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, created_at, retake_of) VALUES (?, ?, ?, ?, 2, ?, ?)',
                    [self::ACME_FOODS, self::tryId(1)->toString(), self::DRAFT, self::MEDIA, self::SAVED_AT, $screenshotId],
                );
            } catch (ForeignKeyConstraintViolationException $refused) {
                return $refused;
            }

            return null;
        });

        self::assertNotNull($refusal);
        self::assertStringContainsString('rvw_screenshot_retake_of_fkey', $refusal->getMessage());
        self::assertSame([[self::CHECKOUT, null], [self::BASKET, null]], $this->chainRowsOfDraft());
    }

    #[Test]
    public function aSixthTryIsRefusedWithTheCapMessageAndNothingIsStored(): void
    {
        $this->changeDraft(static function (Round $draft): void {
            for ($try = 1; $try < Screenshot::MAX_TRIES; ++$try) {
                $draft->addScreenshot(new UserId(self::LENA), self::tryId($try), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            }
        });
        $rowsBefore = $this->chainRowsOfDraft();
        self::assertCount(6, $rowsBefore, 'The draft holds five tries of Checkout and Basket.');

        $refusal = $this->committed(self::ACME_FOODS, function (): ?ScreenshotTriesFull {
            $draft = $this->storedDraft();
            try {
                $draft->addScreenshot(new UserId(self::LENA), self::tryId(9), self::MEDIA, self::tryId(4));
            } catch (ScreenshotTriesFull $refused) {
                return $refused;
            }
            $this->rounds()->save($draft, self::savedAt());

            return null;
        });

        self::assertSame('This screenshot already has 5 tries. Remove one before you add another.', $refusal?->getMessage());
        self::assertSame($rowsBefore, $this->chainRowsOfDraft());
    }

    /**
     * The cap is checked on the round read, and the save writes only over the version read, so of two requests that
     * both read four tries and add a fifth, the second is refused instead of storing a sixth.
     */
    #[Test]
    public function twoSavesFromOneReadCannotBothAddATryPastTheCap(): void
    {
        $this->changeDraft(static function (Round $draft): void {
            for ($try = 1; $try < Screenshot::MAX_TRIES - 1; ++$try) {
                $draft->addScreenshot(new UserId(self::LENA), self::tryId($try), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            }
        });
        $first = $this->readDraft();
        $second = $this->readDraft();
        $first->addScreenshot(new UserId(self::LENA), self::tryId(5), self::MEDIA, new ScreenshotId(self::CHECKOUT));
        $second->addScreenshot(new UserId(self::LENA), self::tryId(6), self::MEDIA, new ScreenshotId(self::CHECKOUT));
        $this->committed(self::ACME_FOODS, function () use ($first): void {
            $this->rounds()->save($first, self::savedAt());
        });

        $refusal = $this->committed(self::ACME_FOODS, function () use ($second): ?RoundChanged {
            try {
                $this->rounds()->save($second, self::savedAt());
            } catch (RoundChanged $changed) {
                return $changed;
            }

            return null;
        });

        self::assertNotNull($refusal);
        self::assertSame(
            [self::CHECKOUT, self::tryId(1)->toString(), self::tryId(2)->toString(), self::tryId(3)->toString(), self::tryId(5)->toString()],
            array_values(array_map(static fn (array $row): ?string => $row[0], array_filter($this->chainRowsOfDraft(), static fn (array $row): bool => self::BASKET !== $row[0]))),
        );
    }

    #[Test]
    public function removingAStoredFirstTrySavesTheChainUnderTheNextTry(): void
    {
        $this->changeDraft(static function (Round $draft): void {
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(1), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(2), self::MEDIA, new ScreenshotId(self::CHECKOUT));
        });

        $this->changeDraft(static function (Round $draft): void {
            $draft->removeScreenshot(new UserId(self::LENA), new ScreenshotId(self::CHECKOUT));
            $draft->addScreenshot(new UserId(self::LENA), self::tryId(3), self::MEDIA, self::tryId(2));
        });

        self::assertSame(
            [[self::BASKET, null], [self::tryId(1)->toString(), null], [self::tryId(2)->toString(), self::tryId(1)->toString()], [self::tryId(3)->toString(), self::tryId(1)->toString()]],
            $this->chainRowsOfDraft(),
        );
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM rvw_pin WHERE id = ?', [self::CHECKOUT_PIN])), 'The pin went with its try.');
    }

    /**
     * Rounds::save writes Round::share's latest tries: try 2 is unhooked from Checkout before Checkout and try 1 go in
     * one statement, so the foreign key holds, and the try-1 pin goes with its try.
     */
    #[Test]
    public function aSharedDraftKeepsOnlyTheLatestTryOfEachChainAndTheEarlierTriesGoWithTheirPins(): void
    {
        $otherRoundsBefore = $this->rowsOfOtherRounds();
        $this->addTwoTriesOfCheckoutWithAPinOnTheLatest();
        self::assertCount(4, $this->chainRowsOfDraft());

        $this->changeDraft(static function (Round $draft): void {
            $draft->share(new UserId(self::LENA), 2, self::savedAt());
        });

        self::assertSame([[self::BASKET, null], [self::tryId(2)->toString(), null]], $this->chainRowsOfDraft());
        self::assertSame([self::LATEST_TRY_PIN], $this->pinIdsOf([self::CHECKOUT_PIN, self::LATEST_TRY_PIN]));
        self::assertSame(
            [self::BASKET, self::tryId(2)->toString()],
            array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $this->readDraft()->screenshots()),
        );
        self::assertSame($otherRoundsBefore, $this->rowsOfOtherRounds());
    }

    /**
     * ADR-0048: the next round's "before" is the earlier round's "after". After the share, Round 2 holds only Checkout's
     * try 2, so a Round 3 area compares with it and checks its pin, and an earlier try is no row a "before" could name.
     */
    #[Test]
    public function aLaterRoundsBeforeIsTheSharedRoundsLatestTryAndNeverAnEarlierOne(): void
    {
        $this->addTwoTriesOfCheckoutWithAPinOnTheLatest();
        $this->changeDraft(static function (Round $draft): void {
            $draft->share(new UserId(self::LENA), 2, self::savedAt());
        });

        try {
            $this->addReviewRoundThreeComparingWith(self::tryId(1)->toString());
            self::fail('Try 1 is still stored, so a "before" could show it.');
        } catch (ForeignKeyConstraintViolationException $refused) {
            self::assertStringContainsString('rvw_area_before_screenshot_fkey', $refused->getMessage());
        }

        $this->addReviewRoundThreeComparingWith(self::tryId(2)->toString());

        [$area, $pinToCheck, $beforeRoundNumber] = $this->committed(self::ACME_FOODS, fn (): array => [
            $this->areas()->areaOf(new RoundId(self::REVIEW_ROUND), new AreaId(self::AREA)),
            $this->areas()->pinToCheckOf(new RoundId(self::REVIEW_ROUND), new PinId(self::LATEST_TRY_PIN)),
            $this->connection()->fetchOne('SELECT round.number FROM rvw_screenshot AS screenshot JOIN rvw_round AS round ON round.id = screenshot.round_id WHERE screenshot.id = ?', [self::tryId(2)->toString()]),
        ]);
        self::assertSame(self::tryId(2)->toString(), $area?->beforeScreenshotId()?->toString());
        self::assertSame(2, $beforeRoundNumber);
        self::assertSame(self::LATEST_TRY_PIN, $pinToCheck?->id()->toString());
    }

    private function addTwoTriesOfCheckoutWithAPinOnTheLatest(): void
    {
        $this->changeDraft(static function (Round $draft): void {
            $lena = new UserId(self::LENA);
            $draft->addScreenshot($lena, self::tryId(1), self::MEDIA, new ScreenshotId(self::CHECKOUT));
            $draft->addScreenshot($lena, self::tryId(2), self::MEDIA, self::tryId(1));
            $draft->replacePins($lena, self::tryId(2), [Pin::placedBy(new PinId(self::LATEST_TRY_PIN), 1, 50.0, 60.0, 'Total still cut off', $lena)]);
        });
    }

    /**
     * Review round 3 of the ticket with one area whose "after" is a new picture and whose "before" is $beforeScreenshotId;
     * no command writes a review round yet, so its rows are added as the seed command adds them.
     */
    private function addReviewRoundThreeComparingWith(string $beforeScreenshotId): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            ['rvw_round', ['organization_id' => self::ACME_FOODS, 'id' => self::REVIEW_ROUND, 'ticket_id' => self::TICKET, 'number' => 3, 'kind' => 'review', 'owner_id' => self::LENA, 'shared_at' => self::SAVED_AT, 'state' => 'open', 'version' => 1, 'created_at' => self::SAVED_AT]],
            ['rvw_screenshot', ['organization_id' => self::ACME_FOODS, 'id' => self::REVIEW_SCREENSHOT, 'round_id' => self::REVIEW_ROUND, 'media_id' => self::MEDIA, 'position' => 0, 'created_at' => self::SAVED_AT]],
            ['rvw_area', ['organization_id' => self::ACME_FOODS, 'id' => self::AREA, 'round_id' => self::REVIEW_ROUND, 'name' => 'Checkout', 'position' => 0, 'before_screenshot_id' => $beforeScreenshotId, 'after_screenshot_id' => self::REVIEW_SCREENSHOT, 'created_at' => self::SAVED_AT]],
        ]);
    }

    /**
     * @param list<string> $pinIds
     *
     * @return list<string> those of $pinIds that are stored, in id order
     */
    private function pinIdsOf(array $pinIds): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_map(
            static fn (mixed $id): string => \is_string($id) ? $id : '',
            $this->connection()->fetchFirstColumn('SELECT id FROM rvw_pin WHERE id = ANY(CAST(? AS uuid[])) ORDER BY id', ['{'.implode(',', $pinIds).'}']),
        ));
    }

    private function areas(): DbalAreas
    {
        return new DbalAreas($this->connection(), self::getContainer()->get(TenantContext::class));
    }

    private function seedRounds(): void
    {
        $lena = new UserId(self::LENA);
        $draft = self::draftOf(self::DRAFT, self::ACME_FOODS, self::LENA);
        $draft->addScreenshot($lena, new ScreenshotId(self::CHECKOUT), self::MEDIA);
        $draft->replacePins($lena, new ScreenshotId(self::CHECKOUT), [Pin::placedBy(new PinId(self::CHECKOUT_PIN), 1, 30.0, 40.0, 'Total is cut off', $lena)]);
        $draft->addScreenshot($lena, new ScreenshotId(self::BASKET), self::MEDIA);
        $this->addCommitted($draft);

        $omarsDraft = self::draftOf(self::OMARS_DRAFT, self::ACME_FOODS, self::OMAR);
        $omarsDraft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::OMARS_SCREENSHOT), self::MEDIA);
        $this->addCommitted($omarsDraft);

        $this->addCommitted(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $lena, [
            Screenshot::of(new ScreenshotId(self::REPORT_SCREENSHOT), self::MEDIA, 0, null, null, []),
        ], new \DateTimeImmutable(self::STARTED_AT)));

        $elsewhere = self::draftOf(self::OTHER_ORGANIZATION_DRAFT, self::OTHER_ORGANIZATION, self::LENA);
        $elsewhere->addScreenshot($lena, new ScreenshotId(self::OTHER_ORGANIZATION_SCREENSHOT), self::MEDIA);
        $this->addCommitted($elsewhere);
    }

    private static function draftOf(string $roundId, string $organizationId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId($organizationId), new TicketId(self::TICKET), new UserId($ownerId), new \DateTimeImmutable(self::STARTED_AT));
    }

    private static function tryId(int $try): ScreenshotId
    {
        return new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-00000014b%03x', $try));
    }

    private static function savedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::SAVED_AT);
    }

    /**
     * @param \Closure(Round): void $change
     */
    private function changeDraft(\Closure $change): void
    {
        $this->committed(self::ACME_FOODS, function () use ($change): void {
            $draft = $this->storedDraft();
            $change($draft);
            $this->rounds()->save($draft, self::savedAt());
        });
    }

    private function queriesToReadTheDraftAndItsTries(): int
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            self::assertNotSame([], $this->storedDraft()->tries());

            return self::queryCountOf($this->debugData());
        });
    }

    private function addCommitted(Round $round): void
    {
        $this->committed($round->organizationId()->toString(), function () use ($round): void {
            $this->rounds()->add($round);
        });
    }

    private function readDraft(): Round
    {
        return $this->committed(self::ACME_FOODS, fn (): Round => $this->storedDraft());
    }

    /**
     * Inside the caller's transaction.
     */
    private function storedDraft(): Round
    {
        return $this->rounds()->get(new RoundId(self::DRAFT)) ?? throw new \LogicException('The seeded draft is missing.');
    }

    /**
     * @return list<array{?string, ?string}> each screenshot of Lena's draft as [id, retake_of], by position
     */
    private function chainRowsOfDraft(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_map(
            static fn (array $row): array => [\is_string($row['id']) ? $row['id'] : null, \is_string($row['retake_of']) ? $row['retake_of'] : null],
            $this->connection()->fetchAllAssociative('SELECT id, retake_of FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [self::DRAFT]),
        ));
    }

    /**
     * Every screenshot and pin of every round but Lena's draft, of both organizations, as JSON text.
     *
     * @return list<list<mixed>>
     */
    private function rowsOfOtherRounds(): array
    {
        $rows = [];
        foreach ([self::ACME_FOODS, self::OTHER_ORGANIZATION] as $organizationId) {
            $rows[] = $this->transactions()->rolledBackAs($organizationId, fn (): array => [
                ...$this->connection()->fetchFirstColumn('SELECT to_jsonb(s)::text FROM rvw_screenshot s WHERE round_id <> ? ORDER BY id', [self::DRAFT]),
                ...$this->connection()->fetchFirstColumn('SELECT to_jsonb(r)::text FROM rvw_round r WHERE id <> ? ORDER BY id', [self::DRAFT]),
            ]);
        }
        self::assertNotSame([], $rows[0], 'The other rounds were not seeded, so nothing below is proven.');
        self::assertNotSame([], $rows[1]);

        return $rows;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function committed(string $organizationId, \Closure $work): mixed
    {
        return $this->transactions()->committedAs($organizationId, $work);
    }

    private function rounds(): DbalRounds
    {
        return new DbalRounds($this->connection(), self::getContainer()->get(TenantContext::class));
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
