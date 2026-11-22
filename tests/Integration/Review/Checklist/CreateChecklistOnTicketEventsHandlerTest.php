<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Checklist;

use App\Review\Api\ChecklistCommands;
use App\Review\Infrastructure\Messenger\CreateChecklistOnTicketEventsHandler;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Api\Event\TicketMoved;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * CreateChecklistOnTicketEventsHandler on the real ChecklistCommands and tables, with the ticket's step answered by a
 * double, as the step of a real ticket needs a whole workflow. Lena reported SHOP-1 of Acme Foods with two pins;
 * SHOP-2 has a report with no pin. Beta Bakery has a report with one pin on a ticket with SHOP-1's id.
 */
final class CreateChecklistOnTicketEventsHandlerTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9c01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f9c02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f9c03';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f9c04';
    private const string NO_PINS_TICKET = '0197a3c0-0000-7000-8000-0000000f9c05';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f9c06';
    private const string TEAM = '0197a3c0-0000-7000-8000-0000000f9c07';
    private const string STEP = '0197a3c0-0000-7000-8000-0000000f9c08';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f9c11';
    private const string NO_PINS_REPORT = '0197a3c0-0000-7000-8000-0000000f9c12';
    private const string BETA_REPORT = '0197a3c0-0000-7000-8000-0000000f9c13';
    private const string PICTURE = '0197a3c0-0000-7000-8000-0000000f9c21';
    private const string NO_PINS_PICTURE = '0197a3c0-0000-7000-8000-0000000f9c22';
    private const string BETA_PICTURE = '0197a3c0-0000-7000-8000-0000000f9c23';
    private const string FIRST_PIN = '0197a3c0-0000-7000-8000-0000000f9c31';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-0000000f9c32';
    private const string BETA_PIN = '0197a3c0-0000-7000-8000-0000000f9c33';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f9c51';
    private const string NOW = '2027-03-16 09:30:00+00:00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::NO_PINS_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::report(self::ACME_FOODS, self::REPORT, self::TICKET),
            self::picture(self::ACME_FOODS, self::PICTURE, self::REPORT),
            self::pin(self::ACME_FOODS, self::FIRST_PIN, self::PICTURE, 1),
            self::pin(self::ACME_FOODS, self::SECOND_PIN, self::PICTURE, 2),
            self::report(self::ACME_FOODS, self::NO_PINS_REPORT, self::NO_PINS_TICKET),
            self::picture(self::ACME_FOODS, self::NO_PINS_PICTURE, self::NO_PINS_REPORT),
        ]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::report(self::BETA_BAKERY, self::BETA_REPORT, self::TICKET),
            self::picture(self::BETA_BAKERY, self::BETA_PICTURE, self::BETA_REPORT),
            self::pin(self::BETA_BAKERY, self::BETA_PIN, self::BETA_PICTURE, 1),
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function startWorkMakesOneChecklistOfTheTwoPinsAndARedeliveryMakesNoSecond(): void
    {
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved('start_work')), 'progress');
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved('start_work')), 'progress');

        self::assertSame([[null, 'checklist']], $this->roundsOf(self::ACME_FOODS, self::TICKET));
        self::assertSame(
            [[self::FIRST_PIN, 'open'], [self::SECOND_PIN, 'open']],
            $this->itemsOf(self::ACME_FOODS, self::TICKET),
        );
    }

    #[Test]
    public function aMoveWithAnotherVerbOutOfAStepThatIsNotToDoMakesNothing(): void
    {
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved('send_to_review')), 'review');
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved(null)), 'progress');

        self::assertSame([], $this->roundsOf(self::ACME_FOODS, self::TICKET));
        self::assertSame([], $this->itemsOf(self::ACME_FOODS, self::TICKET));
    }

    #[Test]
    public function aMoveIntoToDoMakesTheChecklistWhateverTheVerb(): void
    {
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved(null)), 'todo');

        self::assertSame([[null, 'checklist']], $this->roundsOf(self::ACME_FOODS, self::TICKET));
    }

    #[Test]
    public function aTicketCreatedInToDoGetsItsChecklistAndOneCreatedInBacklogDoesNot(): void
    {
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketCreated(self::created()), 'backlog');
        self::assertSame([], $this->roundsOf(self::ACME_FOODS, self::TICKET));

        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketCreated(self::created()), 'todo');

        self::assertSame([[null, 'checklist']], $this->roundsOf(self::ACME_FOODS, self::TICKET));
        self::assertCount(2, $this->itemsOf(self::ACME_FOODS, self::TICKET));
    }

    #[Test]
    public function aReportWithNoPinsGetsNoChecklist(): void
    {
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved('start_work', self::NO_PINS_TICKET)), 'progress', self::NO_PINS_TICKET);
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketCreated(self::created(self::NO_PINS_TICKET)), 'todo', self::NO_PINS_TICKET);

        self::assertSame([], $this->roundsOf(self::ACME_FOODS, self::NO_PINS_TICKET));
    }

    #[Test]
    public function anotherOrganizationsReportOnATicketWithTheSameIdIsLeftAlone(): void
    {
        $this->handle(self::ACME_FOODS, static fn (CreateChecklistOnTicketEventsHandler $handler) => $handler->onTicketMoved(self::moved('start_work')), 'progress');

        self::assertCount(1, $this->roundsOf(self::ACME_FOODS, self::TICKET));
        self::assertSame([], $this->roundsOf(self::BETA_BAKERY, self::TICKET));
        self::assertSame([], $this->itemsOf(self::BETA_BAKERY, self::TICKET));
    }

    /**
     * @param \Closure(CreateChecklistOnTicketEventsHandler): mixed $deliver
     */
    private function handle(string $organizationId, \Closure $deliver, string $coreCode, string $ticketId = self::TICKET): void
    {
        $commands = self::getContainer()->get('test.review_checklist_commands');
        self::assertInstanceOf(ChecklistCommands::class, $commands);
        $handler = new CreateChecklistOnTicketEventsHandler(
            $commands,
            new InMemoryTicketTurnQueries([self::snapshot($coreCode, $ticketId)], new TicketId(self::STEP)),
            new MockClock(self::NOW),
        );

        $this->transactions()->committedAs($organizationId, static fn (): mixed => $deliver($handler));
    }

    /**
     * @return list<list<mixed>> the number and kind of each round after Round 0, the checklist with none first
     */
    private function roundsOf(string $organizationId, string $ticketId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT number, kind FROM rvw_round WHERE ticket_id = ? AND kind <> 'report' ORDER BY number NULLS FIRST",
            [$ticketId],
        ));
    }

    /**
     * @return list<list<mixed>> the pin and state of each item, by pin
     */
    private function itemsOf(string $organizationId, string $ticketId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            'SELECT i.pin_id, i.state FROM rvw_check_item i JOIN rvw_round r ON r.id = i.round_id WHERE r.ticket_id = ? ORDER BY i.pin_id',
            [$ticketId],
        ));
    }

    private static function moved(?string $verb, string $ticketId = self::TICKET): TicketMoved
    {
        return new TicketMoved(self::ACME_FOODS, $ticketId, 'SHOP-1', self::STEP, self::STEP, $verb, self::LENA, new \DateTimeImmutable(self::NOW));
    }

    private static function created(string $ticketId = self::TICKET): TicketCreated
    {
        return new TicketCreated(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::TEAM, self::LENA, null);
    }

    private static function snapshot(string $coreCode, string $ticketId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            self::SHOP_PROJECT,
            self::STEP,
            $coreCode,
            'todo',
            new TeamId(self::TEAM),
            new UserId(self::LENA),
            'Lena',
            [],
            [],
            'all',
            'major',
            null,
            new UserId(self::LENA),
        );
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function report(string $organizationId, string $roundId, string $ticketId): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId,
            'id' => $roundId,
            'ticket_id' => $ticketId,
            'number' => 0,
            'kind' => 'report',
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'closed',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function picture(string $organizationId, string $screenshotId, string $roundId): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId,
            'id' => $screenshotId,
            'round_id' => $roundId,
            'media_id' => self::MEDIA,
            'position' => 0,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $organizationId, string $pinId, string $screenshotId, int $number): array
    {
        return ['rvw_pin', [
            'organization_id' => $organizationId, 'id' => $pinId, 'screenshot_id' => $screenshotId, 'number' => $number, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => 'Wrong colour.', 'source' => 'human', 'author_id' => self::LENA, 'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), $this->tenantContext());
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows($this->migrator(), $this->tenantContext());
    }
}
