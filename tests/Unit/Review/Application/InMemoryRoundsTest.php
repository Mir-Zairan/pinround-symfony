<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\DraftDecision;
use App\Review\Domain\DraftFacts;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The double is only worth using while it behaves like DbalRounds, so its three rules are pinned here.
 */
final class InMemoryRoundsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000001002';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';
    private const string UNKNOWN_ROUND = '0197a3c0-0000-7000-8000-000000000e77';

    #[Test]
    public function aSecondReportOnOneTicketIsASlotTakenAndIsNotStored(): void
    {
        $rounds = new InMemoryRounds();
        $rounds->add(self::report($rounds, self::TICKET));

        try {
            $rounds->add(self::report($rounds, self::TICKET));
            self::fail('A second report was stored.');
        } catch (RoundSlotTaken) {
            self::assertCount(1, $rounds->stored);
        }
    }

    #[Test]
    public function aReportOnAnotherTicketTakesNoSlotOfTheFirst(): void
    {
        $rounds = new InMemoryRounds();
        $rounds->add(self::report($rounds, self::TICKET));
        $rounds->add(self::report($rounds, self::OTHER_TICKET));

        self::assertCount(2, $rounds->stored);
    }

    #[Test]
    public function anOwnerHasOneUnsharedDraftPerTicketAndAnotherOwnerHasTheirOwn(): void
    {
        $rounds = new InMemoryRounds();
        $rounds->add(self::draft($rounds, self::OMAR));
        $rounds->add(self::draft($rounds, self::LENA));

        try {
            $rounds->add(self::draft($rounds, self::OMAR));
            self::fail('A second open draft of one owner was stored.');
        } catch (RoundSlotTaken) {
            self::assertCount(2, $rounds->stored);
        }
    }

    #[Test]
    public function aRoundIdMetAgainIsALogicExceptionNotASlotTaken(): void
    {
        $rounds = new InMemoryRounds();
        $report = self::report($rounds, self::TICKET);
        $rounds->add($report);

        try {
            $rounds->add($report);
            self::fail('A round was added twice.');
        } catch (\LogicException $logic) {
            self::assertNotInstanceOf(RoundSlotTaken::class, $logic);
            self::assertCount(1, $rounds->stored);
        }
    }

    #[Test]
    public function everyIdentityIsNew(): void
    {
        $rounds = new InMemoryRounds();

        $ids = [$rounds->nextIdentity()->toString(), $rounds->nextScreenshotIdentity()->toString(), $rounds->nextPinIdentity()->toString(), $rounds->nextIdentity()->toString()];

        self::assertCount(4, array_unique($ids));
    }

    #[Test]
    public function getGivesACopyThatChangesNothingStoredUntilItIsSaved(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $draft = self::draft($rounds, self::OMAR);
        $rounds->add($draft);

        $read = $rounds->get($draft->id());
        self::assertNotNull($read);
        $read->addScreenshot(new UserId(self::OMAR), $rounds->nextScreenshotIdentity(), self::MEDIA);

        self::assertCount(1, $read->screenshots());
        self::assertSame([], $rounds->stored[0]->screenshots());
        self::assertSame(1, $rounds->stored[0]->version());
        self::assertSame([], $rounds->get($draft->id())?->screenshots());
    }

    #[Test]
    public function getFindsADeletedDraftToo(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $rounds->add(self::draft($rounds, self::OMAR));
        $read = $rounds->openUnsharedDraftOf(new TicketId(self::TICKET), new UserId(self::OMAR));
        self::assertNotNull($read);
        $read->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $rounds->save($read, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));

        $found = $rounds->get($read->id());

        self::assertNotNull($found);
        self::assertNotNull($found->deletedAt());
    }

    #[Test]
    public function getAnUnknownIdOrAnotherOrganizationsRoundIsNull(): void
    {
        $rounds = new InMemoryRounds(self::tenant(self::ACME_FOODS));
        $draft = self::draft($rounds, self::OMAR);
        $rounds->add($draft);
        $otherTenant = new InMemoryRounds(self::tenant(self::BETA_BAKERY));
        $otherTenant->stored = $rounds->stored;

        self::assertNull($rounds->get(new RoundId(self::UNKNOWN_ROUND)));
        self::assertNull($otherTenant->get($draft->id()));
        self::assertNotNull($rounds->get($draft->id()));
    }

    #[Test]
    public function readsAndSavesNeedATenantContext(): void
    {
        $rounds = new InMemoryRounds();
        $draft = self::draft($rounds, self::OMAR);
        $rounds->add($draft);

        foreach ([
            static fn () => $rounds->get($draft->id()),
            static fn () => $rounds->openUnsharedDraftOf($draft->ticketId(), $draft->ownerId()),
            static fn () => $rounds->openUnsharedDraftFactsOf($draft->ticketId(), $draft->ownerId()),
            static fn () => $rounds->nextFreeNumberOf($draft->ticketId()),
            static fn () => $rounds->save($draft, new \DateTimeImmutable('2026-03-05 08:00:00+00:00')),
        ] as $call) {
            try {
                $call();
                self::fail('A read or save ran without a tenant.');
            } catch (\LogicException $logic) {
                self::assertStringContainsString('TenantContext', $logic->getMessage());
            }
        }
    }

    #[Test]
    public function theOpenDraftOfAnOwnerIsFoundOnlyForThatTicketOwnerAndOrganization(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $omarsDraft = self::draft($rounds, self::OMAR);
        $rounds->add($omarsDraft);
        $rounds->add(self::draft($rounds, self::LENA));
        $ticket = new TicketId(self::TICKET);

        $found = $rounds->openUnsharedDraftOf($ticket, new UserId(self::OMAR));

        self::assertNotNull($found);
        self::assertSame($omarsDraft->id()->toString(), $found->id()->toString());
        self::assertNull($rounds->openUnsharedDraftOf(new TicketId(self::OTHER_TICKET), new UserId(self::OMAR)));
        self::assertNull($rounds->openUnsharedDraftOf($ticket, new UserId(self::MIA)));
        $otherTenant = new InMemoryRounds(self::tenant(self::BETA_BAKERY));
        $otherTenant->stored = $rounds->stored;
        self::assertNull($otherTenant->openUnsharedDraftOf($ticket, new UserId(self::OMAR)));
    }

    #[Test]
    public function theOpenDraftsFactsAreItsIdDecisionAndScreenshotCountAndFoundAsTheOpenDraftIs(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $omar = new UserId(self::OMAR);
        $omarsDraft = self::draft($rounds, self::OMAR);
        $omarsDraft->addScreenshot($omar, $rounds->nextScreenshotIdentity(), self::MEDIA);
        $omarsDraft->addScreenshot($omar, $rounds->nextScreenshotIdentity(), self::MEDIA);
        $omarsDraft->decide($omar, DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $rounds->add($omarsDraft);
        $rounds->add(self::draft($rounds, self::LENA));
        $ticket = new TicketId(self::TICKET);

        self::assertEquals(new DraftFacts($omarsDraft->id(), DraftDecision::KeptPrivate, 2), $rounds->openUnsharedDraftFactsOf($ticket, $omar));
        self::assertNull($rounds->openUnsharedDraftFactsOf(new TicketId(self::OTHER_TICKET), $omar));
        self::assertNull($rounds->openUnsharedDraftFactsOf($ticket, new UserId(self::MIA)));
        $otherTenant = new InMemoryRounds(self::tenant(self::BETA_BAKERY));
        $otherTenant->stored = $rounds->stored;
        self::assertNull($otherTenant->openUnsharedDraftFactsOf($ticket, $omar));
        self::assertSame(0, $rounds->loadedRounds, 'Facts are no read of the whole round.');
    }

    #[Test]
    public function aSharedOrDeletedDraftIsNotTheOpenDraftAndFreesTheSlotForANewOne(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $rounds->add(self::draftWithAScreenshot($rounds, self::OMAR));
        $ticket = new TicketId(self::TICKET);
        $owner = new UserId(self::OMAR);

        $shared = $rounds->openUnsharedDraftOf($ticket, $owner);
        self::assertNotNull($shared);
        $shared->share($owner, $rounds->nextFreeNumberOf($ticket), new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $rounds->save($shared, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        self::assertNull($rounds->openUnsharedDraftOf($ticket, $owner));

        $second = self::draft($rounds, self::OMAR);
        $rounds->add($second);
        $found = $rounds->openUnsharedDraftOf($ticket, $owner);
        self::assertNotNull($found);
        self::assertSame($second->id()->toString(), $found->id()->toString());

        $found->delete($owner, new \DateTimeImmutable('2026-03-06 08:00:00+00:00'));
        $rounds->save($found, new \DateTimeImmutable('2026-03-06 08:00:00+00:00'));
        self::assertNull($rounds->openUnsharedDraftOf($ticket, $owner));
        $rounds->add(self::draft($rounds, self::OMAR));
        self::assertCount(3, $rounds->stored);
    }

    #[Test]
    public function theNextFreeNumberIsOneAboveTheHighestOfThatTicketInThatOrganization(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $ticket = new TicketId(self::TICKET);
        self::assertSame(1, $rounds->nextFreeNumberOf($ticket));

        $rounds->add(self::report($rounds, self::TICKET));
        self::assertSame(1, $rounds->nextFreeNumberOf($ticket));

        $rounds->add(self::draftWithAScreenshot($rounds, self::OMAR));
        self::assertSame(1, $rounds->nextFreeNumberOf($ticket));

        $draft = $rounds->openUnsharedDraftOf($ticket, new UserId(self::OMAR));
        self::assertNotNull($draft);
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $rounds->save($draft, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));

        self::assertSame(2, $rounds->nextFreeNumberOf($ticket));
        self::assertSame(1, $rounds->nextFreeNumberOf(new TicketId(self::OTHER_TICKET)));
        self::assertSame([self::TICKET, self::TICKET, self::TICKET, self::TICKET, self::OTHER_TICKET], $rounds->numberedTicketIds);
    }

    #[Test]
    public function theOpenSharedRoundsAreTheTenantsOpenReviewRoundsAndSharedDraftsOfTheTicketByNumber(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $ticket = new TicketId(self::TICKET);
        $rounds->add(self::report($rounds, self::TICKET));
        $rounds->add(self::stored($rounds, RoundKind::Checklist, self::TICKET, self::LENA, 1, RoundState::Open));
        // Added before the review round it comes after, so the order is the number's.
        $omarsShared = self::stored($rounds, RoundKind::Draft, self::TICKET, self::OMAR, 3, RoundState::Open);
        $rounds->add($omarsShared);
        $review = self::stored($rounds, RoundKind::Review, self::TICKET, self::LENA, 2, RoundState::Open);
        $rounds->add($review);
        $rounds->add(self::stored($rounds, RoundKind::Draft, self::TICKET, self::MIA, 4, RoundState::Closed));
        $rounds->add(self::draftWithAScreenshot($rounds, self::LENA));
        $rounds->add(self::stored($rounds, RoundKind::Draft, self::OTHER_TICKET, self::OMAR, 1, RoundState::Open));
        $otherTenant = new InMemoryRounds(self::tenant(self::BETA_BAKERY));
        $otherTenant->stored = $rounds->stored;

        $openShared = $rounds->openSharedRoundsOf($ticket);

        self::assertSame(
            [$review->id()->toString(), $omarsShared->id()->toString()],
            array_map(static fn (Round $round): string => $round->id()->toString(), $openShared),
        );
        self::assertSame([], $otherTenant->openSharedRoundsOf($ticket));
        self::assertSame([self::TICKET], $rounds->lockedOpenRoundTicketIds);
        $openShared[0]->close();
        self::assertSame(RoundState::Open, $rounds->openSharedRoundsOf($ticket)[0]->state(), 'A read hands out a copy; only save() stores a change.');
    }

    #[Test]
    public function aSavedChangeIsStoredAtTheNewVersionAndTheOldCopyCannotBeSavedAgain(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $rounds->add(self::draft($rounds, self::OMAR));
        $owner = new UserId(self::OMAR);
        $read = $rounds->openUnsharedDraftOf(new TicketId(self::TICKET), $owner);
        self::assertNotNull($read);
        $read->addScreenshot($owner, $rounds->nextScreenshotIdentity(), self::MEDIA);

        $rounds->save($read, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));

        self::assertSame(2, $rounds->stored[0]->version());
        self::assertCount(1, $rounds->stored[0]->screenshots());
        try {
            $rounds->save($read, new \DateTimeImmutable('2026-03-05 08:01:00+00:00'));
            self::fail('A round was saved twice over one read.');
        } catch (RoundChanged) {
            self::assertSame(2, $rounds->stored[0]->version());
        }
    }

    #[Test]
    public function aSaveOverAStaleVersionIsRoundChangedAndLeavesTheStoredRoundAsTheOtherRequestSavedIt(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $rounds->add(self::draft($rounds, self::OMAR));
        $owner = new UserId(self::OMAR);
        $ticket = new TicketId(self::TICKET);
        $firstTab = $rounds->openUnsharedDraftOf($ticket, $owner);
        $secondTab = $rounds->openUnsharedDraftOf($ticket, $owner);
        self::assertNotNull($firstTab);
        self::assertNotNull($secondTab);
        $firstTab->addScreenshot($owner, $rounds->nextScreenshotIdentity(), self::MEDIA);
        $rounds->save($firstTab, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $secondTab->delete($owner, new \DateTimeImmutable('2026-03-05 08:01:00+00:00'));

        try {
            $rounds->save($secondTab, new \DateTimeImmutable('2026-03-05 08:01:00+00:00'));
            self::fail('A stale save was stored.');
        } catch (RoundChanged $changed) {
            self::assertSame('The round changed since it was read.', $changed->getMessage());
        }

        self::assertCount(1, $rounds->stored);
        self::assertSame(2, $rounds->stored[0]->version());
        self::assertNull($rounds->stored[0]->deletedAt());
        self::assertCount(1, $rounds->stored[0]->screenshots());
    }

    #[Test]
    public function aSaveOfARoundThatWasNeverAddedOrBelongsToAnotherOrganizationIsRoundChanged(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $never = self::draft($rounds, self::OMAR);
        try {
            $rounds->save($never, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
            self::fail('A round never added was saved.');
        } catch (RoundChanged) {
            self::assertSame([], $rounds->stored);
        }

        $rounds->add($never);
        $otherTenant = new InMemoryRounds(self::tenant(self::BETA_BAKERY));
        $otherTenant->stored = $rounds->stored;
        $copy = $rounds->get($never->id());
        self::assertNotNull($copy);
        $copy->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        try {
            $otherTenant->save($copy, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
            self::fail('Another organization saved the round.');
        } catch (RoundChanged) {
            self::assertNull($otherTenant->stored[0]->deletedAt());
            self::assertSame(1, $otherTenant->stored[0]->version());
        }
    }

    #[Test]
    public function aSaveThatGivesARoundANumberTheTicketHasIsASlotTakenAndStoresNothing(): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $ticket = new TicketId(self::TICKET);
        $rounds->add(self::draftWithAScreenshot($rounds, self::OMAR));
        $rounds->add(self::draftWithAScreenshot($rounds, self::LENA));
        $omars = $rounds->openUnsharedDraftOf($ticket, new UserId(self::OMAR));
        $lenas = $rounds->openUnsharedDraftOf($ticket, new UserId(self::LENA));
        self::assertNotNull($omars);
        self::assertNotNull($lenas);
        $omars->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $lenas->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
        $rounds->save($omars, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));

        try {
            $rounds->save($lenas, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
            self::fail('Two rounds took one number.');
        } catch (RoundSlotTaken) {
            self::assertNull($rounds->get($lenas->id())?->number());
            self::assertSame($lenas->savedVersion(), $rounds->get($lenas->id())?->version());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function movesOfAStoredPicture(): iterable
    {
        yield 'another position' => [self::MEDIA, 5];
        yield 'another media' => ['0197a3c0-0000-7000-8000-000000000d02', 0];
    }

    #[Test]
    #[DataProvider('movesOfAStoredPicture')]
    public function aSaveThatMovesAStoredPictureToAnotherMediaOrPositionIsALogicException(string $mediaId, int $position): void
    {
        $rounds = self::roundsOf(self::ACME_FOODS);
        $report = self::report($rounds, self::TICKET);
        $rounds->add($report);
        $moved = Round::restored($report->id(), $report->organizationId(), $report->ticketId(), $report->kind(), $report->ownerId(), $report->number(), $report->sharedAt(), $report->state(), 1, [Screenshot::of($report->screenshots()[0]->id(), $mediaId, $position, null, null, [])], $report->createdAt(), null, null, null);

        try {
            $rounds->save($moved, new \DateTimeImmutable('2026-03-05 08:00:00+00:00'));
            self::fail('A stored picture was moved.');
        } catch (\LogicException $logic) {
            self::assertStringContainsString('keeps its picture and position', $logic->getMessage());
        }

        self::assertSame(0, $rounds->stored[0]->screenshots()[0]->position());
    }

    private static function tenant(string $organizationId): TenantContext
    {
        $tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
        $tenantContext->set(new OrganizationId($organizationId));

        return $tenantContext;
    }

    private static function roundsOf(string $organizationId): InMemoryRounds
    {
        return new InMemoryRounds(self::tenant($organizationId));
    }

    private static function report(InMemoryRounds $rounds, string $ticketId): Round
    {
        $screenshot = Screenshot::of($rounds->nextScreenshotIdentity(), self::MEDIA, 0, null, null, []);

        return Round::report($rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId(self::LENA), [$screenshot], new \DateTimeImmutable('2026-03-04 09:30:00+00:00'));
    }

    private static function draft(InMemoryRounds $rounds, string $ownerId): Round
    {
        return Round::draft($rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId($ownerId), new \DateTimeImmutable('2026-03-04 10:00:00+00:00'));
    }

    /**
     * A round of Acme Foods as a read gives it, with one screenshot; shared exactly when it has a number.
     */
    private static function stored(InMemoryRounds $rounds, RoundKind $kind, string $ticketId, string $ownerId, ?int $number, RoundState $state): Round
    {
        return Round::restored(
            $rounds->nextIdentity(),
            new OrganizationId(self::ACME_FOODS),
            new TicketId($ticketId),
            $kind,
            new UserId($ownerId),
            $number,
            null === $number ? null : new \DateTimeImmutable('2026-03-05 08:00:00+00:00'),
            $state,
            1,
            [Screenshot::of($rounds->nextScreenshotIdentity(), self::MEDIA, 0, null, null, [])],
            new \DateTimeImmutable('2026-03-04 10:00:00+00:00'),
            null,
            null,
            null,
        );
    }

    /**
     * A draft that may be shared, as one with no screenshot may not.
     */
    private static function draftWithAScreenshot(InMemoryRounds $rounds, string $ownerId): Round
    {
        $draft = self::draft($rounds, $ownerId);
        $draft->addScreenshot(new UserId($ownerId), $rounds->nextScreenshotIdentity(), self::MEDIA);

        return $draft;
    }
}
