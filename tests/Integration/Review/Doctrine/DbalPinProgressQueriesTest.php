<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Infrastructure\Doctrine\DbalPinProgressQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\PinProgress;
use App\Ticketing\Api\PinProgressQueries;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pin progress of a page of tickets, on the real rvw_round and rvw_check_item under row-level security. Each ticket
 * has a Round 0 whose pins the checklists check.
 */
final class DbalPinProgressQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fa501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000fa502';
    /** A checklist round, and a closed findings round over it. */
    private const string CHECKLIST_TICKET = '0197a3c0-0000-7000-8000-0000000fa511';
    /** A checklist round, and an open findings round shared after it. */
    private const string FINDINGS_TICKET = '0197a3c0-0000-7000-8000-0000000fa512';
    /** Only an unshared draft with items, and a review round. */
    private const string DRAFT_TICKET = '0197a3c0-0000-7000-8000-0000000fa513';
    /** A checklist round, but the page does not ask for it. */
    private const string NOT_ASKED_FOR_TICKET = '0197a3c0-0000-7000-8000-0000000fa514';
    private const string NO_ROUNDS_TICKET = '0197a3c0-0000-7000-8000-0000000fa515';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000fa521';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000fa522';
    private const string CREATED_AT = '2027-03-15 10:00:00+00';

    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            ...$this->ticketRounds(self::ACME_FOODS, self::CHECKLIST_TICKET, [
                ['checklist', null, 'open', ['ok', 'fixed']],
                ['draft', 2, 'closed', ['ok', 'ok', 'ok']],
            ]),
            // Inserted highest number first, so the round picked is the highest number's, not the first row's.
            ...$this->ticketRounds(self::ACME_FOODS, self::FINDINGS_TICKET, [
                ['draft', 3, 'open', ['ok', 'not_fixed', 'ok']],
                ['checklist', null, 'open', ['ok']],
                ['review', 2, 'open', []],
            ]),
            ...$this->ticketRounds(self::ACME_FOODS, self::DRAFT_TICKET, [
                ['draft', null, 'open', ['ok']],
                ['review', 1, 'open', []],
            ]),
            ...$this->ticketRounds(self::ACME_FOODS, self::NOT_ASKED_FOR_TICKET, [
                ['checklist', null, 'open', ['ok']],
            ]),
        ]);
        // The same ticket id under another organization: only row-level security and the organization filter keep it out.
        $this->roundRows()->addAllOf(self::BETA_BAKERY, $this->ticketRounds(self::BETA_BAKERY, self::CHECKLIST_TICKET, [
            ['checklist', null, 'open', ['open', 'open', 'open', 'ok']],
        ]));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function eachTicketCountsTheOkPinsOfItsOpenSharedChecklistWithTheHighestNumber(): void
    {
        $progress = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->pinProgressOf(
            self::ticketIds(self::CHECKLIST_TICKET, self::FINDINGS_TICKET, self::DRAFT_TICKET, self::NO_ROUNDS_TICKET),
        ));

        ksort($progress);
        self::assertEquals(
            [self::CHECKLIST_TICKET => new PinProgress(1, 2), self::FINDINGS_TICKET => new PinProgress(2, 3)],
            $progress,
        );
    }

    #[Test]
    public function anotherOrganizationReadsItsOwnChecklistsOnly(): void
    {
        $progress = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->queries()->pinProgressOf(
            self::ticketIds(self::CHECKLIST_TICKET, self::FINDINGS_TICKET),
        ));

        self::assertEquals([self::CHECKLIST_TICKET => new PinProgress(1, 4)], $progress);
    }

    #[Test]
    public function noTicketsReadNothing(): void
    {
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->pinProgressOf([])));
    }

    #[Test]
    public function moreTicketsThanTheCapAreRefused(): void
    {
        $tooMany = array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-%012d', $number)), range(1, PinProgressQueries::MAX_TICKET_IDS + 1));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Pin progress is read for at most 100 tickets at a time.');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->pinProgressOf($tooMany));
    }

    #[Test]
    public function theCapItselfIsRead(): void
    {
        $atTheCap = [
            ...self::ticketIds(self::CHECKLIST_TICKET),
            ...array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-%012d', $number)), range(1, PinProgressQueries::MAX_TICKET_IDS - 1)),
        ];

        $progress = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->pinProgressOf($atTheCap));

        self::assertSame([self::CHECKLIST_TICKET], array_keys($progress));
    }

    #[Test]
    public function pinProgressIsReadOnlyInATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('none is open');

        $this->queries()->pinProgressOf(self::ticketIds(self::CHECKLIST_TICKET));
    }

    /**
     * Round 0 with one picture and a pin per check item of the later rounds, then those rounds with their items.
     *
     * @param list<array{string, int|null, string, list<string>}> $rounds each a kind, number (null for an unshared
     *                                                                    draft), state and its items' states
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private function ticketRounds(string $organizationId, string $ticketId, array $rounds): array
    {
        $reportId = $this->newId();
        $screenshotId = $this->newId();
        $rows = [
            self::round($organizationId, $ticketId, $reportId, 'report', 0, 'open'),
            ['rvw_screenshot', [
                'organization_id' => $organizationId, 'id' => $screenshotId, 'round_id' => $reportId, 'media_id' => self::MEDIA,
                'position' => 0, 'created_at' => self::CREATED_AT,
            ]],
        ];
        $items = [];
        $pinNumber = 1;
        foreach ($rounds as [$kind, $number, $state, $itemStates]) {
            $roundId = $this->newId();
            $rows[] = self::round($organizationId, $ticketId, $roundId, $kind, $number, $state);
            foreach ($itemStates as $itemState) {
                $pinId = $this->newId();
                $rows[] = ['rvw_pin', [
                    'organization_id' => $organizationId, 'id' => $pinId, 'screenshot_id' => $screenshotId, 'number' => $pinNumber++,
                    'x_pct' => '40.00', 'y_pct' => '60.00', 'note' => 'Looks off', 'source' => 'human', 'author_id' => self::LENA,
                    'created_at' => self::CREATED_AT,
                ]];
                $items[] = ['rvw_check_item', [
                    'organization_id' => $organizationId, 'id' => $this->newId(), 'round_id' => $roundId, 'pin_id' => $pinId,
                    'state' => $itemState, 'created_at' => self::CREATED_AT,
                    ...('open' === $itemState ? [] : ['changed_by' => self::LENA, 'changed_at' => self::CREATED_AT]),
                ]];
            }
        }

        return [...$rows, ...$items];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $organizationId, string $ticketId, string $roundId, string $kind, ?int $number, string $state): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId, 'id' => $roundId, 'ticket_id' => $ticketId, 'owner_id' => self::LENA, 'kind' => $kind,
            'number' => $number, 'shared_at' => null === $number && 'checklist' !== $kind ? null : self::CREATED_AT, 'state' => $state, 'version' => 1,
            'created_at' => self::CREATED_AT,
        ]];
    }

    private function newId(): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000fb%03d', $this->nextId++);
    }

    /**
     * @return list<TicketId>
     */
    private static function ticketIds(string ...$ids): array
    {
        return array_values(array_map(static fn (string $id): TicketId => new TicketId($id), $ids));
    }

    private function queries(): DbalPinProgressQueries
    {
        return new DbalPinProgressQueries($this->entityManager()->getConnection(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
