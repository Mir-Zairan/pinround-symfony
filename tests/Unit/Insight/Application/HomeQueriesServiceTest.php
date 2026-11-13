<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
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

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null);

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

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), null, null);

        self::assertSame([self::OMAR, self::MIA], array_map(static fn (TurnGroup $group): string => $group->groupKey(), $groups));
    }

    #[Test]
    public function itPassesTheProjectAndTheOpenedGroupOnToTheRows(): void
    {
        $rows = new FixedWhoseTurnRows([]);

        $groups = new HomeQueriesService($rows)->whoseTurn(self::lena(), self::SHOP_PROJECT, 'Customer');

        self::assertSame([[], [[self::SHOP_PROJECT, 'Customer']]], [$groups, $rows->calls]);
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
            new HomeQueriesService($rows)->whoseTurn(self::lena(), $projectId, $openedGroup);
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

        new HomeQueriesService($rows)->whoseTurn(self::lena(), null, str_repeat('é', 100));

        self::assertSame([[null, str_repeat('é', 100)]], $rows->calls);
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
