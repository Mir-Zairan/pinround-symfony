<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Summary;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\CurrentSummaryChanged;
use App\Ticketing\Api\GeneratedSummaryRefused;
use App\Ticketing\Api\TicketSummaryCommands;
use App\Ticketing\Domain\SummaryRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TicketSummaryCommands::storeAiSummary as the summary job calls it, inside a transaction under the organization. Acme
 * Foods has SHOP-7 with a human summary as its current one; Beta Bakery is another organization.
 */
final class TicketSummaryCommandsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8a02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f8a03';
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f8a04';
    private const string SHOP_8 = '0197a3c0-0000-7000-8000-0000000f8a05';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000f8a06';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000f8a09';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8a11';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8a12';
    private const string TEXT = 'Checkout button hidden MARKER-f8a';
    private const string CURRENT_SUMMARY = '0197a3c0-0000-7000-8000-0000000f8a21';
    private const array CURRENT_SUMMARY_ROW = [
        'text' => 'Cart total wrong after coupon', 'source' => 'human', 'author_id' => null, 'model' => null,
        'prompt_version' => null, 'is_current' => true, 'created_at' => '2027-03-02 09:00:00',
    ];

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_7, self::SHOP_PROJECT, self::MIA, 7);
        $this->summaryRows()->addOf(self::ACME_FOODS, self::CURRENT_SUMMARY, self::SHOP_7, self::CURRENT_SUMMARY_ROW['text'], true, '2027-03-02 09:00:00+00');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function anAiSummaryBecomesTheCurrentSummaryLabeledAndTrimmedAndTheOneBeforeGoesToHistory(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeAiSummary(self::ACME_FOODS, "  \n".self::TEXT."\n "));

        self::assertSame([
            [...self::CURRENT_SUMMARY_ROW, 'is_current' => false],
            ['text' => self::TEXT, 'source' => 'ai', 'author_id' => self::MIA, 'model' => 'claude-sonnet', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-03 10:05:00'],
        ], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function theSummaryRollsBackWithTheCallersTransaction(): void
    {
        $summaryCountInTheTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): mixed {
            $this->storeAiSummary(self::ACME_FOODS);

            return self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT count(*) FROM tkt_summary WHERE ticket_id = ?', [self::SHOP_7]);
        });

        self::assertSame(2, $summaryCountInTheTransaction);

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aSummaryForAnotherOrganizationThanTheOneSetIsRefusedAndTheCurrentSummaryStays(): void
    {
        try {
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn () => $this->storeAiSummary(self::ACME_FOODS));
            self::fail('The summary was stored under another organization.');
        } catch (\LogicException $refusal) {
            self::assertSame('An AI summary is stored under its own organization; another one is set.', $refusal->getMessage());
        }

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aBlankSummaryIsRefusedForItsBlanknessAndTheCurrentSummaryStaysCurrent(): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeAiSummary(self::ACME_FOODS, " \t\r\n  \n"));
            self::fail('The blank summary was stored.');
        } catch (GeneratedSummaryRefused $refusal) {
            self::assertSame('A summary has text; this one is blank.', $refusal->getMessage());
            self::assertInstanceOf(SummaryRefused::class, $refusal->getPrevious());
        }

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aSummaryWithABlankModelIsRefusedForIt(): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeAiSummary(self::ACME_FOODS, self::TEXT, ' '));
            self::fail('The summary without a model was stored.');
        } catch (GeneratedSummaryRefused $refusal) {
            self::assertSame('An AI summary names its model; it is blank.', $refusal->getMessage());
        }

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aChosenLaptopSummaryBecomesTheCurrentSummaryNamingItsPosterAndChooserAndTheOneBeforeGoesToHistory(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeChosenLaptopSummary(self::ACME_FOODS, ' '.self::TEXT."\n"));

        self::assertSame([
            [...self::CURRENT_SUMMARY_ROW, 'is_current' => false],
            ['text' => self::TEXT, 'source' => 'laptop', 'author_id' => self::MIA, 'model' => 'qwen3:4b', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-03 10:05:00'],
        ], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame(self::LENA, $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            static fn (): mixed => self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT chosen_by_id FROM tkt_summary WHERE ticket_id = ? AND is_current', [self::SHOP_7]),
        ));
    }

    #[Test]
    public function aChosenLaptopSummaryOverASummarysCapIsRefusedForItAndTheCurrentSummaryStays(): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeChosenLaptopSummary(self::ACME_FOODS, str_repeat('a', 2001)));
            self::fail('The over-long laptop summary was stored.');
        } catch (GeneratedSummaryRefused $refusal) {
            self::assertSame('A summary has at most 2000 characters.', $refusal->getMessage());
        }

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    /**
     * @return iterable<string, array{string|null}> when the current summary the person saw was made
     */
    public static function currentSummariesThePersonDidNotSee(): iterable
    {
        yield 'one made a second before' => ['2027-03-02 08:59:59+00'];
        yield 'one made a second after' => ['2027-03-02 09:00:01+00'];
        yield 'none, though the ticket has one' => [null];
    }

    /**
     * The person read the page before another summary became the current one: storing theirs would replace one they
     * never saw. The caller commits, so a refusal must leave nothing behind by itself.
     */
    #[Test]
    #[DataProvider('currentSummariesThePersonDidNotSee')]
    public function aChosenLaptopSummaryIsRefusedWhenTheCurrentSummaryIsNotTheOneThePersonSaw(?string $currentSummaryCreatedAt): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($currentSummaryCreatedAt): ?CurrentSummaryChanged {
            try {
                $this->storeChosenLaptopSummary(self::ACME_FOODS, self::TEXT, $currentSummaryCreatedAt);
            } catch (CurrentSummaryChanged $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertSame('The ticket\'s current summary changed since the page was read.', $refusal?->getMessage());
        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aChosenLaptopSummaryOfATicketWithNoSummaryIsStoredOnlyWhenThePersonSawNone(): void
    {
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_8, self::SHOP_PROJECT, self::MIA, 8);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeChosenLaptopSummary(self::ACME_FOODS, self::TEXT, '2027-03-02 09:00:00+00', self::SHOP_8));
            self::fail('A summary was stored in place of one the ticket does not have.');
        } catch (CurrentSummaryChanged) {
        }
        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame([], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_8));

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->storeChosenLaptopSummary(self::ACME_FOODS, self::TEXT, null, self::SHOP_8));

        self::assertSame(
            [['text' => self::TEXT, 'source' => 'laptop', 'author_id' => self::MIA, 'model' => 'qwen3:4b', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-03 10:05:00']],
            $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_8),
        );
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function ticketsAcmeFoodsDoesNotHave(): iterable
    {
        yield 'an unknown ticket, as one that has no summary' => [self::UNKNOWN_TICKET, null];
        yield 'an unknown ticket, as one with a summary' => [self::UNKNOWN_TICKET, '2027-03-02 09:00:00+00'];
        yield 'Beta Bakery\'s ticket, hidden by row-level security' => [self::BETA_TICKET, null];
    }

    /**
     * The caller commits, so a refusal must leave nothing behind by itself.
     */
    #[Test]
    #[DataProvider('ticketsAcmeFoodsDoesNotHave')]
    public function aChosenLaptopSummaryOfATicketTheOrganizationDoesNotHaveIsRefusedAndStoresNothing(string $ticketId, ?string $currentSummaryCreatedAt): void
    {
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::SHOP_PROJECT, self::MIA, 8);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($ticketId, $currentSummaryCreatedAt): ?CurrentSummaryChanged {
            try {
                $this->storeChosenLaptopSummary(self::ACME_FOODS, self::TEXT, $currentSummaryCreatedAt, $ticketId);
            } catch (CurrentSummaryChanged $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertSame('The ticket\'s current summary changed since the page was read.', $refusal?->getMessage());
        self::assertSame([], $this->summaryRows()->of(self::ACME_FOODS, $ticketId));
        self::assertSame([], $this->summaryRows()->of(self::BETA_BAKERY, $ticketId));
        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    #[Test]
    public function aChosenLaptopSummaryForAnotherOrganizationThanTheOneSetIsRefusedAndTheCurrentSummaryStays(): void
    {
        try {
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn () => $this->storeChosenLaptopSummary(self::ACME_FOODS, self::TEXT));
            self::fail('The laptop summary was stored under another organization.');
        } catch (\LogicException $refusal) {
            self::assertSame('A laptop summary is stored under its own organization; another one is set.', $refusal->getMessage());
        }

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    private function storeChosenLaptopSummary(string $organizationId, string $text, ?string $currentSummaryCreatedAt = '2027-03-02 09:00:00+00', string $ticketId = self::SHOP_7): void
    {
        $ticketSummaryCommands = self::getContainer()->get('test.ticketing_ticket_summary_commands');
        \assert($ticketSummaryCommands instanceof TicketSummaryCommands);
        $ticketSummaryCommands->storeChosenLaptopSummary(
            new OrganizationId($organizationId),
            new TicketId($ticketId),
            $text,
            new UserId(self::MIA),
            'qwen3:4b',
            'summary.v1',
            new UserId(self::LENA),
            new \DateTimeImmutable('2027-03-03 10:05:00 UTC'),
            null === $currentSummaryCreatedAt ? null : new \DateTimeImmutable($currentSummaryCreatedAt),
        );
    }

    private function storeAiSummary(string $organizationId, string $text = self::TEXT, string $model = 'claude-sonnet'): void
    {
        $ticketSummaryCommands = self::getContainer()->get('test.ticketing_ticket_summary_commands');
        \assert($ticketSummaryCommands instanceof TicketSummaryCommands);
        $ticketSummaryCommands->storeAiSummary(
            new OrganizationId($organizationId),
            new TicketId(self::SHOP_7),
            $text,
            new UserId(self::MIA),
            $model,
            'summary.v1',
            new \DateTimeImmutable('2027-03-03 10:05:00 UTC'),
        );
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
