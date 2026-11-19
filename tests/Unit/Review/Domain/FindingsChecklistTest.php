<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\FindingsChecklist;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mia found two problems on SHOP-42 while Omar is its assignee: her shared draft is findings, Omar's would be his
 * self-check (ADR-0044).
 */
final class FindingsChecklistTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000f001';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000f011';
    private const string ROUND = '0197a3c0-0000-7000-8000-00000000f021';
    private const string FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-00000000f031';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-00000000f032';
    private const string FIRST_PIN = '0197a3c0-0000-7000-8000-00000000f041';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-00000000f042';
    private const string THIRD_PIN = '0197a3c0-0000-7000-8000-00000000f043';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000f051';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000f052';
    private const string MEDIA = '0197a3c0-0000-7000-8000-00000000f061';
    private const string SHARED_AT = '2027-03-15 10:00:00+00:00';

    private int $issuedItemIds = 0;

    #[Test]
    public function aDraftOfSomeoneButTheAssigneeGetsAnOpenItemPerPinByPictureThenPinNumber(): void
    {
        $checklist = FindingsChecklist::ofSharedDraft($this->sharedDraftOf(self::MIA), new UserId(self::OMAR), $this->nextItemId(...), new \DateTimeImmutable(self::SHARED_AT));

        self::assertNotNull($checklist);
        self::assertSame([self::ROUND, self::ACME_FOODS, self::SHARED_AT], [$checklist->roundId()->toString(), $checklist->organizationId()->toString(), $checklist->createdAt()->format('Y-m-d H:i:sP')]);
        self::assertSame(
            [self::SECOND_PIN, self::FIRST_PIN, self::THIRD_PIN],
            array_map(static fn (CheckItem $item): string => $item->pinId()->toString(), $checklist->items()),
            'Pin 1 then pin 2 of the first picture, then the second picture.',
        );
        foreach ($checklist->items() as $item) {
            self::assertSame([self::ROUND, self::TICKET, CheckItemState::Open], [$item->roundId()->toString(), $item->ticketId()->toString(), $item->state()]);
        }
        self::assertSame(3, $this->issuedItemIds, 'One new id per pin.');
    }

    #[Test]
    public function theAssigneesOwnDraftIsASelfCheckAndGetsNone(): void
    {
        self::assertNull(FindingsChecklist::ofSharedDraft($this->sharedDraftOf(self::OMAR), new UserId(self::OMAR), $this->nextItemId(...), new \DateTimeImmutable(self::SHARED_AT)));
        self::assertSame(0, $this->issuedItemIds);
    }

    #[Test]
    public function onATicketWithNoAssigneeEveryDraftIsFindings(): void
    {
        $checklist = FindingsChecklist::ofSharedDraft($this->sharedDraftOf(self::OMAR), null, $this->nextItemId(...), new \DateTimeImmutable(self::SHARED_AT));

        self::assertNotNull($checklist);
        self::assertCount(3, $checklist->items());
    }

    #[Test]
    public function findingsWithoutPinsGetNone(): void
    {
        $mia = new UserId(self::MIA);
        $draft = Round::draft(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $mia, new \DateTimeImmutable(self::SHARED_AT));
        $draft->addScreenshot($mia, new ScreenshotId(self::FIRST_SCREENSHOT), self::MEDIA);
        $draft->share($mia, 2, new \DateTimeImmutable(self::SHARED_AT));

        self::assertNull(FindingsChecklist::ofSharedDraft($draft, new UserId(self::OMAR), $this->nextItemId(...), new \DateTimeImmutable(self::SHARED_AT)));
    }

    #[Test]
    public function anUnsharedDraftIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Only a shared draft round has findings to check.'));

        FindingsChecklist::ofSharedDraft($this->draftOf(self::MIA), new UserId(self::OMAR), $this->nextItemId(...), new \DateTimeImmutable(self::SHARED_AT));
    }

    #[Test]
    public function aDeletedDraftIsRefused(): void
    {
        $draft = $this->draftOf(self::MIA);
        $draft->delete(new UserId(self::MIA), new \DateTimeImmutable(self::SHARED_AT));

        $this->expectExceptionObject(new \InvalidArgumentException('Only a shared draft round has findings to check.'));

        FindingsChecklist::ofSharedDraft($draft, new UserId(self::OMAR), $this->nextItemId(...), new \DateTimeImmutable(self::SHARED_AT));
    }

    private function nextItemId(): CheckItemId
    {
        return new CheckItemId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xF100 + ++$this->issuedItemIds));
    }

    private function sharedDraftOf(string $ownerId): Round
    {
        $draft = $this->draftOf($ownerId);
        $draft->share(new UserId($ownerId), 2, new \DateTimeImmutable(self::SHARED_AT));

        return $draft;
    }

    /**
     * Two pictures: pins 2 and 1 on the first, given out of order, and pin 1 on the second.
     */
    private function draftOf(string $ownerId): Round
    {
        $owner = new UserId($ownerId);
        $draft = Round::draft(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $owner, new \DateTimeImmutable(self::SHARED_AT));
        $draft->addScreenshot($owner, new ScreenshotId(self::FIRST_SCREENSHOT), self::MEDIA);
        $draft->addScreenshot($owner, new ScreenshotId(self::SECOND_SCREENSHOT), self::MEDIA);
        $draft->replacePins($owner, new ScreenshotId(self::FIRST_SCREENSHOT), [
            Pin::placedBy(new PinId(self::FIRST_PIN), 2, 20.0, 20.0, 'Shown twice', $owner),
            Pin::placedBy(new PinId(self::SECOND_PIN), 1, 10.0, 10.0, 'Cut off', $owner),
        ]);
        $draft->replacePins($owner, new ScreenshotId(self::SECOND_SCREENSHOT), [Pin::placedBy(new PinId(self::THIRD_PIN), 1, 50.0, 50.0, 'Wrong colour', $owner)]);

        return $draft;
    }
}
