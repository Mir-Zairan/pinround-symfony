<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Infrastructure\Console\SeedReviewRoundCommand;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The prod guard of this command is in SeedDraftWithPictureCommandTest, which boots one prod kernel for both commands.
 */
final class SeedReviewRoundCommandTest extends KernelTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsAssignedDemoTicket;
    use SeedsTicketRounds;

    private const string COMMAND = 'app:e2e:seed-review-round';
    private const array NO_ROWS = ['rounds' => 0, 'media' => 0, 'screenshots' => 0, 'pins' => 0, 'areas' => 0];

    /** Read by SeedsTicketRounds. */
    protected string $shopProject;
    private string $stepId;

    /** @var array<string, User> */
    private array $people;

    private KernelInterface $testKernel;

    protected function setUp(): void
    {
        $this->testKernel = self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    public function testItRefusesAnUnknownOrganizationAndWritesNothing(): void
    {
        $this->seedAssignedTicket();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'no-such-organization', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('That organization does not exist.', $tester->getDisplay());
        self::assertSame(self::NO_ROWS, $this->rowCounts());
    }

    public function testItRefusesAnUnknownTicketAndWritesNothing(): void
    {
        $this->seedAssignedTicket();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-99']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('That ticket does not exist.', $tester->getDisplay());
        self::assertSame(self::NO_ROWS, $this->rowCounts());
    }

    public function testItRefusesATicketGivenToNoOneAndWritesNothing(): void
    {
        $ticketId = $this->seedAssignedTicket();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement('UPDATE tkt_ticket SET assignee_id = NULL WHERE id = ?', [$ticketId]));
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('That ticket is not given to anyone, so there is no one to own the rounds.', $tester->getDisplay());
        self::assertSame(self::NO_ROWS, $this->rowCounts());
    }

    public function testItGivesTheTicketASharedRoundWithPinsAndAReviewRoundWithAnAreaPerPictureAndTouchesNoOtherTicket(): void
    {
        $ticketId = $this->seedAssignedTicket();
        $otherTicketId = $this->addTicket('SHOP-2', number: 2);
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Round 2 on SHOP-1 has 2 screen areas with ready pictures.', $tester->getDisplay());
        self::assertSame([], $this->roundRowsOf($otherTicketId));
        $rounds = $this->roundRowsOf($ticketId);
        $omar = $this->people['Omar']->id()->toString();
        self::assertSame(
            [['kind' => 'draft', 'number' => 1, 'owner_id' => $omar, 'state' => 'open'], ['kind' => 'review', 'number' => 2, 'owner_id' => $omar, 'state' => 'open']],
            array_map(static fn (array $round): array => ['kind' => $round['kind'], 'number' => $round['number'], 'owner_id' => $round['owner_id'], 'state' => $round['state']], $rounds),
        );
        self::assertNotNull($rounds[0]['shared_at']);
        self::assertNotNull($rounds[1]['shared_at']);
        $earlierShots = $this->screenshotRowsOf(self::idOf($rounds[0]));
        self::assertSame(SeedReviewRoundCommand::AREA_NAMES, array_column($earlierShots, 'title'));
        self::assertSame(
            [[$earlierShots[0]['id'], 1, SeedReviewRoundCommand::PIN_NOTES[0]], [$earlierShots[0]['id'], 2, SeedReviewRoundCommand::PIN_NOTES[1]]],
            array_map(static fn (array $pin): array => [$pin['screenshot_id'], $pin['number'], $pin['note']], $this->pinRowsOf(self::idOf($rounds[0]))),
        );
        $reviewShots = $this->screenshotRowsOf(self::idOf($rounds[1]));
        self::assertSame(SeedReviewRoundCommand::AREA_NAMES, array_column($reviewShots, 'title'));
        self::assertSame(
            [
                ['name' => 'Cart summary', 'position' => 0, 'before_screenshot_id' => $earlierShots[0]['id'], 'after_screenshot_id' => $reviewShots[0]['id'], 'verdict' => null],
                ['name' => 'Checkout button', 'position' => 1, 'before_screenshot_id' => $earlierShots[1]['id'], 'after_screenshot_id' => $reviewShots[1]['id'], 'verdict' => null],
            ],
            $this->areaRowsOf(self::idOf($rounds[1])),
        );
        self::assertSame(['ready'], $this->mediaStatuses());
        self::assertSame(['rounds' => 2, 'media' => 4, 'screenshots' => 4, 'pins' => 2, 'areas' => 2], $this->rowCounts());
    }

    public function testItGivesTheFirstPinTheLongNoteAndLeavesTheNamedAreasAfterPicturePending(): void
    {
        $ticketId = $this->seedAssignedTicket();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1', '--long-note' => true, '--pending-after' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('The "after" picture of Checkout button is still processing.', $tester->getDisplay());
        $rounds = $this->roundRowsOf($ticketId);
        self::assertCount(2, $rounds);
        self::assertSame(
            [SeedReviewRoundCommand::LONG_PIN_NOTE, SeedReviewRoundCommand::PIN_NOTES[1]],
            array_column($this->pinRowsOf(self::idOf($rounds[0])), 'note'),
        );
        self::assertSame([[0, 'ready'], [1, 'pending']], $this->afterPictureStatusesOf(self::idOf($rounds[1])));
        self::assertSame(['rounds' => 2, 'media' => 4, 'screenshots' => 4, 'pins' => 2, 'areas' => 2], $this->rowCounts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function positionsOfNoArea(): iterable
    {
        yield 'past the last area' => ['2'];
        yield 'negative' => ['-1'];
        yield 'a leading zero' => ['01'];
        yield 'a word' => ['first'];
        yield 'empty' => [''];
    }

    #[DataProvider('positionsOfNoArea')]
    public function testItRefusesAPendingAfterPositionOfNoAreaAndWritesNothing(string $position): void
    {
        $this->seedAssignedTicket();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1', '--pending-after' => $position]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('There is no screen area at that position; give 0 to 1.', $tester->getDisplay());
        self::assertSame(self::NO_ROWS, $this->rowCounts());
    }

    public function testItRefusesATicketThatHasRoundsAlreadyAndWritesNothingMore(): void
    {
        $this->seedAssignedTicket();
        $firstRun = $this->commandTester();
        self::assertSame(Command::SUCCESS, $firstRun->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1']), $firstRun->getDisplay());
        $afterTheFirstRun = $this->rowCounts();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('That ticket has rounds already; seed the review round on a new ticket.', $tester->getDisplay());
        self::assertSame($afterTheFirstRun, $this->rowCounts());
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester(new Application($this->testKernel)->find(self::COMMAND));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function idOf(array $row): string
    {
        return \is_string($row['id']) ? $row['id'] : throw new \LogicException('A stored id is a string.');
    }

    /**
     * @return list<array<string, mixed>> the round's areas as stored, by position
     */
    private function areaRowsOf(string $roundId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$rows): int {
            $rows = $migrator->fetchAllAssociative('SELECT name, position, before_screenshot_id, after_screenshot_id, verdict FROM rvw_area WHERE round_id = ? ORDER BY position', [$roundId]);

            return \count($rows);
        });

        return $rows;
    }

    /**
     * @return list<array{mixed, mixed}> the position of each area of the round and the status of its after picture
     */
    private function afterPictureStatusesOf(string $roundId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                'SELECT a.position, m.status FROM rvw_area a JOIN rvw_screenshot s ON s.id = a.after_screenshot_id JOIN med_object m ON m.id = s.media_id WHERE a.round_id = ? ORDER BY a.position',
                [$roundId],
            );

            return \count($rows);
        });

        return array_map(static fn (array $row): array => [$row[0], $row[1]], $rows);
    }

    /**
     * @return list<mixed> each status of Acme Foods' media once
     */
    private function mediaStatuses(): array
    {
        $statuses = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$statuses): int {
            $statuses = $migrator->fetchFirstColumn('SELECT DISTINCT status FROM med_object');

            return \count($statuses);
        });

        return $statuses;
    }

    /**
     * @return array{rounds: int, media: int, screenshots: int, pins: int, areas: int} the rows of Acme Foods
     */
    private function rowCounts(): array
    {
        $counts = self::NO_ROWS;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$counts): int {
            $count = static fn (string $table): int => \is_int($rows = $migrator->fetchOne('SELECT count(*) FROM '.$table)) ? $rows : throw new \LogicException('A count is a whole number.');
            $counts = ['rounds' => $count('rvw_round'), 'media' => $count('med_object'), 'screenshots' => $count('rvw_screenshot'), 'pins' => $count('rvw_pin'), 'areas' => $count('rvw_area')];

            return 0;
        });

        return $counts;
    }
}
