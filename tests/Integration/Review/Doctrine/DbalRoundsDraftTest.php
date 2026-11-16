<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\RoundRefused;
use App\Review\Application\ReportPictures;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Review\Application\InMemoryUploadedMedia;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read and write side of draft rounds in DbalRounds (get, openUnsharedDraftOf, nextFreeNumberOf, save) on the real
 * tables as pinround_app, under row-level security. Each test commits its rows, so a refusal is proven against rows
 * another transaction wrote, and removes them through the migrator. Every time is a fixed one in March 2027.
 *
 * Besides the draft the tests change, every test seeds rows a wrong filter or a wrong WHERE would touch: another
 * person's draft, another ticket's draft, a report, a shared draft, a deleted draft and another organization's rounds
 * on the same ticket id.
 */
final class DbalRoundsDraftTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6101';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f6102';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f6103';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6104';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6105';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f6111';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f6112';
    private const string EMPTY_TICKET = '0197a3c0-0000-7000-8000-0000000f6113';

    private const string DRAFT = '0197a3c0-0000-7000-8000-0000000f6121';
    private const string LENAS_DELETED_DRAFT = '0197a3c0-0000-7000-8000-0000000f6122';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-0000000f6123';
    private const string MIAS_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f6124';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f6125';
    private const string LENAS_OTHER_TICKET_DRAFT = '0197a3c0-0000-7000-8000-0000000f6126';
    private const string OTHER_ORGANIZATION_OPEN_DRAFT = '0197a3c0-0000-7000-8000-0000000f6127';
    private const string OTHER_ORGANIZATION_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f6128';
    private const string LENAS_NEW_DRAFT = '0197a3c0-0000-7000-8000-0000000f6129';
    private const string UNKNOWN_ROUND = '0197a3c0-0000-7000-8000-0000000f6199';

    private const string FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6131';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6132';
    private const string THIRD_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6133';
    private const string OMARS_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6134';
    private const string MIAS_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6135';
    private const string REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6136';
    private const string OTHER_TICKET_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6137';
    private const string OTHER_ORGANIZATION_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f6138';

    private const string FIRST_PIN = '0197a3c0-0000-7000-8000-0000000f6141';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-0000000f6142';
    private const string THIRD_PIN = '0197a3c0-0000-7000-8000-0000000f6143';
    private const string OMARS_PIN = '0197a3c0-0000-7000-8000-0000000f6144';
    private const string MIAS_PIN = '0197a3c0-0000-7000-8000-0000000f6145';
    private const string OTHER_ORGANIZATION_PIN = '0197a3c0-0000-7000-8000-0000000f6146';

    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000f6151';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000f6152';

    // An offset other than UTC, so a time written without its offset would read back hours off.
    private const string STARTED_AT = '2027-03-16 09:00:00+01:00';
    private const string STARTED_AT_UTC = '2027-03-16 08:00:00';
    private const string SAVED_AT = '2027-03-17 09:15:00+02:00';
    private const string SAVED_AT_UTC = '2027-03-17 07:15:00';
    private const string SHARED_AT_UTC = '2027-03-18 10:00:00';
    // The version seedRounds() stores for the draft: 1, and one more for each of the five changes made to it.
    private const int SEEDED_VERSION = 6;

    private ?EntityManagerInterface $otherSession = null;

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->seedRounds();
    }

    protected function tearDown(): void
    {
        $this->otherSession?->getConnection()->close();
        $this->otherSession = null;
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function aRoundIsReadBackWithItsScreenshotsByPositionAndItsPinsByNumber(): void
    {
        $draft = $this->read(self::ACME_FOODS, self::DRAFT);

        self::assertNotNull($draft);
        self::assertSame(self::LENA, $draft->ownerId()->toString());
        self::assertSame(self::TICKET, $draft->ticketId()->toString());
        self::assertSame(self::ACME_FOODS, $draft->organizationId()->toString());
        self::assertNull($draft->number());
        self::assertNull($draft->sharedAt());
        self::assertNull($draft->deletedAt());
        self::assertSame(RoundState::Open, $draft->state());
        self::assertSame($draft->version(), $draft->savedVersion());
        self::assertSame(self::STARTED_AT_UTC, $draft->createdAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT], array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $draft->screenshots()));
        [$first, $second] = $draft->screenshots();
        self::assertSame([0, 1], [$first->position(), $second->position()]);
        self::assertSame(self::FIRST_MEDIA, $first->mediaId());
        self::assertSame('Checkout', $first->title());
        self::assertSame(ScreenshotVerdict::LooksRight, $first->verdict());
        self::assertSame([self::FIRST_PIN, self::SECOND_PIN], array_map(static fn (Pin $pin): string => $pin->id()->toString(), $first->pins()));
        self::assertSame([1, 2], array_map(static fn (Pin $pin): int => $pin->number(), $first->pins()));
        self::assertSame([12.35, 100.0], array_map(static fn (Pin $pin): float => $pin->xPercent(), $first->pins()));
        self::assertSame(['Prix affiché en €', 'two'], array_map(static fn (Pin $pin): string => $pin->note(), $first->pins()));
        self::assertSame([], $second->pins());
    }

    #[Test]
    public function aDeletedDraftIsStillReadBackSoTheCallerCanRefuseItAsGone(): void
    {
        $deleted = $this->read(self::ACME_FOODS, self::LENAS_DELETED_DRAFT);

        self::assertNotNull($deleted);
        self::assertSame(RoundState::Closed, $deleted->state());
        self::assertSame('2027-03-17 06:00:00', $deleted->deletedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function anUnknownRoundAndAnotherOrganizationsRoundAreNotFound(): void
    {
        self::assertNull($this->read(self::ACME_FOODS, self::UNKNOWN_ROUND));
        self::assertNull($this->read(self::OTHER_ORGANIZATION, self::DRAFT));
        self::assertNull($this->read(self::ACME_FOODS, self::OTHER_ORGANIZATION_OPEN_DRAFT));
        self::assertNotNull($this->read(self::OTHER_ORGANIZATION, self::OTHER_ORGANIZATION_OPEN_DRAFT));
    }

    #[Test]
    public function theOpenDraftOfAnOwnerOnATicketIsFoundAmongTheRoundsThatMustNotMatch(): void
    {
        $found = $this->committed(self::ACME_FOODS, fn (): ?Round => $this->rounds()->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::LENA)));

        // Lena has a deleted draft on the ticket too, which the open-draft slot no longer holds.
        self::assertSame(self::DRAFT, $found?->id()->toString());
        self::assertCount(2, $found->screenshots());
    }

    /**
     * @return list<array{string, string, string}> tenant, ticket, owner
     */
    private static function owners(): array
    {
        return [
            [self::ACME_FOODS, self::TICKET, self::MIA],
            [self::ACME_FOODS, self::OTHER_TICKET, self::OMAR],
            [self::ACME_FOODS, self::EMPTY_TICKET, self::LENA],
        ];
    }

    #[Test]
    public function noOpenDraftIsFoundForAnOwnerWhoHasOnlyASharedOrDeletedOneOrNoneOnThatTicket(): void
    {
        foreach (self::owners() as [$organizationId, $ticketId, $ownerId]) {
            $found = $this->committed($organizationId, fn (): ?Round => $this->rounds()->openUnsharedDraftOf(new TicketId($ticketId), new UserId($ownerId)));

            self::assertNull($found, \sprintf('Owner %s on ticket %s.', $ownerId, $ticketId));
        }
        // Mia's one on the ticket is shared, and she has none open; Lena's other-organization one is not Acme Foods'.
        self::assertSame(
            self::OTHER_ORGANIZATION_OPEN_DRAFT,
            $this->committed(self::OTHER_ORGANIZATION, fn (): ?Round => $this->rounds()->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::LENA)))?->id()->toString(),
        );
    }

    #[Test]
    public function theNextNumberIsOneAboveTheTicketsHighestAndCountsNoOtherTicketOrOrganization(): void
    {
        // Acme Foods' ticket: the report 0 and Mia's number 1 (Beta Bakery's 9 on the same ticket id is not its own).
        self::assertSame(2, $this->nextNumber(self::ACME_FOODS, self::TICKET));
        self::assertSame(10, $this->nextNumber(self::OTHER_ORGANIZATION, self::TICKET));
        self::assertSame(1, $this->nextNumber(self::ACME_FOODS, self::EMPTY_TICKET));
        // Only drafts with no number: none above 0 either.
        self::assertSame(1, $this->nextNumber(self::ACME_FOODS, self::OTHER_TICKET));
    }

    #[Test]
    public function everyReadAndSaveRefusesWithoutAnOpenTransaction(): void
    {
        $draft = $this->read(self::ACME_FOODS, self::DRAFT);
        self::assertNotNull($draft);
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        $message = 'Rounds are read and saved in a transaction under the organization as tenant; none is open.';
        $calls = [
            'get' => fn () => $this->rounds()->get(new RoundId(self::DRAFT)),
            'openUnsharedDraftOf' => fn () => $this->rounds()->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::LENA)),
            'nextFreeNumberOf' => fn () => $this->rounds()->nextFreeNumberOf(new TicketId(self::TICKET)),
            'save' => fn () => $this->rounds()->save($draft, self::savedAt()),
        ];

        try {
            foreach ($calls as $name => $call) {
                try {
                    $call();
                    self::fail($name.' ran without a transaction.');
                } catch (\LogicException $refusal) {
                    self::assertSame($message, $refusal->getMessage(), $name);
                }
            }
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }
    }

    #[Test]
    public function savingAddsTheNewScreenshotAndPinsStampedWithTheSaveTimeAndLeavesTheStoredOnesAlone(): void
    {
        $noiseBefore = $this->rowsOfOtherRoundsThan(self::DRAFT);
        $draftRowsBefore = $this->rowsOfRound(self::DRAFT);

        $this->committed(self::ACME_FOODS, function (): void {
            $draft = $this->storedDraft();
            $draft->addScreenshot(new UserId(self::LENA), new ScreenshotId(self::THIRD_SCREENSHOT), self::SECOND_MEDIA);
            $draft->replacePins(new UserId(self::LENA), new ScreenshotId(self::THIRD_SCREENSHOT), [Pin::placedBy(new PinId(self::THIRD_PIN), 1, 5.0, 6.25, 'Fresh', new UserId(self::LENA))]);
            $this->rounds()->save($draft, self::savedAt());
        });

        $rows = $this->rowsOfRound(self::DRAFT);
        self::assertSame(self::SEEDED_VERSION + 2, $rows['round']['version']);
        self::assertSame(
            [[self::FIRST_SCREENSHOT, self::STARTED_AT_UTC], [self::SECOND_SCREENSHOT, self::STARTED_AT_UTC], [self::THIRD_SCREENSHOT, self::SAVED_AT_UTC]],
            array_map(static fn (array $row): array => [$row['id'], $row['created_at_utc']], $rows['screenshots']),
        );
        self::assertSame(2, $rows['screenshots'][2]['position']);
        self::assertSame(self::SECOND_MEDIA, $rows['screenshots'][2]['media_id']);
        self::assertSame(
            [[self::FIRST_PIN, self::STARTED_AT_UTC], [self::SECOND_PIN, self::STARTED_AT_UTC], [self::THIRD_PIN, self::SAVED_AT_UTC]],
            array_map(static fn (array $row): array => [$row['id'], $row['created_at_utc']], $rows['pins']),
        );
        self::assertSame(['5.00', '6.25', 'Fresh', self::LENA], [$rows['pins'][2]['x_pct'], $rows['pins'][2]['y_pct'], $rows['pins'][2]['note'], $rows['pins'][2]['author_id']]);
        self::assertSame($draftRowsBefore['pins'][0], $rows['pins'][0]);
        self::assertSame($draftRowsBefore['screenshots'][0], $rows['screenshots'][0]);
        self::assertSame($noiseBefore, $this->rowsOfOtherRoundsThan(self::DRAFT));
    }

    #[Test]
    public function savingUpdatesChangedRowsInPlaceAndRemovesTheScreenshotsAndPinsTheRoundNoLongerHas(): void
    {
        $noiseBefore = $this->rowsOfOtherRoundsThan(self::DRAFT);

        $this->committed(self::ACME_FOODS, function (): void {
            $lena = new UserId(self::LENA);
            $draft = $this->storedDraft();
            $draft->editScreenshotText($lena, new ScreenshotId(self::FIRST_SCREENSHOT), 'New title', ' A summary ');
            $draft->setVerdict($lena, new ScreenshotId(self::FIRST_SCREENSHOT), ScreenshotVerdict::NotRight);
            // The first pin keeps its id and moves, the second goes, the third is new.
            $draft->replacePins($lena, new ScreenshotId(self::FIRST_SCREENSHOT), [
                Pin::placedBy(new PinId(self::FIRST_PIN), 1, 55.5, 44.444, 'Moved', $lena),
                Pin::placedBy(new PinId(self::THIRD_PIN), 2, 1.0, 2.0, 'Added', $lena),
            ]);
            $draft->removeScreenshot($lena, new ScreenshotId(self::SECOND_SCREENSHOT));
            $this->rounds()->save($draft, self::savedAt());
        });

        $rows = $this->rowsOfRound(self::DRAFT);
        self::assertSame(
            [[self::FIRST_SCREENSHOT, 0, 'New title', 'A summary', 'not_right', self::STARTED_AT_UTC]],
            array_map(static fn (array $row): array => [$row['id'], $row['position'], $row['title'], $row['summary'], $row['verdict'], $row['created_at_utc']], $rows['screenshots']),
        );
        self::assertSame(
            [
                [self::FIRST_PIN, 1, '55.50', '44.44', 'Moved', self::STARTED_AT_UTC],
                [self::THIRD_PIN, 2, '1.00', '2.00', 'Added', self::SAVED_AT_UTC],
            ],
            array_map(static fn (array $row): array => [$row['id'], $row['number'], $row['x_pct'], $row['y_pct'], $row['note'], $row['created_at_utc']], $rows['pins']),
        );
        self::assertSame(self::SEEDED_VERSION + 4, $rows['round']['version']);
        self::assertSame($noiseBefore, $this->rowsOfOtherRoundsThan(self::DRAFT));
        $reread = $this->read(self::ACME_FOODS, self::DRAFT);
        self::assertSame(ScreenshotVerdict::NotRight, $reread?->screenshots()[0]->verdict());
    }

    #[Test]
    public function removingAScreenshotRemovesItsPinsToo(): void
    {
        $noiseBefore = $this->rowsOfOtherRoundsThan(self::DRAFT);
        self::assertCount(2, $this->pinIdsOfDraft());

        $this->committed(self::ACME_FOODS, function (): void {
            $draft = $this->storedDraft();
            $draft->removeScreenshot(new UserId(self::LENA), new ScreenshotId(self::FIRST_SCREENSHOT));
            $this->rounds()->save($draft, self::savedAt());
        });

        $rows = $this->rowsOfRound(self::DRAFT);
        self::assertSame([self::SECOND_SCREENSHOT], array_map(static fn (array $row): mixed => $row['id'], $rows['screenshots']));
        self::assertSame(1, $rows['screenshots'][0]['position']);
        self::assertSame([], $this->pinIdsOfDraft());
        self::assertSame($noiseBefore, $this->rowsOfOtherRoundsThan(self::DRAFT));
    }

    #[Test]
    public function aDraftReadBeforeAnotherSaveIsRefusedAsChangedAndWritesNoPartOfItself(): void
    {
        $first = $this->readDraft();
        $second = $this->readDraft();
        $first->editScreenshotText(new UserId(self::LENA), new ScreenshotId(self::FIRST_SCREENSHOT), 'First wins', null);
        $this->committed(self::ACME_FOODS, fn () => $this->rounds()->save($first, self::savedAt()));
        $second->addScreenshot(new UserId(self::LENA), new ScreenshotId(self::THIRD_SCREENSHOT), self::SECOND_MEDIA);
        $second->replacePins(new UserId(self::LENA), new ScreenshotId(self::THIRD_SCREENSHOT), [Pin::placedBy(new PinId(self::THIRD_PIN), 1, 5.0, 6.0, 'Lost', new UserId(self::LENA))]);
        $rowsAfterFirst = $this->rowsOfRound(self::DRAFT);

        $refusal = $this->committed(self::ACME_FOODS, function () use ($second): ?RoundChanged {
            try {
                $this->rounds()->save($second, self::savedAt());
            } catch (RoundChanged $changed) {
                return $changed;
            }

            return null;
        });

        self::assertInstanceOf(RoundChanged::class, $refusal);
        self::assertSame('The round changed since it was read.', $refusal->getMessage());
        self::assertSame(self::SEEDED_VERSION + 1, $rowsAfterFirst['round']['version']);
        self::assertSame($rowsAfterFirst, $this->rowsOfRound(self::DRAFT));
        self::assertSame('First wins', $rowsAfterFirst['screenshots'][0]['title']);
    }

    #[Test]
    public function aSavedRoundObjectCannotBeSavedAgainWithoutReadingItAgain(): void
    {
        $draft = $this->readDraft();
        $draft->editScreenshotText(new UserId(self::LENA), new ScreenshotId(self::FIRST_SCREENSHOT), 'Once', null);
        $this->committed(self::ACME_FOODS, fn () => $this->rounds()->save($draft, self::savedAt()));
        $draft->editScreenshotText(new UserId(self::LENA), new ScreenshotId(self::FIRST_SCREENSHOT), 'Twice', null);
        $rowsAfterFirstSave = $this->rowsOfRound(self::DRAFT);

        $refusal = $this->committed(self::ACME_FOODS, function () use ($draft): ?RoundChanged {
            try {
                $this->rounds()->save($draft, self::savedAt());
            } catch (RoundChanged $changed) {
                return $changed;
            }

            return null;
        });

        self::assertInstanceOf(RoundChanged::class, $refusal);
        self::assertSame($rowsAfterFirstSave, $this->rowsOfRound(self::DRAFT));
        self::assertSame('Once', $rowsAfterFirstSave['screenshots'][0]['title']);
    }

    #[Test]
    public function savingAnotherOrganizationsRoundIsRefusedAsChangedAndWritesNothing(): void
    {
        $draft = $this->readDraft();
        $draft->editScreenshotText(new UserId(self::LENA), new ScreenshotId(self::FIRST_SCREENSHOT), 'Not yours', null);
        $rowsBefore = $this->rowsOfRound(self::DRAFT);

        $refusal = $this->committed(self::OTHER_ORGANIZATION, function () use ($draft): ?RoundChanged {
            try {
                $this->rounds()->save($draft, self::savedAt());
            } catch (RoundChanged $changed) {
                return $changed;
            }

            return null;
        });

        self::assertInstanceOf(RoundChanged::class, $refusal);
        self::assertSame($rowsBefore, $this->rowsOfRound(self::DRAFT));
        self::assertSame($rowsBefore['screenshots'][0]['title'], 'Checkout');
    }

    /**
     * The savepoint DbalRounds::save opens: a screenshot the database refuses halfway, after the round row went over,
     * leaves the round as it was and the caller's transaction goes on.
     */
    #[Test]
    public function aSaveTheDatabaseRefusesHalfwayLeavesTheRoundAsItWasAndTheCallersTransactionGoesOn(): void
    {
        $rowsBefore = $this->rowsOfRound(self::DRAFT);
        $draft = $this->readDraft();
        // The id of Omar's screenshot, so the insert after the round's UPDATE fails on the primary key.
        $draft->addScreenshot(new UserId(self::LENA), new ScreenshotId(self::OMARS_SCREENSHOT), self::SECOND_MEDIA);

        $refusal = $this->committed(self::ACME_FOODS, function () use ($draft): ?UniqueConstraintViolationException {
            $nestingLevel = $this->connection()->getTransactionNestingLevel();
            try {
                $this->rounds()->save($draft, self::savedAt());
            } catch (UniqueConstraintViolationException $refusal) {
                self::assertSame($nestingLevel, $this->connection()->getTransactionNestingLevel());
                $this->rounds()->add(Round::draft(new RoundId(self::LENAS_NEW_DRAFT), new OrganizationId(self::ACME_FOODS), new TicketId(self::EMPTY_TICKET), new UserId(self::LENA), self::startedAt()));

                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(UniqueConstraintViolationException::class, $refusal);
        self::assertStringContainsString('rvw_screenshot_pkey', $refusal->getMessage());
        self::assertSame($rowsBefore, $this->rowsOfRound(self::DRAFT));
        self::assertNotNull($this->read(self::ACME_FOODS, self::LENAS_NEW_DRAFT), 'The caller\'s transaction did not go on to commit its next write.');
    }

    #[Test]
    public function sharingStoresTheNumberAndTimeAndFreesTheOwnersDraftSlot(): void
    {
        $noiseBefore = $this->rowsOfOtherRoundsThan(self::DRAFT);

        $this->committed(self::ACME_FOODS, function (): void {
            $draft = $this->storedDraft();
            $number = $this->rounds()->nextFreeNumberOf($draft->ticketId());
            $draft->share(new UserId(self::LENA), $number, new \DateTimeImmutable(self::SHARED_AT_UTC.'+00:00'));
            $this->rounds()->save($draft, self::savedAt());
        });

        $round = $this->rowsOfRound(self::DRAFT)['round'];
        self::assertSame([2, self::SHARED_AT_UTC, 'open', self::SEEDED_VERSION + 1], [$round['number'], $round['shared_at_utc'], $round['state'], $round['version']]);
        self::assertNull($this->committed(self::ACME_FOODS, fn (): ?Round => $this->rounds()->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::LENA))));
        self::assertSame($noiseBefore, $this->rowsOfOtherRoundsThan(self::DRAFT));
    }

    /**
     * The unique index on the ticket's round numbers backs the advisory lock up: a number another round holds is
     * refused even when the caller read a stale one, and the draft stays as it was.
     */
    #[Test]
    public function sharingUnderANumberAnotherRoundHoldsIsRefusedAsSlotTakenAndLeavesTheDraftPrivate(): void
    {
        $rowsBefore = $this->rowsOfRound(self::DRAFT);

        $refusal = $this->committed(self::ACME_FOODS, function (): ?RoundSlotTaken {
            $draft = $this->storedDraft();
            // Mia's shared draft holds number 1.
            $draft->share(new UserId(self::LENA), 1, new \DateTimeImmutable(self::SHARED_AT_UTC.'+00:00'));
            try {
                $this->rounds()->save($draft, self::savedAt());
            } catch (RoundSlotTaken $taken) {
                $fresh = $this->storedDraft();
                $fresh->share(new UserId(self::LENA), 2, new \DateTimeImmutable(self::SHARED_AT_UTC.'+00:00'));
                $this->rounds()->save($fresh, self::savedAt());

                return $taken;
            }

            return null;
        });

        self::assertInstanceOf(RoundSlotTaken::class, $refusal);
        self::assertSame('The ticket has this round already.', $refusal->getMessage());
        self::assertInstanceOf(UniqueConstraintViolationException::class, $refusal->getPrevious());
        self::assertSame(self::SEEDED_VERSION, $rowsBefore['round']['version']);
        $round = $this->rowsOfRound(self::DRAFT)['round'];
        self::assertSame([2, self::SEEDED_VERSION + 1], [$round['number'], $round['version']], 'The transaction did not go on to share under the next free number.');
    }

    #[Test]
    public function deletingStoresItClosedAndFreesTheOwnersDraftSlotForANewDraft(): void
    {
        $this->committed(self::ACME_FOODS, function (): void {
            $draft = $this->storedDraft();
            $draft->delete(new UserId(self::LENA), new \DateTimeImmutable('2027-03-18 12:30:00+00:00'));
            $this->rounds()->save($draft, self::savedAt());
        });

        $rows = $this->rowsOfRound(self::DRAFT);
        self::assertSame(['closed', '2027-03-18 12:30:00', null, null, self::SEEDED_VERSION + 1], [$rows['round']['state'], $rows['round']['deleted_at_utc'], $rows['round']['shared_at_utc'], $rows['round']['number'], $rows['round']['version']]);
        self::assertCount(2, $rows['screenshots'], 'A deleted draft keeps its pictures for retention to remove.');
        self::assertNull($this->committed(self::ACME_FOODS, fn (): ?Round => $this->rounds()->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::LENA))));

        $this->addCommitted(Round::draft(new RoundId(self::LENAS_NEW_DRAFT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), self::startedAt()));

        self::assertSame(
            self::LENAS_NEW_DRAFT,
            $this->committed(self::ACME_FOODS, fn (): ?Round => $this->rounds()->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::LENA)))?->id()->toString(),
        );
    }

    #[Test]
    public function theDraftCommandsRefuseADeletedDraftAnotherOrganizationsAndAnotherPersonsAsNotFoundOnTheRealTables(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $commands = new UseCaseRoundCommands(
            $this->rounds(),
            new InMemoryTicketTurnQueries([], new TicketId(self::EMPTY_TICKET)),
            $tenantContext,
            new ReportPictures(new InMemoryUploadedMedia($tenantContext, [[self::ACME_FOODS, self::FIRST_MEDIA, self::LENA, MediaImageStatus::Ready]])),
        );
        $rowsBefore = [$this->rowsOfRound(self::LENAS_DELETED_DRAFT), $this->rowsOfRound(self::OTHER_ORGANIZATION_OPEN_DRAFT), $this->rowsOfRound(self::OMARS_DRAFT)];

        foreach ([self::LENAS_DELETED_DRAFT, self::OTHER_ORGANIZATION_OPEN_DRAFT, self::OMARS_DRAFT] as $roundId) {
            $refusal = $this->committed(self::ACME_FOODS, static function () use ($commands, $roundId): ?RoundRefused {
                try {
                    $commands->deleteDraft($roundId, new UserId(self::LENA), self::savedAt());
                } catch (RoundRefused $refused) {
                    return $refused;
                }

                return null;
            });

            self::assertNotNull($refusal, $roundId);
            self::assertSame(RoundRefused::NOT_FOUND, $refusal->getMessage(), $roundId);
        }
        self::assertSame($rowsBefore, [$this->rowsOfRound(self::LENAS_DELETED_DRAFT), $this->rowsOfRound(self::OTHER_ORGANIZATION_OPEN_DRAFT), $this->rowsOfRound(self::OMARS_DRAFT)]);
    }

    /**
     * Two requests share one draft at once. Both read it unshared; the first takes the ticket's number lock and
     * shares it; the second waits at the lock (lock_timeout ends the wait here, as PHP cannot wait in two places) and,
     * once the first commits, finds the draft changed. Exactly one share lands, under one number.
     */
    #[Test]
    public function twoConcurrentSharesOfOneDraftLetExactlyOneWin(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $tenantContext);
        $this->otherSession = $secondSession->entityManager;
        $otherRounds = new DbalRounds($secondSession->entityManager->getConnection(), $tenantContext);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $readBySecond = $this->readIn($secondSession->entityManager->getConnection(), $otherRounds);

        $this->connection()->beginTransaction();
        $draftOfFirst = $this->storedDraft();
        $firstNumber = $this->rounds()->nextFreeNumberOf($draftOfFirst->ticketId());
        $draftOfFirst->share(new UserId(self::LENA), $firstNumber, new \DateTimeImmutable(self::SHARED_AT_UTC.'+00:00'));
        $this->rounds()->save($draftOfFirst, self::savedAt());

        $waitedAtTheLock = $secondSession->sqlStateOfFailedAttempt(static function () use ($otherRounds): void {
            $otherRounds->nextFreeNumberOf(new TicketId(self::TICKET));
        });
        $this->connection()->commit();

        $secondConnection = $secondSession->entityManager->getConnection();
        $secondConnection->beginTransaction();
        try {
            $secondNumber = $otherRounds->nextFreeNumberOf($readBySecond->ticketId());
            $readBySecond->share(new UserId(self::LENA), $secondNumber, new \DateTimeImmutable('2027-03-18 11:00:00+00:00'));
            try {
                $otherRounds->save($readBySecond, self::savedAt());
                $secondWon = true;
            } catch (RoundChanged) {
                $secondWon = false;
            }
        } finally {
            $secondConnection->rollBack();
        }
        $tenantContext->clear();

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $waitedAtTheLock, 'The second share did not wait for the first one\'s number lock.');
        self::assertSame(2, $firstNumber);
        self::assertSame(3, $secondNumber, 'After the first committed, the second reads the next number.');
        self::assertFalse($secondWon, 'Both shares of one draft were saved.');
        $round = $this->rowsOfRound(self::DRAFT)['round'];
        self::assertSame([2, self::SHARED_AT_UTC, self::SEEDED_VERSION + 1], [$round['number'], $round['shared_at_utc'], $round['version']]);
        self::assertSame(
            [0, 1, 2],
            $this->committed(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT number FROM rvw_round WHERE ticket_id = ? AND number IS NOT NULL ORDER BY number', [self::TICKET])),
        );
    }

    private function seedRounds(): void
    {
        $lena = new UserId(self::LENA);
        $draft = self::draftOf(self::DRAFT, self::ACME_FOODS, self::TICKET, self::LENA);
        $draft->addScreenshot($lena, new ScreenshotId(self::FIRST_SCREENSHOT), self::FIRST_MEDIA);
        $draft->editScreenshotText($lena, new ScreenshotId(self::FIRST_SCREENSHOT), 'Checkout', null);
        $draft->setVerdict($lena, new ScreenshotId(self::FIRST_SCREENSHOT), ScreenshotVerdict::LooksRight);
        // Out of order, so the stored numbers come from the pins and the read orders them.
        $draft->replacePins($lena, new ScreenshotId(self::FIRST_SCREENSHOT), [
            Pin::placedBy(new PinId(self::SECOND_PIN), 2, 100.0, 0.0, 'two', $lena),
            Pin::placedBy(new PinId(self::FIRST_PIN), 1, 12.349, 7.1, 'Prix affiché en €', $lena),
        ]);
        $draft->addScreenshot($lena, new ScreenshotId(self::SECOND_SCREENSHOT), self::SECOND_MEDIA);
        $this->addCommitted($draft);

        $deleted = self::draftOf(self::LENAS_DELETED_DRAFT, self::ACME_FOODS, self::TICKET, self::LENA);
        $deleted->delete($lena, new \DateTimeImmutable('2027-03-17 06:00:00+00:00'));
        $this->addCommitted($deleted);

        $omarsDraft = self::draftOf(self::OMARS_DRAFT, self::ACME_FOODS, self::TICKET, self::OMAR);
        $omarsDraft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::OMARS_SCREENSHOT), self::FIRST_MEDIA);
        $omarsDraft->replacePins(new UserId(self::OMAR), new ScreenshotId(self::OMARS_SCREENSHOT), [Pin::placedBy(new PinId(self::OMARS_PIN), 1, 40.0, 60.0, 'Omar\'s', new UserId(self::OMAR))]);
        $this->addCommitted($omarsDraft);

        $miasShared = self::draftOf(self::MIAS_SHARED_DRAFT, self::ACME_FOODS, self::TICKET, self::MIA);
        $miasShared->addScreenshot(new UserId(self::MIA), new ScreenshotId(self::MIAS_SCREENSHOT), self::FIRST_MEDIA);
        $miasShared->replacePins(new UserId(self::MIA), new ScreenshotId(self::MIAS_SCREENSHOT), [Pin::placedBy(new PinId(self::MIAS_PIN), 1, 20.0, 30.0, 'Mia\'s', new UserId(self::MIA))]);
        $miasShared->share(new UserId(self::MIA), 1, new \DateTimeImmutable('2027-03-17 05:00:00+00:00'));
        $this->addCommitted($miasShared);

        $this->addCommitted(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $lena, [
            Screenshot::of(new ScreenshotId(self::REPORT_SCREENSHOT), self::FIRST_MEDIA, 0, null, null, []),
        ], new \DateTimeImmutable('2027-03-10 08:00:00+00:00')));

        $otherTicketDraft = self::draftOf(self::LENAS_OTHER_TICKET_DRAFT, self::ACME_FOODS, self::OTHER_TICKET, self::LENA);
        $otherTicketDraft->addScreenshot($lena, new ScreenshotId(self::OTHER_TICKET_SCREENSHOT), self::FIRST_MEDIA);
        $this->addCommitted($otherTicketDraft);

        // Another organization's rounds on the very same ticket id: an open draft of Lena's and a shared one numbered 9.
        $openElsewhere = self::draftOf(self::OTHER_ORGANIZATION_OPEN_DRAFT, self::OTHER_ORGANIZATION, self::TICKET, self::LENA);
        $openElsewhere->addScreenshot($lena, new ScreenshotId(self::OTHER_ORGANIZATION_SCREENSHOT), self::FIRST_MEDIA);
        $openElsewhere->replacePins($lena, new ScreenshotId(self::OTHER_ORGANIZATION_SCREENSHOT), [Pin::placedBy(new PinId(self::OTHER_ORGANIZATION_PIN), 1, 1.0, 1.0, 'Elsewhere', $lena)]);
        $this->addCommitted($openElsewhere);
        $sharedElsewhere = self::draftOf(self::OTHER_ORGANIZATION_SHARED_DRAFT, self::OTHER_ORGANIZATION, self::TICKET, self::LENA);
        $sharedElsewhere->share($lena, 9, new \DateTimeImmutable('2027-03-17 05:00:00+00:00'));
        $this->addCommitted($sharedElsewhere);
    }

    private static function draftOf(string $roundId, string $organizationId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId($organizationId), new TicketId($ticketId), new UserId($ownerId), self::startedAt());
    }

    private static function startedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::STARTED_AT);
    }

    private static function savedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::SAVED_AT);
    }

    private function addCommitted(Round $round): void
    {
        $this->committed($round->organizationId()->toString(), function () use ($round): void {
            $this->rounds()->add($round);
        });
    }

    private function read(string $organizationId, string $roundId): ?Round
    {
        return $this->committed($organizationId, fn (): ?Round => $this->rounds()->get(new RoundId($roundId)));
    }

    /**
     * @return list<mixed>
     */
    private function pinIdsOfDraft(): array
    {
        return array_map(static fn (array $row): mixed => $row['id'], $this->rowsOfRound(self::DRAFT)['pins']);
    }

    private function readDraft(): Round
    {
        return $this->read(self::ACME_FOODS, self::DRAFT) ?? throw new \LogicException('The seeded draft is missing.');
    }

    /**
     * Inside the caller's transaction.
     */
    private function storedDraft(): Round
    {
        return $this->rounds()->get(new RoundId(self::DRAFT)) ?? throw new \LogicException('The seeded draft is missing.');
    }

    private function readIn(Connection $connection, DbalRounds $rounds): Round
    {
        $connection->beginTransaction();
        try {
            return $rounds->get(new RoundId(self::DRAFT)) ?? throw new \LogicException('No draft.');
        } finally {
            $connection->rollBack();
        }
    }

    private function nextNumber(string $organizationId, string $ticketId): int
    {
        return $this->committed($organizationId, fn (): int => $this->rounds()->nextFreeNumberOf(new TicketId($ticketId)));
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

    /**
     * The round's row, screenshots by position and pins by screenshot position and number, as stored, with times in UTC.
     *
     * @return array{round: array<string, mixed>, screenshots: list<array<string, mixed>>, pins: list<array<string, mixed>>}
     */
    private function rowsOfRound(string $roundId): array
    {
        $organizationId = \in_array($roundId, [self::OTHER_ORGANIZATION_OPEN_DRAFT, self::OTHER_ORGANIZATION_SHARED_DRAFT], true) ? self::OTHER_ORGANIZATION : self::ACME_FOODS;

        return $this->transactions()->rolledBackAs($organizationId, function () use ($roundId): array {
            $round = $this->connection()->fetchAssociative(
                "SELECT id, ticket_id, number, kind, owner_id, state, version, shared_at AT TIME ZONE 'UTC' AS shared_at_utc, deleted_at AT TIME ZONE 'UTC' AS deleted_at_utc, created_at AT TIME ZONE 'UTC' AS created_at_utc, decision FROM rvw_round WHERE id = ?",
                [$roundId],
            );
            self::assertIsArray($round);

            return [
                'round' => $round,
                'screenshots' => $this->connection()->fetchAllAssociative(
                    "SELECT id, media_id, position, variant_label, verdict, title, summary, created_at AT TIME ZONE 'UTC' AS created_at_utc FROM rvw_screenshot WHERE round_id = ? ORDER BY position",
                    [$roundId],
                ),
                'pins' => $this->connection()->fetchAllAssociative(
                    "SELECT p.id, p.screenshot_id, p.number, p.x_pct, p.y_pct, p.note, p.verdict, p.source, p.ai_state, p.author_id, p.created_at AT TIME ZONE 'UTC' AS created_at_utc"
                    .' FROM rvw_pin p JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id WHERE s.round_id = ? ORDER BY s.position, p.number',
                    [$roundId],
                ),
            ];
        });
    }

    /**
     * Every row of every round but one, of both organizations, as JSON text, so any column a wrong WHERE touched shows.
     *
     * @return list<list<mixed>>
     */
    private function rowsOfOtherRoundsThan(string $roundId): array
    {
        $rows = [];
        foreach ([self::ACME_FOODS, self::OTHER_ORGANIZATION] as $organizationId) {
            $rows[] = $this->transactions()->rolledBackAs($organizationId, fn (): array => [
                ...$this->connection()->fetchFirstColumn('SELECT to_jsonb(r)::text FROM rvw_round r WHERE id <> ? ORDER BY id', [$roundId]),
                ...$this->connection()->fetchFirstColumn('SELECT to_jsonb(s)::text FROM rvw_screenshot s WHERE round_id <> ? ORDER BY id', [$roundId]),
                ...$this->connection()->fetchFirstColumn('SELECT to_jsonb(p)::text FROM rvw_pin p WHERE screenshot_id NOT IN (SELECT id FROM rvw_screenshot WHERE round_id = ?) ORDER BY id', [$roundId]),
            ]);
        }
        self::assertNotSame([], $rows[0], 'The other rounds were not seeded, so nothing below is proven.');
        self::assertNotSame([], $rows[1]);

        return $rows;
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
