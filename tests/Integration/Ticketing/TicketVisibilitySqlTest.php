<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Infrastructure\Doctrine\TicketVisibilitySql;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL condition and the PHP check of TicketVisibility agree (ADR-0013): on real tkt_ticket rows, the condition
 * finds exactly the tickets the PHP check lets each viewer see.
 */
final class TicketVisibilitySqlTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000034a1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000034d1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000034c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000034c2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000034b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000034b2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000034b3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000034b4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000034b5';

    /**
     * By ticket id: visibility, team, reporter and the other people by column. Mia, of the other team, reports most;
     * Omar, in no team, is the one person on each people ticket.
     */
    private const array TICKETS = [
        '0197a3c0-0000-7000-8000-0000000034e1' => ['all', self::OTHER_TEAM, self::MIA, []],
        '0197a3c0-0000-7000-8000-0000000034e2' => ['team', self::SHOP_TEAM, self::LENA, []],
        '0197a3c0-0000-7000-8000-0000000034e3' => ['team', self::OTHER_TEAM, self::OMAR, []],
        '0197a3c0-0000-7000-8000-0000000034e4' => ['team', self::OTHER_TEAM, self::MIA, ['assignee_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e5' => ['team', self::OTHER_TEAM, self::MIA, ['pending_assignee_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e6' => ['team', self::OTHER_TEAM, self::MIA, ['reviewer_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e7' => ['team', self::OTHER_TEAM, self::MIA, ['tester_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e8' => ['team', self::OTHER_TEAM, self::MIA, ['waiting_on_user_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e9' => ['team', null, self::MIA, []],
    ];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $number = 1;
        foreach (self::TICKETS as $ticketId => [$visibility, $teamId, $reporterId, $otherPeople]) {
            $this->rows()->addWithAudienceOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, $number++, $visibility, $teamId, $reporterId, $otherPeople);
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    /**
     * @param list<string> $expectedTicketNumbers
     */
    #[Test]
    #[DataProvider('viewers')]
    public function theConditionFindsTheTicketsThePhpCheckShows(TicketVisibility $viewer, array $expectedTicketNumbers): void
    {
        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->ticketNumbersFound($viewer));

        self::assertSame($expectedTicketNumbers, $found);
        self::assertSame($expectedTicketNumbers, self::ticketNumbersShownBy($viewer));
    }

    /**
     * @return iterable<string, array{TicketVisibility, list<string>}>
     */
    public static function viewers(): iterable
    {
        $triager = new UserId(self::LENA);
        yield 'Lena, in the SHOP team and the triager' => [TicketVisibility::for(new UserId(self::LENA), [new TeamId(self::SHOP_TEAM)], false, $triager), ['1', '2', '9']];
        yield 'Omar, in no team, on six tickets' => [TicketVisibility::for(new UserId(self::OMAR), [], false, $triager), ['1', '3', '4', '5', '6', '7', '8']];
        yield 'Mia, in the other team' => [TicketVisibility::for(new UserId(self::MIA), [new TeamId(self::OTHER_TEAM)], false, $triager), ['1', '3', '4', '5', '6', '7', '8', '9']];
        yield 'Raj, a manager without a team role' => [TicketVisibility::for(new UserId(self::RAJ), [], false, $triager), ['1']];
        yield 'Sara, an admin without a team role' => [TicketVisibility::for(new UserId(self::SARA), [], true, $triager), ['1', '2', '3', '4', '5', '6', '7', '8', '9']];
    }

    #[Test]
    public function twoConditionsInOneQueryKeepTheirOwnViewers(): void
    {
        $triager = new UserId(self::LENA);
        $omar = TicketVisibilitySql::of(TicketVisibility::for(new UserId(self::OMAR), [], false, $triager), 'shown');
        $lena = TicketVisibilitySql::of(TicketVisibility::for(new UserId(self::LENA), [new TeamId(self::SHOP_TEAM)], false, $triager), 'also_shown');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT shown.number::text FROM tkt_ticket shown WHERE '.$omar->sql()
            .' AND EXISTS (SELECT 1 FROM tkt_ticket also_shown WHERE also_shown.id = shown.id AND '.$lena->sql().') ORDER BY shown.number',
            [...$omar->parameters(), ...$lena->parameters()],
            [...$omar->types(), ...$lena->types()],
        ));

        self::assertSame(['1'], $found);
    }

    #[Test]
    public function theTurnReadModelCarriesTheColumnsTheConditionReads(): void
    {
        $condition = TicketVisibilitySql::of(TicketVisibility::for(new UserId(self::OMAR), [], false, new UserId(self::LENA)), 'tt');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT tt.ticket_id FROM ins_ticket_turn tt WHERE '.$condition->sql(),
            $condition->parameters(),
            $condition->types(),
        ));

        self::assertSame([], $found, 'The tickets of this test have no turn rows.');
    }

    /**
     * @return list<string>
     */
    private function ticketNumbersFound(TicketVisibility $viewer): array
    {
        $condition = TicketVisibilitySql::of($viewer, 't');

        return array_map(
            static fn (mixed $number): string => \is_string($number) ? $number : throw new \UnexpectedValueException('A ticket number reads as text.'),
            $this->connection()->fetchFirstColumn(
                'SELECT t.number::text FROM tkt_ticket t WHERE '.$condition->sql().' ORDER BY t.number',
                $condition->parameters(),
                $condition->types(),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private static function ticketNumbersShownBy(TicketVisibility $viewer): array
    {
        $numbers = [];
        $number = 1;
        foreach (self::TICKETS as [$visibility, $teamId, $reporterId, $otherPeople]) {
            $ticket = new TicketVisibilityFacts(
                $visibility,
                null === $teamId ? null : new TeamId($teamId),
                new UserId($reporterId),
                ...array_map(static fn (string $userId): UserId => new UserId($userId), self::byArgumentName($otherPeople)),
            );
            if ($viewer->isVisible($ticket)) {
                $numbers[] = (string) $number;
            }
            ++$number;
        }

        return $numbers;
    }

    /**
     * @param array<string, string> $peopleByColumn
     *
     * @return array<string, string>
     */
    private static function byArgumentName(array $peopleByColumn): array
    {
        $byArgumentName = [];
        foreach ($peopleByColumn as $column => $userId) {
            $byArgumentName[lcfirst(str_replace('_', '', ucwords($column, '_')))] = $userId;
        }

        return $byArgumentName;
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
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
