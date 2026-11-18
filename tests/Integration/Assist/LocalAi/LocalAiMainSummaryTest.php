<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\LocalAi;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalPackageId;
use App\Assist\Api\LocalResult;
use App\Assist\Api\MainSummaryRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\Summary\SummaryRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * LocalAi::proposalOf and LocalAi::makeMainSummary on the real ast_local_package and tkt_summary as pinround_app.
 * Acme Foods has SHOP-7, whose current summary was written at 10:00, and SHOP-8; Omar posted results on SHOP-7 before
 * that summary (09:50), after it (10:01 and 10:02, the newest), and one already made the main summary (10:03), and one on
 * SHOP-8 (10:04); Beta Bakery has a newer result (10:05) on a ticket with SHOP-7's id. The clock stands at 10:10 on a
 * day that is not today.
 */
final class LocalAiMainSummaryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f9a02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f9a03';
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f9a04';
    private const string SHOP_8 = '0197a3c0-0000-7000-8000-0000000f9a05';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f9a11';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f9a12';
    private const string CURRENT_SUMMARY = '0197a3c0-0000-7000-8000-0000000f9a21';
    private const string BEFORE_SUMMARY = '0197a3c0-0000-7000-8000-0000000f9a31';
    private const string OLDER = '0197a3c0-0000-7000-8000-0000000f9a32';
    private const string NEWEST = '0197a3c0-0000-7000-8000-0000000f9a33';
    private const string MADE_MAIN = '0197a3c0-0000-7000-8000-0000000f9a34';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f9a35';
    private const string BETA_PACKAGE = '0197a3c0-0000-7000-8000-0000000f9a36';
    private const string UNKNOWN_PACKAGE = '0197a3c0-0000-7000-8000-0000000f9a39';
    private const string SUMMARY_WRITTEN_AT = '2027-03-15 10:00:00+00';
    private const string NOW = '2027-03-15 10:10:00';
    private const string NEWEST_TEXT = 'Pay does nothing on the last step MARKER-f9a.';
    private const string LABEL = 'posted by Omar using local model qwen3:4b (unverified)';
    private const array CURRENT_SUMMARY_ROW = [
        'text' => 'Cart total wrong after coupon', 'source' => 'human', 'author_id' => null, 'model' => null,
        'prompt_version' => null, 'is_current' => true, 'created_at' => '2027-03-15 10:00:00',
    ];

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_7, self::SHOP_PROJECT, self::OMAR, 7);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_8, self::SHOP_PROJECT, self::OMAR, 8);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::SHOP_7, self::SHOP_PROJECT, self::OMAR, 7);
        $this->summaryRows()->addOf(self::ACME_FOODS, self::CURRENT_SUMMARY, self::SHOP_7, self::CURRENT_SUMMARY_ROW['text'], true, self::SUMMARY_WRITTEN_AT);
        // Inserted out of their posting order, so a read that trusts the insert order picks the wrong one.
        $this->addResult(self::ACME_FOODS, self::NEWEST, self::SHOP_7, '2027-03-15 10:02:00', self::NEWEST_TEXT);
        $this->addResult(self::ACME_FOODS, self::BEFORE_SUMMARY, self::SHOP_7, '2027-03-15 09:50:00', 'Posted before the summary.');
        $this->addResult(self::ACME_FOODS, self::MADE_MAIN, self::SHOP_7, '2027-03-15 10:03:00', 'Made the main summary once.', '2027-03-15 10:03:30');
        $this->addResult(self::ACME_FOODS, self::OLDER, self::SHOP_7, '2027-03-15 10:01:00', 'An older proposal.');
        $this->addResult(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_8, '2027-03-15 10:04:00', 'On another ticket.');
        $this->addResult(self::BETA_BAKERY, self::BETA_PACKAGE, self::SHOP_7, '2027-03-15 10:05:00', 'In another organization.');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function theProposalIsTheNewestResultAfterTheCurrentSummaryNotMadeTheMainSummaryOfThisTicketAndOrganization(): void
    {
        $proposal = $this->proposalOf(self::SHOP_7, self::SUMMARY_WRITTEN_AT);

        self::assertNotNull($proposal);
        self::assertSame(self::NEWEST, $proposal->packageId->toString());
        self::assertSame(self::NEWEST_TEXT, $proposal->text);
        self::assertSame(self::LABEL, $proposal->label);
        self::assertSame('qwen3:4b', $proposal->model->value);
        self::assertSame(self::OMAR, $proposal->posterId->toString());
        self::assertSame('2027-03-15 10:02:00', $proposal->postedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aTicketWithNoSummaryProposesItsNewestResultAndOneWithANewerSummaryProposesNothing(): void
    {
        self::assertSame(self::OTHER_TICKET, $this->proposalOf(self::SHOP_8, null)?->packageId->toString());
        self::assertNull($this->proposalOf(self::SHOP_8, '2027-03-15 10:04:01+00'));
    }

    /**
     * Rows kept before accept() capped a summary result at a summary's length: a newer one over the cap is passed
     * over for the newest one at the cap, and cannot be made the main summary.
     */
    #[Test]
    public function aStoredResultLongerThanASummaryIsNoProposalAndOneAtTheCapIs(): void
    {
        $atTheCap = '0197a3c0-0000-7000-8000-0000000f9a37';
        $overTheCap = '0197a3c0-0000-7000-8000-0000000f9a38';
        $this->addResult(self::ACME_FOODS, $atTheCap, self::SHOP_7, '2027-03-15 10:06:00', str_repeat('é', 2000));
        $this->addResult(self::ACME_FOODS, $overTheCap, self::SHOP_7, '2027-03-15 10:07:00', str_repeat('é', 2001));

        self::assertSame($atTheCap, $this->proposalOf(self::SHOP_7, self::SUMMARY_WRITTEN_AT)?->packageId->toString());
        $this->assertRefusedAsNotAProposal($overTheCap, self::SHOP_7);
        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame([self::MADE_MAIN => '2027-03-15 10:03:30'], $this->madeMainTimes());
    }

    #[Test]
    public function lenaMakesTheProposalTheMainSummaryAndTheOneBeforeGoesToHistory(): void
    {
        $this->makeMainSummary(self::NEWEST, self::SHOP_7, self::SUMMARY_WRITTEN_AT);

        self::assertSame([
            [...self::CURRENT_SUMMARY_ROW, 'is_current' => false],
            ['text' => self::NEWEST_TEXT, 'source' => 'laptop', 'author_id' => self::OMAR, 'model' => 'qwen3:4b', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-15 10:10:00'],
        ], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame(self::LENA, $this->chosenByOfCurrentSummary());
        self::assertSame([self::MADE_MAIN => '2027-03-15 10:03:30', self::NEWEST => '2027-03-15 10:10:00'], $this->madeMainTimes());
        self::assertNull($this->proposalOf(self::SHOP_7, '2027-03-15 10:10:00+00'));
    }

    /**
     * The second request read the page before the first committed, so it still names the summary of 10:00: the
     * conditional write refuses it on the row, not on what the caller read.
     */
    #[Test]
    public function aResultIsMadeTheMainSummaryOnceAndASecondRequestChangesNothing(): void
    {
        $this->makeMainSummary(self::NEWEST, self::SHOP_7, self::SUMMARY_WRITTEN_AT);
        $summariesAfterTheFirst = $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7);

        $this->assertRefusedAsNotAProposal(self::NEWEST, self::SHOP_7);

        self::assertCount(2, $summariesAfterTheFirst);
        self::assertSame($summariesAfterTheFirst, $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame([self::MADE_MAIN => '2027-03-15 10:03:30', self::NEWEST => '2027-03-15 10:10:00'], $this->madeMainTimes());
    }

    /**
     * The page was read when the current summary was an older one: the result is still newer than that one, so the
     * mark matches, and Ticketing refuses under the ticket's lock; the savepoint takes the mark back.
     */
    #[Test]
    public function aChoiceMadeOnAPageThatShowedAnotherCurrentSummaryIsRefusedAndChangesNothing(): void
    {
        try {
            $this->makeMainSummary(self::NEWEST, self::SHOP_7, '2027-03-15 09:00:00+00');
            self::fail('A summary the person never saw was replaced.');
        } catch (MainSummaryRefused $refusal) {
            self::assertSame(MainSummaryRefused::NOT_A_PROPOSAL, $refusal->refusalCode);
        }
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame([self::MADE_MAIN => '2027-03-15 10:03:30'], $this->madeMainTimes());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resultsThatAreNotTheProposal(): iterable
    {
        yield 'an older result than the newest' => [self::OLDER, self::SHOP_7];
        yield 'a result posted before the current summary' => [self::BEFORE_SUMMARY, self::SHOP_7];
        yield 'a result made the main summary already' => [self::MADE_MAIN, self::SHOP_7];
        yield 'another ticket\'s result named with this ticket' => [self::OTHER_TICKET, self::SHOP_7];
        yield 'another organization\'s result' => [self::BETA_PACKAGE, self::SHOP_7];
        yield 'an unknown package' => [self::UNKNOWN_PACKAGE, self::SHOP_7];
    }

    #[Test]
    #[DataProvider('resultsThatAreNotTheProposal')]
    public function aResultThatIsNotTheProposalIsRefusedAndNothingChanges(string $packageId, string $ticketId): void
    {
        $this->assertRefusedAsNotAProposal($packageId, $ticketId);

        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame([self::MADE_MAIN => '2027-03-15 10:03:30'], $this->madeMainTimes());
    }

    /**
     * The caller catches the refusal and commits, as a page that shows it would: the mark made before Ticketing
     * refused the text is undone by makeMainSummary itself. A result stored with a control character stands for one
     * Ticketing refuses, as accept() lets none through.
     */
    #[Test]
    public function aResultTicketingRefusesAsASummaryIsRefusedForItsRuleAndStaysTheProposalWhenTheCallerCommits(): void
    {
        $refusedPackage = '0197a3c0-0000-7000-8000-0000000f9a37';
        $this->addResult(self::ACME_FOODS, $refusedPackage, self::SHOP_7, '2027-03-15 10:06:00', "Pay does nothing\u{0007} on the last step.");

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($refusedPackage): ?MainSummaryRefused {
            try {
                $this->localAi()->makeMainSummary(
                    new LocalPackageId($refusedPackage),
                    new TicketId(self::SHOP_7),
                    new \DateTimeImmutable(self::SUMMARY_WRITTEN_AT),
                    new UserId(self::LENA),
                    new OrganizationId(self::ACME_FOODS),
                );
            } catch (MainSummaryRefused $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertNotNull($refusal, 'A result Ticketing refuses was made the main summary.');
        self::assertSame(MainSummaryRefused::SUMMARY_REJECTED, $refusal->refusalCode);
        self::assertSame('A summary is plain UTF-8 text; this one holds a control character or is not UTF-8.', $refusal->getMessage());
        self::assertSame([self::CURRENT_SUMMARY_ROW], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
        self::assertSame([self::MADE_MAIN => '2027-03-15 10:03:30'], $this->madeMainTimes());
        self::assertSame($refusedPackage, $this->proposalOf(self::SHOP_7, self::SUMMARY_WRITTEN_AT)?->packageId->toString());
    }

    private function assertRefusedAsNotAProposal(string $packageId, string $ticketId): void
    {
        try {
            $this->makeMainSummary($packageId, $ticketId, self::SUMMARY_WRITTEN_AT);
            self::fail('A result that is not the proposal was made the main summary.');
        } catch (MainSummaryRefused $refusal) {
            self::assertSame(MainSummaryRefused::NOT_A_PROPOSAL, $refusal->refusalCode);
            self::assertSame('This laptop summary is no longer offered. Reload the page.', $refusal->getMessage());
        }
        self::getContainer()->get('doctrine')->resetManager();
    }

    private function proposalOf(string $ticketId, ?string $currentSummaryCreatedAt): ?LocalResult
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?LocalResult => $this->localAi()->proposalOf(
            new TicketId($ticketId),
            null === $currentSummaryCreatedAt ? null : new \DateTimeImmutable($currentSummaryCreatedAt),
            new OrganizationId(self::ACME_FOODS),
        ));
    }

    private function makeMainSummary(string $packageId, string $ticketId, string $currentSummaryCreatedAt): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->localAi()->makeMainSummary(
            new LocalPackageId($packageId),
            new TicketId($ticketId),
            new \DateTimeImmutable($currentSummaryCreatedAt),
            new UserId(self::LENA),
            new OrganizationId(self::ACME_FOODS),
        ));
    }

    private function addResult(string $organizationId, string $packageId, string $ticketId, string $usedAt, string $text, ?string $madeMainAt = null): void
    {
        $this->asMigratorOf($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at, used_at, result_text, model, result_label, made_main_at)
             VALUES (?, ?, ?, ?, 'summary', 'summary.v1', CAST(? AS timestamptz) - interval '1 minute', CAST(? AS timestamptz) + interval '4 minutes', CAST(? AS timestamptz), ?, 'qwen3:4b', ?, CAST(? AS timestamptz))",
            [$organizationId, $packageId, self::OMAR, $ticketId, $usedAt.'+00', $usedAt.'+00', $usedAt.'+00', $text, self::LABEL, null === $madeMainAt ? null : $madeMainAt.'+00'],
        ));
    }

    /**
     * @return array<string, string> Acme Foods' package ids made the main summary, with the time in UTC
     */
    private function madeMainTimes(): array
    {
        /** @var array<string, string> $times */
        $times = $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $migrator): array => $migrator->fetchAllKeyValue(
            "SELECT id, to_char(made_main_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM ast_local_package WHERE made_main_at IS NOT NULL ORDER BY made_main_at",
        ));

        return $times;
    }

    private function chosenByOfCurrentSummary(): mixed
    {
        return $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $migrator): mixed => $migrator->fetchOne(
            'SELECT chosen_by_id FROM tkt_summary WHERE ticket_id = ? AND is_current',
            [self::SHOP_7],
        ));
    }

    private function removeRows(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->asMigratorOf($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ast_local_package'));
        }
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asMigratorOf(string $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');

            return $migrator->transactional($work);
        } finally {
            $tenantContext->clear();
        }
    }

    private function localAi(): LocalAi
    {
        return self::getContainer()->get(LocalAi::class);
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
