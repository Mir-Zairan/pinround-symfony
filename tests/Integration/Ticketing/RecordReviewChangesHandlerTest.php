<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Review\Api\ChecklistCommands;
use App\Review\Api\Event\AreaVerdictSaved;
use App\Review\Api\Event\PinVerdictSet;
use App\Review\Api\VerdictCommands;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Ticketing\Application\ReviewChange;
use App\Ticketing\Infrastructure\Messenger\RecordReviewChangesHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The ticket history consumer of the Review events on the real commands, outbox, relay, worker and tkt_ticket_event:
 * each change writes one row under its organization, with the pin number and area name Review gives, and a redelivered
 * event writes none.
 */
final class RecordReviewChangesHandlerTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9e01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f9e02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f9e03';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f9e04';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f9e05';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f9e06';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f9e07';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f9e11';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-0000000f9e12';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f9e13';
    private const string BETA_REVIEW = '0197a3c0-0000-7000-8000-0000000f9e14';
    private const string BEFORE = '0197a3c0-0000-7000-8000-0000000f9e21';
    private const string AFTER = '0197a3c0-0000-7000-8000-0000000f9e22';
    private const string BETA_BEFORE = '0197a3c0-0000-7000-8000-0000000f9e23';
    private const string BETA_AFTER = '0197a3c0-0000-7000-8000-0000000f9e24';
    private const string TOTAL_PIN = '0197a3c0-0000-7000-8000-0000000f9e31';
    private const string PRICE_PIN = '0197a3c0-0000-7000-8000-0000000f9e32';
    private const string PRICE_ITEM = '0197a3c0-0000-7000-8000-0000000f9e41';
    private const string AREA = '0197a3c0-0000-7000-8000-0000000f9e51';
    private const string BETA_AREA = '0197a3c0-0000-7000-8000-0000000f9e52';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f9e61';
    private const string NOTE = 'The total still shows the old price.';
    private const string CREATED_AT = '2027-03-15 10:00:00+00';
    private const string MARKED_AT = '2027-03-16 09:00:00+00:00';
    private const string SET_AT = '2027-03-16 09:10:00+00:00';
    private const string UNDONE_AT = '2027-03-16 09:20:00+00:00';
    private const string SAVED_AT = '2027-03-16 09:30:00+00:00';

    protected function setUp(): void
    {
        $this->cleanUp();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::REPORT, 'report', 0, 'closed'),
            self::round(self::ACME_FOODS, self::CHECKLIST, 'checklist', 1, 'open'),
            self::round(self::ACME_FOODS, self::REVIEW, 'review', 2, 'open'),
            self::screenshot(self::ACME_FOODS, self::BEFORE, self::REPORT),
            self::screenshot(self::ACME_FOODS, self::AFTER, self::REVIEW),
            self::pin(self::TOTAL_PIN, 2),
            self::pin(self::PRICE_PIN, 5),
            ['rvw_check_item', [
                'organization_id' => self::ACME_FOODS, 'id' => self::PRICE_ITEM, 'round_id' => self::CHECKLIST, 'pin_id' => self::PRICE_PIN,
                'state' => 'open', 'created_at' => self::CREATED_AT,
            ]],
            self::area(self::ACME_FOODS, self::AREA, self::REVIEW, 'Per product', self::BEFORE, self::AFTER),
        ]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::round(self::BETA_BAKERY, self::BETA_REVIEW, 'review', 1, 'open'),
            self::screenshot(self::BETA_BAKERY, self::BETA_BEFORE, self::BETA_REVIEW),
            self::screenshot(self::BETA_BAKERY, self::BETA_AFTER, self::BETA_REVIEW, 1),
            self::area(self::BETA_BAKERY, self::BETA_AREA, self::BETA_REVIEW, 'Bread list', self::BETA_BEFORE, self::BETA_AFTER),
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->cleanUp();
        parent::tearDown();
    }

    #[Test]
    public function eachChecklistAndVerdictChangeWritesOneHistoryRowWithItsKindOutcomeAndLabels(): void
    {
        $this->makeTheFourChanges();

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(
            [
                [ReviewChange::CHECK_ITEM_CHANGED, self::OMAR, null, null, ['area_name' => null, 'outcome' => 'fixed', 'pin_number' => 5], '2027-03-16 09:00:00'],
                [ReviewChange::PIN_VERDICT_SET, self::MIA, null, null, ['area_name' => 'Per product', 'outcome' => 'still_wrong', 'pin_number' => 2], '2027-03-16 09:10:00'],
                [ReviewChange::PIN_VERDICT_UNDONE, self::MIA, null, null, ['area_name' => 'Per product', 'outcome' => 'still_wrong', 'pin_number' => 2], '2027-03-16 09:20:00'],
                [ReviewChange::AREA_VERDICT_SAVED, self::MIA, null, null, ['area_name' => 'Per product', 'outcome' => 'needs_fix', 'pin_number' => null], '2027-03-16 09:30:00'],
            ],
            $this->historyRowsAs(self::ACME_FOODS),
        );
        self::assertStringNotContainsString('old price', implode(' ', $this->rawPayloadsAs(self::ACME_FOODS)), 'The area note never reaches the history.');
        self::assertSame([], $this->historyRowsAs(self::BETA_BAKERY), 'Another organization sees none of the rows.');
    }

    #[Test]
    public function aRedeliveredEventWritesNoSecondRow(): void
    {
        $this->makeTheFourChanges();
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        $asyncNotify = $this->asyncNotify();

        $verdictsSet = array_values(array_filter(
            $asyncNotify->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof PinVerdictSet && self::TICKET === $envelope->getMessage()->ticketId,
        ));
        self::assertCount(1, $verdictsSet);
        $sentBefore = \count($asyncNotify->getSent());

        $asyncNotify->send($verdictsSet[0]);
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount($sentBefore + 1, $asyncNotify->getSent());
        self::assertSame(
            [ReviewChange::CHECK_ITEM_CHANGED, ReviewChange::PIN_VERDICT_SET, ReviewChange::PIN_VERDICT_UNDONE, ReviewChange::AREA_VERDICT_SAVED],
            array_column($this->historyRowsAs(self::ACME_FOODS), 0),
        );
    }

    #[Test]
    public function anAreaOfAnotherOrganizationIsReadUnderTheEventsOrganizationAndSoHasNoName(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->eventBus()->dispatch(new AreaVerdictSaved(
                self::ACME_FOODS,
                self::TICKET,
                self::BETA_REVIEW,
                self::BETA_AREA,
                VerdictCommands::AREA_PASS,
                false,
                self::MIA,
                new \DateTimeImmutable(self::SAVED_AT),
            ));
        });

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(
            [[ReviewChange::AREA_VERDICT_SAVED, self::MIA, null, null, ['area_name' => null, 'outcome' => 'pass', 'pin_number' => null], '2027-03-16 09:30:00']],
            $this->historyRowsAs(self::ACME_FOODS),
        );
    }

    private function makeTheFourChanges(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->checklistCommands()->markFixed(self::PRICE_ITEM, new UserId(self::OMAR), new \DateTimeImmutable(self::MARKED_AT)));
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->verdictCommands()->setPinVerdict(self::REVIEW, self::TOTAL_PIN, new UserId(self::MIA), VerdictCommands::PIN_STILL_WRONG, new \DateTimeImmutable(self::SET_AT)));
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->verdictCommands()->undoPinVerdict(self::REVIEW, self::TOTAL_PIN, new UserId(self::MIA), new \DateTimeImmutable(self::UNDONE_AT)));
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->verdictCommands()->saveAreaVerdict(self::REVIEW, self::AREA, new UserId(self::MIA), VerdictCommands::AREA_NEEDS_FIX, self::NOTE, new \DateTimeImmutable(self::SAVED_AT)));
    }

    /**
     * The ticket's review change rows in time order, read as the organization.
     *
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed}> type, actor, from step, to step, payload, time
     */
    private function historyRowsAs(string $organizationId): array
    {
        $rows = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT type, actor_id, from_step, to_step, payload::text, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')
             FROM tkt_ticket_event WHERE ticket_id = ? AND type IN (?, ?, ?, ?) ORDER BY at",
            [self::TICKET, ...ReviewChange::KINDS],
        ));

        return array_map(static function (array $row): array {
            self::assertIsString($row[4]);
            $payload = json_decode($row[4], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            // jsonb keeps its own key order.
            ksort($payload);

            return [$row[0], $row[1], $row[2], $row[3], $payload, $row[5]];
        }, $rows);
    }

    /**
     * @return list<string>
     */
    private function rawPayloadsAs(string $organizationId): array
    {
        $payloads = [];
        foreach ($this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn('SELECT payload::text FROM tkt_ticket_event WHERE ticket_id = ?', [self::TICKET])) as $payload) {
            self::assertIsString($payload);
            $payloads[] = $payload;
        }

        return $payloads;
    }

    private function cleanUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $migrator = $this->migrator();
        $migrator->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordReviewChangesHandler::HANDLER_NAME]);
        $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.self::TICKET.'%']);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $organizationId, string $roundId, string $kind, int $number, string $state): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId, 'id' => $roundId, 'ticket_id' => self::TICKET, 'owner_id' => self::LENA, 'kind' => $kind,
            'number' => $number, 'shared_at' => self::CREATED_AT, 'state' => $state, 'version' => 1, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function screenshot(string $organizationId, string $screenshotId, string $roundId, int $position = 0): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId, 'id' => $screenshotId, 'round_id' => $roundId, 'media_id' => self::MEDIA,
            'position' => $position, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $pinId, int $number): array
    {
        return ['rvw_pin', [
            'organization_id' => self::ACME_FOODS, 'id' => $pinId, 'screenshot_id' => self::BEFORE, 'number' => $number, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => 'Looks off', 'source' => 'human', 'author_id' => self::LENA, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function area(string $organizationId, string $areaId, string $roundId, string $name, string $beforeId, string $afterId): array
    {
        return ['rvw_area', [
            'organization_id' => $organizationId, 'id' => $areaId, 'round_id' => $roundId, 'name' => $name, 'position' => 0,
            'before_screenshot_id' => $beforeId, 'after_screenshot_id' => $afterId, 'created_at' => self::CREATED_AT,
        ]];
    }

    private function checklistCommands(): ChecklistCommands
    {
        $commands = self::getContainer()->get('test.review_checklist_commands');
        self::assertInstanceOf(ChecklistCommands::class, $commands);

        return $commands;
    }

    private function verdictCommands(): VerdictCommands
    {
        $commands = self::getContainer()->get('test.review_verdict_commands');
        self::assertInstanceOf(VerdictCommands::class, $commands);

        return $commands;
    }

    private function eventBus(): MessageBusInterface
    {
        return self::getContainer()->get('event.bus');
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
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
