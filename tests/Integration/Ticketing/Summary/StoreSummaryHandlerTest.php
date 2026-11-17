<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Summary;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\Event\SummaryGenerated;
use App\Ticketing\Api\GeneratedSummaryRefused;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * SummaryGenerated on the real command.bus, as the summary job dispatches it. Acme Foods has SHOP-7; Beta Bakery is
 * another organization. The summary text carries a marker that must reach no log.
 */
final class StoreSummaryHandlerTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8a02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f8a03';
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f8a04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8a11';
    private const string TEXT = 'Checkout button hidden MARKER-f8a';
    private const string CURRENT_SUMMARY = '0197a3c0-0000-7000-8000-0000000f8a21';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_7, self::SHOP_PROJECT, self::MIA, 7);
        $this->capturedLogRecords()->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aSummaryGeneratedUnderItsOrganizationBecomesTheCurrentSummaryAndNoLogHoldsItsText(): void
    {
        $this->commandBus()->dispatch(new Envelope(self::summaryGenerated(), [new TenantStamp(new OrganizationId(self::ACME_FOODS))]));

        self::assertSame(
            [['text' => self::TEXT, 'source' => 'ai', 'author_id' => self::MIA, 'model' => 'claude-sonnet', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-03 10:05:00']],
            $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7),
        );
        // Prod writes info and up (monolog.yaml); the debug records here are DBAL's statements, which it logs with
        // their parameters only when kernel.debug is on, so never in prod.
        $records = array_filter($this->capturedLogRecords()->all(), static fn (LogRecord $record): bool => $record->level->isHigherThan(Level::Debug));
        self::assertNotSame([], $records);
        $formatter = new JsonFormatter();
        foreach ($records as $record) {
            self::assertStringNotContainsString('MARKER-f8a', $formatter->format($record));
        }
    }

    #[Test]
    public function theSummaryRollsBackWithTheTransactionThatDispatchedIt(): void
    {
        $summaryCountInTheTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): mixed {
            $this->commandBus()->dispatch(self::summaryGenerated());

            return self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT count(*) FROM tkt_summary WHERE ticket_id = ?', [self::SHOP_7]);
        });

        self::assertSame(1, $summaryCountInTheTransaction);

        self::assertSame([], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aSummaryGeneratedForAnotherOrganizationThanTheOneSetIsRefused(): void
    {
        try {
            $this->commandBus()->dispatch(new Envelope(self::summaryGenerated(), [new TenantStamp(new OrganizationId(self::BETA_BAKERY))]));
            self::fail('The summary was handled under another organization.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(MessageForAnotherTenantRefused::class, $failure->getPrevious());
        }

        self::assertSame([], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aBlankSummaryIsRefusedForItsBlanknessAndTheCurrentSummaryStaysCurrent(): void
    {
        $this->summaryRows()->addOf(self::ACME_FOODS, self::CURRENT_SUMMARY, self::SHOP_7, 'Cart total wrong after coupon', true, '2027-03-02 09:00:00+00');
        $currentSummaryOnly = [['text' => 'Cart total wrong after coupon', 'source' => 'human', 'author_id' => null, 'model' => null, 'prompt_version' => null, 'is_current' => true, 'created_at' => '2027-03-02 09:00:00']];
        self::assertSame($currentSummaryOnly, $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));

        try {
            $this->commandBus()->dispatch(new Envelope(self::summaryGenerated(" \t\r\n  \n"), [new TenantStamp(new OrganizationId(self::ACME_FOODS))]));
            self::fail('The blank summary was stored.');
        } catch (HandlerFailedException $failure) {
            self::assertSame(
                [[GeneratedSummaryRefused::class, 'A summary has text; this one is blank.']],
                array_map(static fn (\Throwable $wrapped): array => [$wrapped::class, $wrapped->getMessage()], array_values($failure->getWrappedExceptions())),
            );
        }

        self::assertSame($currentSummaryOnly, $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    private static function summaryGenerated(string $text = self::TEXT): SummaryGenerated
    {
        return new SummaryGenerated(self::ACME_FOODS, self::SHOP_7, $text, self::MIA, 'claude-sonnet', 'summary.v1', new \DateTimeImmutable('2027-03-03 10:05:00 UTC'));
    }

    private function commandBus(): MessageBusInterface
    {
        return self::getContainer()->get('command.bus');
    }

    private function capturedLogRecords(): CapturedLogRecords
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);

        return $capturedLogRecords;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function summaryRows(): SummaryRows
    {
        return new SummaryRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
