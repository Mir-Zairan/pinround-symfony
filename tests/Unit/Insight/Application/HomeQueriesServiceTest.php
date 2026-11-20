<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;
use App\Insight\Application\HomeQueriesService;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HomeQueriesServiceTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a801';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000a821';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a851';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a852';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a853';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000a854';

    /**
     * Given in a shuffled order: Lena, the viewer, waits the shortest; Raj and the Customer label share the oldest wait;
     * Mia and Omar share the next one, Mia given first though her id is the larger.
     */
    #[Test]
    public function theViewersGroupComesFirstThenTheOldestWaitWithPeopleBeforeLabelsThenByIdOnATie(): void
    {
        $rows = new FixedWhoseTurnRows([
            self::personGroup(self::MIA, '2027-03-05 07:00:00'),
            self::labelGroup('Customer', '2027-03-01 06:00:00'),
            self::personGroup(self::LENA, '2027-03-12 09:00:00'),
            self::labelGroup('Supplier delivery', '2027-03-08 06:00:00'),
            self::personGroup(self::OMAR, '2027-03-05 07:00:00'),
            self::personGroup(self::RAJ, '2027-03-01 06:00:00'),
        ]);

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, null)->groups;

        self::assertSame(
            [self::LENA, self::RAJ, 'Customer', self::OMAR, self::MIA, 'Supplier delivery'],
            array_map(static fn (TurnGroup $group): string => $group->groupKey(), $groups),
        );
    }

    #[Test]
    public function withoutTheViewersGroupTheOldestWaitComesFirst(): void
    {
        $rows = new FixedWhoseTurnRows([
            self::personGroup(self::MIA, '2027-03-09 07:00:00'),
            self::personGroup(self::OMAR, '2027-03-02 07:00:00'),
        ]);

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, null)->groups;

        self::assertSame([self::OMAR, self::MIA], array_map(static fn (TurnGroup $group): string => $group->groupKey(), $groups));
    }

    /**
     * Labels "10" and "9" share their oldest wait: byte by byte, as the database's keyset goes, "10" comes first,
     * though PHP's <=> would put the number 9 first.
     */
    #[Test]
    public function numericLabelsOfOneOldestWaitGoByteByByte(): void
    {
        $rows = new FixedWhoseTurnRows([
            self::labelGroup('9', '2027-03-05 07:00:00'),
            self::labelGroup('10', '2027-03-05 07:00:00'),
        ]);

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, null)->groups;

        self::assertSame(['10', '9'], array_map(static fn (TurnGroup $group): string => $group->groupKey(), $groups));
    }

    /**
     * The rows give the viewer's group and 21 others, one more than a page, given in reverse order: the page keeps the
     * viewer's group and the 20 oldest waits, and its cursor is after the 20th.
     */
    #[Test]
    public function aPageHoldsTheViewersGroupAndTwentyOthersAndACursorAfterTheTwentieth(): void
    {
        $otherGroups = [];
        for ($day = 21; $day >= 1; --$day) {
            $otherGroups[] = self::labelGroup(\sprintf('Supplier %02d', $day), \sprintf('2027-03-%02d 06:00:00', $day));
        }
        $rows = new FixedWhoseTurnRows([...$otherGroups, self::personGroup(self::LENA, '2027-03-25 09:00:00')], 40);

        $page = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, null);

        $expectedKeys = [self::LENA];
        for ($day = 1; $day <= 20; ++$day) {
            $expectedKeys[] = \sprintf('Supplier %02d', $day);
        }
        self::assertSame($expectedKeys, array_map(static fn (TurnGroup $group): string => $group->groupKey(), $page->groups));
        self::assertSame(40, $page->ticketCount);
        self::assertNotNull($page->nextCursor);
        self::assertSame(
            ['2027-03-20 06:00:00', null, 'Supplier 20'],
            [$page->nextCursor->oldestWaitingSince->format('Y-m-d H:i:s'), $page->nextCursor->turnUserId, $page->nextCursor->turnLabel],
        );
    }

    #[Test]
    public function aPageOfTwentyOtherGroupsIsTheLastAndHasNoCursor(): void
    {
        $otherGroups = [];
        for ($day = 20; $day >= 1; --$day) {
            $otherGroups[] = self::labelGroup(\sprintf('Supplier %02d', $day), \sprintf('2027-03-%02d 06:00:00', $day));
        }
        $rows = new FixedWhoseTurnRows([self::personGroup(self::LENA, '2027-03-25 09:00:00'), ...$otherGroups]);

        $page = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, null);

        self::assertCount(21, $page->groups);
        self::assertSame('Supplier 20', $page->groups[20]->groupKey());
        self::assertNull($page->nextCursor);
    }

    /**
     * The database leaves the viewer's group out after a cursor (WhoseTurnTest proves it), so the rows hold none here.
     */
    #[Test]
    public function aNextPagePassesTheCursorOnOrdersTheRowsAndHasNoCursorUnderAFullPage(): void
    {
        $after = new TurnGroupCursor(new \DateTimeImmutable('2027-03-01 06:00:00 UTC'), null, 'Customer');
        $rows = new FixedWhoseTurnRows([
            self::personGroup(self::MIA, '2027-03-09 07:00:00'),
            self::personGroup(self::OMAR, '2027-03-02 07:00:00'),
        ], 7);

        $page = new HomeQueriesService($rows)->whoseTurn(self::lena(), self::SHOP_PROJECT, null, $after);

        self::assertSame([self::OMAR, self::MIA], array_map(static fn (TurnGroup $group): string => $group->groupKey(), $page->groups));
        self::assertSame(7, $page->ticketCount);
        self::assertNull($page->nextCursor);
        self::assertSame([[self::SHOP_PROJECT, null, $after, 21, null]], $rows->calls);
    }

    #[Test]
    public function aCursorLabelOfExactly100MultibyteCharactersIsRead(): void
    {
        $after = new TurnGroupCursor(new \DateTimeImmutable('2027-03-01 06:00:00 UTC'), null, str_repeat('é', 100));
        $rows = new FixedWhoseTurnRows([]);

        new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, $after);

        self::assertSame([[null, null, $after, 21, null]], $rows->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCursorLabels(): iterable
    {
        yield 'a label of 101 characters' => [str_repeat('é', 101)];
        yield 'a label that is not UTF-8' => ["Customer\xC3"];
    }

    #[Test]
    #[DataProvider('refusedCursorLabels')]
    public function aCursorWithAMalformedLabelIsRefusedBeforeTheRowsAreRead(string $label): void
    {
        $rows = new FixedWhoseTurnRows([self::personGroup(self::OMAR, '2027-03-05 07:00:00')]);

        try {
            new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, new TurnGroupCursor(new \DateTimeImmutable('2027-03-01 06:00:00 UTC'), null, $label));
            self::fail('The read must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('A whose-turn cursor\'s label has at most 100 characters.', $exception->getMessage());
        }
        self::assertSame([], $rows->calls, 'No rows were read before the refusal.');
    }

    #[Test]
    public function itPassesTheProjectAndTheOpenedGroupOnToTheRows(): void
    {
        $rows = new FixedWhoseTurnRows([]);

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), self::SHOP_PROJECT, 'Customer', null)->groups;

        self::assertSame([[], [[self::SHOP_PROJECT, 'Customer', null, 21, null]]], [$groups, $rows->calls]);
    }

    #[Test]
    public function itPassesTheSearchOnToTheRowsAndKeepsTheirSearchCap(): void
    {
        $rows = new FixedWhoseTurnRows([self::personGroup(self::OMAR, '2027-03-05 07:00:00')], 1000, true);

        $page = new HomeQueriesService($rows)->whoseTurn(self::lena(), self::SHOP_PROJECT, null, null, 'cart total');

        self::assertSame([[self::SHOP_PROJECT, null, null, 21, 'cart total']], $rows->calls);
        self::assertSame([1000, true], [$page->ticketCount, $page->isTicketCountCapped]);
    }

    #[Test]
    public function aPageOfNoSearchIsNotCapped(): void
    {
        $page = new HomeQueriesService(new FixedWhoseTurnRows([self::personGroup(self::OMAR, '2027-03-05 07:00:00')], 1))->whoseTurn(self::lena(), null, null, null);

        self::assertSame([1, false], [$page->ticketCount, $page->isTicketCountCapped]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedSearchTexts(): iterable
    {
        yield 'an empty search' => [''];
        yield 'a search with a leading space' => [' cart'];
        yield 'a search of 201 characters' => [str_repeat('é', 201)];
        yield 'a search that is not UTF-8' => ["cart\xC3"];
        yield 'a search with a NUL' => ["cart\0"];
    }

    #[Test]
    #[DataProvider('refusedSearchTexts')]
    public function aSearchTheListWouldRefuseIsRefusedBeforeTheRowsAreRead(string $searchText): void
    {
        $rows = new FixedWhoseTurnRows([self::personGroup(self::OMAR, '2027-03-05 07:00:00')]);

        try {
            new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null, null, $searchText);
            self::fail('The read must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Whose turn is searched with a trimmed text of 1 to 200 characters, valid UTF-8 without NUL.', $exception->getMessage());
        }
        self::assertSame([], $rows->calls, 'No rows were read before the refusal.');
    }

    /**
     * @return iterable<string, array{string|null, string|null, string}>
     */
    public static function refusedInputs(): iterable
    {
        $projectRefusal = 'Whose turn is read in a project given by its canonical lowercase uuid.';
        $groupRefusal = 'The opened whose-turn group is a user id or a label of at most 100 characters.';

        yield 'an upper-case project id' => [strtoupper(self::SHOP_PROJECT), null, $projectRefusal];
        yield 'a project id with a trailing newline' => [self::SHOP_PROJECT."\n", null, $projectRefusal];
        yield 'a project name' => ['SHOP', null, $projectRefusal];
        yield 'an opened group of 101 characters' => [null, str_repeat('é', 101), $groupRefusal];
        yield 'an opened group that is not UTF-8' => [null, "Customer\xC3", $groupRefusal];
    }

    #[Test]
    #[DataProvider('refusedInputs')]
    public function aMalformedProjectOrOpenedGroupIsRefusedBeforeTheRowsAreRead(?string $projectId, ?string $openedGroup, string $refusal): void
    {
        $rows = new FixedWhoseTurnRows([self::personGroup(self::OMAR, '2027-03-05 07:00:00')]);

        try {
            new HomeQueriesService($rows)->whoseTurn(self::lena(), $projectId, $openedGroup, null);
            self::fail('The read must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($refusal, $exception->getMessage());
        }
        self::assertSame([], $rows->calls, 'No rows were read before the refusal.');
    }

    #[Test]
    public function anOpenedGroupOfExactly100MultibyteCharactersIsRead(): void
    {
        $rows = new FixedWhoseTurnRows([]);

        new HomeQueriesService($rows)->whoseTurn(self::lena(), null, str_repeat('é', 100), null);

        self::assertSame([[null, str_repeat('é', 100), null, 21, null]], $rows->calls);
    }

    private static function personGroup(string $userId, string $oldestWaitingSince): TurnGroup
    {
        return new TurnGroup(new UserId($userId), null, 1, new \DateTimeImmutable($oldestWaitingSince.' UTC'), []);
    }

    private static function labelGroup(string $label, string $oldestWaitingSince): TurnGroup
    {
        return new TurnGroup(null, $label, 1, new \DateTimeImmutable($oldestWaitingSince.' UTC'), []);
    }

    private static function lena(): TicketViewer
    {
        return new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], false, false);
    }
}
