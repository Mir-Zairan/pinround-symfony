<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\LocalAi;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Api\LocalPackageRefused;
use App\Assist\Api\LocalResult;
use App\Assist\Api\LocalResultRefused;
use App\Assist\Api\PromptPackage;
use App\Assist\Application\AiAnswerCheck;
use App\Assist\Application\AiRequesters;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\LocalAiService;
use App\Assist\Application\PromptRenderer;
use App\Assist\Infrastructure\Uid\UuidV7LocalPackageIds;
use App\Audit\Api\AuditLog;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Application\Savepoints;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketSummaryCommands;
use App\Ticketing\Api\TicketSummaryHistory;
use App\Ticketing\Api\TicketSummaryQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Laptop AI as the container wires it, as pinround_app in committed transactions under Acme Foods, so a refusal that
 * a page catches could not hide a written row behind a rollback: package() in the test's transaction, accept() in its
 * own. Lena leads the Shop team that owns SHOP; Omar (display name "Omar Haddad") is in Acme Foods but in no team, so
 * he sees the checkout ticket for everyone but not the team-only one; Mia leads the Ops team; Raj is in Beta Bakery
 * only. The checkout ticket has a current AI summary. The packages and audit entries are removed by hand after each
 * test, as pinround_app may not delete them. The clock is frozen in March 2027, which has its aud_event partition.
 */
final class LocalAiServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e6a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e6a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000e6a21';
    private const string OPS_TEAM = '0197a3c0-0000-7000-8000-0000000e6a22';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e6a41';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e6a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e6a12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e6a13';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000e6a14';
    private const string UNKNOWN_PACKAGE = '0197a3c0-0000-7000-8000-0000000e6a59';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string TWO_MINUTES_LATER = '2027-03-15 10:02:00 UTC';
    private const string TICKET_TEXT = 'Pay does nothing on the last step MARKER-e6t.';
    private const string CURRENT_SUMMARY = 'Checkout fails at the pay step.';
    private const string ON_THE_LAPTOP = '{"on": true, "where": "laptop"}';
    private const string MODEL = 'qwen3:4b';
    /** A byte order mark, a CRLF and trailing spaces, which the answer check removes. */
    private const string RESULT = "\u{FEFF}Pay does nothing on the last step.\r\nThe order is never sent MARKER-e6r.  ";
    private const string CHECKED_RESULT = "Pay does nothing on the last step.\nThe order is never sent MARKER-e6r.";
    private const string INSERT_PACKAGE = 'INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at, used_at, result_text, model, result_label)'
        ." VALUES (?, ?, ?, ?, 'summary', 'summary.v1', ?, ?, ?, ?, ?, ?)";
    private const string INSERT_MEMBERSHIP = "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')";

    private static ?TicketId $checkoutTicketId = null;
    private static ?TicketId $teamOnlyTicketId = null;

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [self::LENA => ['Lena', 'lead', 'developer']]);
        self::shopTeamRows()->addUser(self::OMAR, 'Omar');
        self::shopTeamRows()->addUser(self::MIA, 'Mia');
        self::shopTeamRows()->addUser(self::RAJ, 'Raj');
        self::migrator()->executeStatement("UPDATE idn_user SET name = 'Omar Haddad' WHERE id = ?", [self::OMAR]);
        self::asMigratorOf(self::ACME_FOODS, static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, 'Ops', 'ops', ?, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::OPS_TEAM, self::MIA],
            );
        });
        self::shopTeamRows()->addMember(self::ACME_FOODS, self::OPS_TEAM, self::MIA, 'lead', 'tester');
        self::asMigratorOf(self::BETA_BAKERY, static function (Connection $migrator): void {
            $migrator->executeStatement(self::INSERT_MEMBERSHIP, [self::BETA_BAKERY, self::RAJ]);
        });
        self::transactions()->committedAs(self::ACME_FOODS, static function (): void {
            self::connection()->executeStatement(self::INSERT_MEMBERSHIP, [self::ACME_FOODS, self::OMAR]);
            self::connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, '{}'::jsonb, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS],
            );
        });

        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $tickets = new TicketFactory($ticketCommands, self::transactions(), self::connection());
        $checkoutTicketId = $tickets->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Checkout fails', 'description' => self::TICKET_TEXT]);
        self::$checkoutTicketId = $checkoutTicketId;
        $teamOnlyTicketId = $tickets->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Card numbers in the log', 'description' => 'Card numbers show.']);
        self::$teamOnlyTicketId = $teamOnlyTicketId;
        // No command restricts a ticket yet; the turn row copies the column.
        self::asMigratorOf(self::ACME_FOODS, static function (Connection $migrator) use ($teamOnlyTicketId): void {
            $migrator->executeStatement("UPDATE tkt_ticket SET visibility = 'team' WHERE id = ?", [$teamOnlyTicketId->toString()]);
            $migrator->executeStatement("UPDATE ins_ticket_turn SET visibility = 'team' WHERE ticket_id = ?", [$teamOnlyTicketId->toString()]);
        });
        $summaryCommands = self::getContainer()->get('test.ticketing_ticket_summary_commands');
        \assert($summaryCommands instanceof TicketSummaryCommands);
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($summaryCommands, $checkoutTicketId): void {
            $summaryCommands->storeAiSummary(
                new OrganizationId(self::ACME_FOODS),
                $checkoutTicketId,
                self::CURRENT_SUMMARY,
                new UserId(self::LENA),
                'meta-llama/Llama-3.1-8B-Instruct',
                'summary.v1',
                new \DateTimeImmutable('2027-03-14 09:00:00 UTC'),
            );
        });
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::$checkoutTicketId = null;
        self::$teamOnlyTicketId = null;
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->capturedLogRecords()->clear();
    }

    protected function tearDown(): void
    {
        self::removePackagesAndAuditEntries();
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function aPackageForATicketOmarMaySeeIsStoredWithThePromptTheServerWouldSend(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));

        self::assertStringContainsString('You write a short summary of one ticket', $package->instructions);
        self::assertStringContainsString("<ticket>\nTitle: Checkout fails\nText: ".self::TICKET_TEXT."\n</ticket>", $package->prompt);
        self::assertNull($package->schema);
        self::assertSame('2027-03-15 10:05:00', $package->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame(
            [[$package->packageId->toString(), self::OMAR, self::checkoutTicketId()->toString(), 'summary', 'summary.v1', '2027-03-15 10:00:00', '2027-03-15 10:05:00', null, null, null]],
            $this->packageRowsOf(self::ACME_FOODS),
        );
    }

    #[Test]
    public function aTitleOnlyPolicyLeavesTheTicketTextOutOfThePrompt(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, '{"on": true, "where": "laptop", "send": "title_only"}', self::checkoutTicketId()));

        self::assertStringContainsString("<ticket>\nTitle: Checkout fails\n</ticket>", $package->prompt);
        self::assertStringNotContainsString('MARKER-e6t', $package->prompt);
        self::assertStringNotContainsString('MARKER-e6t', $package->instructions);
    }

    #[Test]
    public function laptopFirstGivesAPackageToo(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, '{"on": true, "where": "laptop_first"}', self::checkoutTicketId()));

        self::assertCount(1, $this->packageRowsOf(self::ACME_FOODS));
        self::assertStringContainsString('Title: Checkout fails', $package->prompt);
    }

    /** ai-architecture.md: a team-only ticket may go to the laptop of someone who may see it. */
    #[Test]
    public function aTeamOnlyTicketGivesAPackageToSomeoneWhoMaySeeIt(): void
    {
        $package = self::expectPackage($this->packageAs(self::LENA, self::ON_THE_LAPTOP, self::teamOnlyTicketId()));

        self::assertStringContainsString("Title: Card numbers in the log\nText: Card numbers show.", $package->prompt);
        self::assertSame([self::LENA], array_column($this->packageRowsOf(self::ACME_FOODS), 1));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function refusedPackages(): iterable
    {
        yield 'Mia of the Ops team on a team-only ticket of Shop' => [self::MIA, self::ON_THE_LAPTOP, 'team-only', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
        yield 'Omar, in no team, on a team-only ticket' => [self::OMAR, self::ON_THE_LAPTOP, 'team-only', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
        yield 'an unknown ticket' => [self::OMAR, self::ON_THE_LAPTOP, 'unknown', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
        yield 'AI in the cloud only' => [self::OMAR, '{"on": true, "where": "cloud"}', 'checkout', 'cloud_only', 'Your admin runs AI help in the company cloud, not on laptops.'];
        // Team-only tickets are kept from cloud AI by default; the laptop refusal still names the cloud-only setting.
        yield 'AI in the cloud only, Lena on a team-only ticket she may see' => [self::LENA, '{"on": true, "where": "cloud"}', 'team-only', 'cloud_only', 'Your admin runs AI help in the company cloud, not on laptops.'];
        yield 'AI off' => [self::OMAR, '{"on": false, "where": "laptop"}', 'checkout', 'ai_off', 'Your admin turned AI help off.'];
        yield 'leads only, Omar leads no team' => [self::OMAR, '{"on": true, "where": "laptop", "who": "leads_only"}', 'checkout', 'leads_only', 'Your admin allows AI help for leads only.'];
        // Refused as for a ticket he cannot see, so the answer never tells whether the ticket exists in Acme Foods.
        yield 'Raj, a member of Beta Bakery only' => [self::RAJ, self::ON_THE_LAPTOP, 'checkout', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
    }

    #[Test]
    #[DataProvider('refusedPackages')]
    public function aPackageThePolicyRefusesSaysWhyAndStoresNothing(string $requesterId, string $aiJson, string $ticket, string $expectedCode, string $expectedSentence): void
    {
        $refusal = $this->packageAs($requesterId, $aiJson, self::ticketIdOf($ticket));

        self::assertInstanceOf(LocalPackageRefused::class, $refusal);
        self::assertSame([$expectedCode, $expectedSentence], [$refusal->refusalCode, $refusal->getMessage()]);
        self::assertSame([], $this->packageRowsOf(self::ACME_FOODS));
    }

    /** Leads only lets a lead through, so the refusal above is about Omar, not the setting. */
    #[Test]
    public function leadsOnlyGivesALeadAPackage(): void
    {
        self::expectPackage($this->packageAs(self::LENA, '{"on": true, "where": "laptop", "who": "leads_only"}', self::checkoutTicketId()));

        self::assertCount(1, $this->packageRowsOf(self::ACME_FOODS));
    }

    #[Test]
    public function theEleventhOpenPackageIsRefusedAndUsedExpiredAndOtherPeoplesPackagesDoNotCount(): void
    {
        self::seedPackage(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000e6a51', self::OMAR, expiresAt: '2027-03-15 10:00:00+00', createdAt: '2027-03-15 09:55:00+00');
        self::seedPackage(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000e6a52', self::OMAR, usedAt: '2027-03-15 09:59:00+00');
        self::seedPackage(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000e6a53', self::LENA);
        self::seedPackage(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-0000000e6a54', self::OMAR);

        for ($package = 1; $package <= LocalAi::MAX_OPEN_PACKAGES_PER_PERSON; ++$package) {
            self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        }
        $refusal = $this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId());

        self::assertInstanceOf(LocalPackageRefused::class, $refusal);
        self::assertSame(
            [LocalPackageRefused::TOO_MANY_OPEN_PACKAGES, 'You have 10 laptop AI requests open. Wait a few minutes and try again.'],
            [$refusal->refusalCode, $refusal->getMessage()],
        );
        self::assertCount(LocalAi::MAX_OPEN_PACKAGES_PER_PERSON + 3, $this->packageRowsOf(self::ACME_FOODS));
    }

    #[Test]
    public function aValidResultIsKeptOnThePackageWithItsLabelAndTheCurrentSummaryStays(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $historyBefore = $this->summaryHistory();
        self::mockTime(self::TWO_MINUTES_LATER);

        $result = $this->acceptAs(self::OMAR, $package->packageId, self::RESULT);

        self::assertInstanceOf(LocalResult::class, $result);
        self::assertSame(
            [$package->packageId->toString(), self::CHECKED_RESULT, self::MODEL, self::OMAR, 'posted by Omar using local model qwen3:4b (unverified)', '2027-03-15 10:02:00'],
            [$result->packageId->toString(), $result->text, $result->model->value, $result->posterId->toString(), $result->label, $result->postedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
        self::assertSame(
            [[$package->packageId->toString(), self::OMAR, self::checkoutTicketId()->toString(), 'summary', 'summary.v1', '2027-03-15 10:00:00', '2027-03-15 10:05:00', '2027-03-15 10:02:00', self::CHECKED_RESULT, self::MODEL]],
            $this->packageRowsOf(self::ACME_FOODS),
        );
        self::assertSame(self::CURRENT_SUMMARY, $historyBefore->current?->text);
        self::assertEquals($historyBefore, $this->summaryHistory(), 'The laptop result changed the ticket\'s summaries.');
    }

    /** OutputValidator never escapes: the text stays plain text, which Twig escapes when it shows it. */
    #[Test]
    public function aResultWithMarkupIsKeptVerbatimAsPlainText(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $markup = '<b>Pay</b> does nothing. <script>alert("x")</script> &amp; more.';

        $result = $this->acceptAs(self::OMAR, $package->packageId, $markup);

        self::assertInstanceOf(LocalResult::class, $result);
        self::assertSame($markup, $result->text);
        self::assertSame([$markup], array_column($this->packageRowsOf(self::ACME_FOODS), 8));
    }

    #[Test]
    public function aSecondResultForAPackageIsGoneAndTheFirstIsKept(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        self::assertInstanceOf(LocalResult::class, $this->acceptAs(self::OMAR, $package->packageId, self::RESULT));
        $rowsAfterTheFirst = $this->packageRowsOf(self::ACME_FOODS);

        self::mockTime(self::TWO_MINUTES_LATER);
        $refusal = $this->acceptAs(self::OMAR, $package->packageId, 'A second answer.', 'phi4-mini:latest');

        self::assertGone($refusal);
        self::assertSame($rowsAfterTheFirst, $this->packageRowsOf(self::ACME_FOODS));
        self::assertSame([self::CHECKED_RESULT], array_column($rowsAfterTheFirst, 8));
    }

    #[Test]
    public function aResultOneSecondPastTheExpiryIsGoneAndNothingIsStored(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $unusedRows = $this->packageRowsOf(self::ACME_FOODS);

        self::mockTime('2027-03-15 10:05:01 UTC');
        $refusal = $this->acceptAs(self::OMAR, $package->packageId, self::RESULT);

        self::assertGone($refusal);
        self::assertSame($unusedRows, $this->packageRowsOf(self::ACME_FOODS));
    }

    /** One second before the expiry the same package still takes it, so the refusal above is the clock's. */
    #[Test]
    public function aResultOneSecondBeforeTheExpiryIsKept(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));

        self::mockTime('2027-03-15 10:04:59 UTC');

        self::assertInstanceOf(LocalResult::class, $this->acceptAs(self::OMAR, $package->packageId, self::RESULT));
    }

    /**
     * @return iterable<string, array{string, string, string}> owner, organization, package id
     */
    public static function packagesThatAreNotOmarsInAcmeFoods(): iterable
    {
        yield 'Lena\'s package in Acme Foods' => [self::LENA, self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000e6a55'];
        yield 'Omar\'s own package in Beta Bakery' => [self::OMAR, self::BETA_BAKERY, '0197a3c0-0000-7000-8000-0000000e6a56'];
    }

    /**
     * The store keeps to the tenant and the user as well, so the refusal must come from the resolver's owner before
     * the store reads or writes the package; a store that lost its filter would otherwise hand it over.
     */
    #[Test]
    #[DataProvider('packagesThatAreNotOmarsInAcmeFoods')]
    public function aResultForAnotherPersonsOrOrganizationsPackageIsNotFoundAndChangesNothing(string $ownerId, string $organizationId, string $packageId): void
    {
        self::seedPackage($organizationId, $packageId, $ownerId);
        $rowsBefore = [$this->packageRowsOf(self::ACME_FOODS), $this->packageRowsOf(self::BETA_BAKERY)];
        self::assertNotSame([[], []], $rowsBefore);
        $watchedPackages = $this->watchedPackages();

        $refusal = $this->acceptAs(self::OMAR, new LocalPackageId($packageId), self::RESULT, self::MODEL, $this->localAiWith($watchedPackages));

        self::assertNotFound($refusal);
        self::assertSame([], $watchedPackages->packageCalls());
        self::assertSame($rowsBefore, [$this->packageRowsOf(self::ACME_FOODS), $this->packageRowsOf(self::BETA_BAKERY)]);
    }

    #[Test]
    public function aResultForAnUnknownPackageIsNotFound(): void
    {
        self::assertNotFound($this->acceptAs(self::OMAR, new LocalPackageId(self::UNKNOWN_PACKAGE), self::RESULT));
        self::assertSame([], $this->packageRowsOf(self::ACME_FOODS));
    }

    /**
     * A valid result posted inside an open transaction: were the guard gone, the result would be kept and committed
     * with the caller's transaction.
     */
    #[Test]
    public function aResultPostedInsideAnOpenTransactionIsRefusedAndChangesNothing(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $unusedRows = $this->packageRowsOf(self::ACME_FOODS);
        self::assertNotSame([], $unusedRows);
        $localAi = $this->localAi();

        $refusal = self::transactions()->committedAs(self::ACME_FOODS, static function () use ($localAi, $package): ?\LogicException {
            try {
                $localAi->accept($package->packageId, self::MODEL, self::RESULT, new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
            } catch (\LogicException $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertSame('A transaction of its own must not run inside another transaction.', $refusal->getMessage());
        self::assertSame($unusedRows, $this->packageRowsOf(self::ACME_FOODS));
        self::assertSame([], $this->auditRowsOf(self::ACME_FOODS));
    }

    /**
     * @return iterable<string, array{string, string, string}> text, model, the reason
     */
    public static function rejectedResults(): iterable
    {
        yield 'blank' => [" \r\n\u{FEFF} ", self::MODEL, 'The AI answer is blank.'];
        // One word of 10,001 bytes: under the answer check's byte cap and its word count, so only the comment cap refuses it.
        yield 'one character over a comment\'s cap' => [str_repeat('x', 10001), self::MODEL, 'The AI answer is longer than 10,000 characters.'];
        yield 'over the answer check\'s bytes' => [str_repeat('x', 20001), self::MODEL, 'The AI answer is longer than 20000 bytes.'];
        yield 'over the summary\'s words' => [str_repeat('word ', 61), self::MODEL, 'The AI answer to summary.v1 has more than 60 words.'];
        yield 'an uppercase model' => [self::RESULT, 'Qwen3:4b', LocalModelName::RULE];
        yield 'a model with a trailing line feed' => [self::RESULT, self::MODEL."\n", LocalModelName::RULE];
    }

    #[Test]
    #[DataProvider('rejectedResults')]
    public function aResultThatFailsTheCheckIsRejectedWithItsReasonAndThePackageStaysOpen(string $text, string $model, string $expectedReason): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $unusedRows = $this->packageRowsOf(self::ACME_FOODS);

        $refusal = $this->acceptAs(self::OMAR, $package->packageId, $text, $model);

        self::assertInstanceOf(LocalResultRefused::class, $refusal);
        self::assertSame([LocalResultRefused::RESULT_REJECTED, $expectedReason], [$refusal->refusalCode, $refusal->getMessage()]);
        self::assertSame($unusedRows, $this->packageRowsOf(self::ACME_FOODS));
        self::assertInstanceOf(LocalResult::class, $this->acceptAs(self::OMAR, $package->packageId, self::RESULT), 'A rejected result used the package up.');
    }

    /** At the cap exactly the text is kept, so the rejection one character over is the cap's. */
    #[Test]
    public function aResultOfExactlyACommentsCapIsKept(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));

        $result = $this->acceptAs(self::OMAR, $package->packageId, str_repeat('é', 10000));

        self::assertInstanceOf(LocalResult::class, $result);
        self::assertSame(10000, mb_strlen($result->text));
    }

    /**
     * Another post of the same package lands between the read that finds it open and the conditional UPDATE: the
     * UPDATE matches no row, so the later post is gone and the earlier one stays.
     */
    #[Test]
    public function ofTwoResultsThatRaceForOnePackageExactlyOneIsKept(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $racingPackages = $this->watchedPackages();
        $racingPackages->postRivalAfterEachRead('The answer of a second tab.', new LocalModelName('phi4-mini:latest'));

        $refusal = $this->acceptAs(self::OMAR, $package->packageId, self::RESULT, self::MODEL, $this->localAiWith($racingPackages));

        self::assertGone($refusal);
        self::assertSame(['issuedOf', 'post'], $racingPackages->packageCalls(), 'The result was refused before its UPDATE, so the UPDATE\'s condition went untested.');
        $rows = $this->packageRowsOf(self::ACME_FOODS);
        self::assertSame([['The answer of a second tab.', 'phi4-mini:latest']], array_map(static fn (array $row): array => [$row[8], $row[9]], $rows));
    }

    /**
     * Omar was given 29 used packages at 09:00:01, so at 10:00:00 the 30th is the last of the hour. His package of
     * 09:00:00 is past the hour, and Lena's and his Beta Bakery package of the hour are not his in Acme Foods.
     */
    #[Test]
    public function theThirtyFirstPackageOfAnHourIsRefusedAndOneSecondLaterItIsGiven(): void
    {
        for ($package = 1; $package <= 29; ++$package) {
            self::seedPackage(self::ACME_FOODS, \sprintf('0197a3c0-0000-7000-8000-0000000e7%03d', $package), self::OMAR, createdAt: '2027-03-15 09:00:01+00', expiresAt: '2027-03-15 09:05:01+00', usedAt: '2027-03-15 09:01:00+00');
        }
        self::seedPackage(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000e7100', self::OMAR, createdAt: '2027-03-15 09:00:00+00', expiresAt: '2027-03-15 09:05:00+00');
        self::seedPackage(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000e7101', self::LENA, createdAt: '2027-03-15 09:30:00+00', expiresAt: '2027-03-15 09:35:00+00');
        self::seedPackage(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-0000000e7102', self::OMAR, createdAt: '2027-03-15 09:30:00+00', expiresAt: '2027-03-15 09:35:00+00');

        self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $rowsAtTheCap = $this->packageRowsOf(self::ACME_FOODS);
        $refusal = $this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId());

        self::assertInstanceOf(LocalPackageRefused::class, $refusal);
        self::assertSame(
            [LocalPackageRefused::TOO_MANY_PACKAGES, 'You asked for 30 laptop AI requests in the last hour. Try again later.'],
            [$refusal->refusalCode, $refusal->getMessage()],
        );
        $rowsAfterTheRefusal = $this->packageRowsOf(self::ACME_FOODS);
        self::assertCount(32, $rowsAtTheCap);
        self::assertSame($rowsAtTheCap, $rowsAfterTheRefusal);

        self::mockTime('2027-03-15 10:00:01 UTC');
        self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        self::assertCount(33, $this->packageRowsOf(self::ACME_FOODS));
    }

    /**
     * @return iterable<string, array{string, string, string}> the AI setting after the package was issued, the code, the sentence
     */
    public static function policiesChangedAfterThePackage(): iterable
    {
        yield 'AI switched off' => ['{"on": false, "where": "laptop"}', 'ai_off', 'Your admin turned AI help off.'];
        yield 'AI moved to the cloud' => ['{"on": true, "where": "cloud"}', 'cloud_only', 'Your admin runs AI help in the company cloud, not on laptops.'];
        yield 'AI for leads only, Omar leads no team' => ['{"on": true, "where": "laptop", "who": "leads_only"}', 'leads_only', 'Your admin allows AI help for leads only.'];
    }

    #[Test]
    #[DataProvider('policiesChangedAfterThePackage')]
    public function aResultThePolicyNoLongerAllowsIsRefusedAndThePackageStaysOpen(string $aiJson, string $expectedCode, string $expectedSentence): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $unusedRows = $this->packageRowsOf(self::ACME_FOODS);
        $this->changeAiSettings($aiJson);

        $refusal = $this->acceptAs(self::OMAR, $package->packageId, self::RESULT);

        self::assertInstanceOf(LocalResultRefused::class, $refusal);
        self::assertSame([$expectedCode, $expectedSentence], [$refusal->refusalCode, $refusal->getMessage()]);
        self::assertSame($unusedRows, $this->packageRowsOf(self::ACME_FOODS));
        $this->assertOneRefusalAudited($package->packageId, $expectedCode);
        $this->changeAiSettings(self::ON_THE_LAPTOP);
        self::assertInstanceOf(LocalResult::class, $this->acceptAs(self::OMAR, $package->packageId, self::RESULT), 'The refusal used the package up.');
    }

    /** Omar sees the team-only ticket while in the Shop team; taken off it, his result reads as for no package. */
    #[Test]
    public function aResultOfAPosterTakenOffTheTeamOfATeamOnlyTicketIsNotFoundAndChangesNothing(): void
    {
        self::asMigratorOf(self::ACME_FOODS, static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, 'member', 'developer', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::SHOP_TEAM, self::OMAR],
            );
        });
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::teamOnlyTicketId()));
        $unusedRows = $this->packageRowsOf(self::ACME_FOODS);
        self::removeOmarFromTheShopTeam();

        $refusal = $this->acceptAs(self::OMAR, $package->packageId, self::RESULT);

        self::assertNotFound($refusal);
        self::assertSame($unusedRows, $this->packageRowsOf(self::ACME_FOODS));
        $this->assertOneRefusalAudited($package->packageId, LocalResultRefused::PACKAGE_NOT_FOUND);
    }

    /**
     * Omar leaves Acme Foods after he was given the package: his result reads as for no package, the package stays
     * unused, and the refusal is audited under him in the organization he left.
     */
    #[Test]
    public function aResultOfAPosterWhoLeftTheOrganizationIsNotFoundAndChangesNothing(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        $unusedRows = $this->packageRowsOf(self::ACME_FOODS);
        self::assertNotSame([], $unusedRows);
        self::removeOmarFromAcmeFoods();
        try {
            $refusal = $this->acceptAs(self::OMAR, $package->packageId, self::RESULT);

            self::assertNotFound($refusal);
            self::assertSame($unusedRows, $this->packageRowsOf(self::ACME_FOODS));
            $this->assertOneRefusalAudited($package->packageId, LocalResultRefused::PACKAGE_NOT_FOUND);
        } finally {
            self::asMigratorOf(self::ACME_FOODS, static function (Connection $migrator): void {
                $migrator->executeStatement(self::INSERT_MEMBERSHIP, [self::ACME_FOODS, self::OMAR]);
            });
        }
    }

    /** Lena's package in Acme Foods and Omar's own in Beta Bakery: the entry stays in Omar's organization. */
    #[Test]
    #[DataProvider('packagesThatAreNotOmarsInAcmeFoods')]
    public function aResultForAPackageThatIsNotOmarsIsAuditedInHisOrganizationOnly(string $ownerId, string $organizationId, string $packageId): void
    {
        self::seedPackage($organizationId, $packageId, $ownerId);

        self::assertNotFound($this->acceptAs(self::OMAR, new LocalPackageId($packageId), self::RESULT));

        $this->assertOneRefusalAudited(new LocalPackageId($packageId), LocalResultRefused::PACKAGE_NOT_FOUND);
        self::assertSame([], $this->auditRowsOf(self::BETA_BAKERY));
    }

    #[Test]
    public function aResultForAnUnknownPackageIsAudited(): void
    {
        self::assertNotFound($this->acceptAs(self::OMAR, new LocalPackageId(self::UNKNOWN_PACKAGE), self::RESULT, 'Qwen3:4b'));

        $this->assertOneRefusalAudited(new LocalPackageId(self::UNKNOWN_PACKAGE), LocalResultRefused::PACKAGE_NOT_FOUND, null);
    }

    #[Test]
    public function aSecondResultIsAuditedAsGoneAndTheFirstIsNot(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        self::assertInstanceOf(LocalResult::class, $this->acceptAs(self::OMAR, $package->packageId, self::RESULT));
        self::assertSame([], $this->auditRowsOf(self::ACME_FOODS), 'A kept result was audited.');

        self::assertGone($this->acceptAs(self::OMAR, $package->packageId, self::RESULT));

        $this->assertOneRefusalAudited($package->packageId, LocalResultRefused::PACKAGE_GONE);
    }

    /** The entry keeps the code and a valid model name only: neither the text nor the rule's sentence. */
    #[Test]
    public function aRejectedResultIsAuditedWithoutItsText(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));

        $refusal = $this->acceptAs(self::OMAR, $package->packageId, str_repeat('MARKER-e6x ', 61));

        self::assertInstanceOf(LocalResultRefused::class, $refusal);
        self::assertSame(LocalResultRefused::RESULT_REJECTED, $refusal->refusalCode);
        $this->assertOneRefusalAudited($package->packageId, LocalResultRefused::RESULT_REJECTED);
        self::assertStringNotContainsString('MARKER', (string) json_encode($this->auditRowsOf(self::ACME_FOODS)));
    }

    #[Test]
    public function neitherTheTicketTextNorThePromptNorTheResultReachesALog(): void
    {
        $package = self::expectPackage($this->packageAs(self::OMAR, self::ON_THE_LAPTOP, self::checkoutTicketId()));
        self::assertInstanceOf(LocalResult::class, $this->acceptAs(self::OMAR, $package->packageId, self::RESULT));

        // CapturedLogRecords keeps a record before RedactionProcessor runs, so each goes through it here; the debug
        // records are DBAL's statements, whose bound values hold the text.
        $redaction = new RedactionProcessor();
        $formatter = new JsonFormatter();
        $lines = array_map(static fn (LogRecord $record): string => $formatter->format($redaction($record)), $this->capturedLogRecords()->all());
        self::assertNotSame([], $lines);
        foreach ($lines as $line) {
            self::assertStringNotContainsString('MARKER-e6t', $line);
            self::assertStringNotContainsString('MARKER-e6r', $line);
            self::assertStringNotContainsString('You write a short summary', $line);
        }
    }

    /**
     * Stores data.ai and asks for a package in one committed transaction under Acme Foods, as a page that catches the
     * refusal would commit it.
     */
    private function packageAs(string $requesterId, string $aiJson, TicketId $ticketId): PromptPackage|LocalPackageRefused
    {
        $localAi = $this->localAi();

        return self::transactions()->committedAs(self::ACME_FOODS, static function () use ($localAi, $requesterId, $aiJson, $ticketId): PromptPackage|LocalPackageRefused {
            self::connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb) WHERE organization_id = ?", [$aiJson, self::ACME_FOODS]);
            try {
                return $localAi->package(LocalAiPurpose::Summary, $ticketId, new UserId($requesterId), new OrganizationId(self::ACME_FOODS));
            } catch (LocalPackageRefused $refusal) {
                return $refusal;
            }
        });
    }

    /**
     * Posts the result under Acme Foods as tenant with no transaction open, as accept() runs one of its own.
     */
    private function acceptAs(string $posterId, LocalPackageId $packageId, string $text, string $model = self::MODEL, ?LocalAi $localAi = null): LocalResult|LocalResultRefused
    {
        $localAi ??= $this->localAi();
        self::assertFalse(self::connection()->isTransactionActive());
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            return $localAi->accept($packageId, $model, $text, new UserId($posterId), new OrganizationId(self::ACME_FOODS));
        } catch (LocalResultRefused $refusal) {
            return $refusal;
        } finally {
            self::entityManager()->clear();
            self::tenantContext()->clear();
        }
    }

    private function changeAiSettings(string $aiJson): void
    {
        self::transactions()->committedAs(self::ACME_FOODS, static fn (): int|string => self::connection()->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb) WHERE organization_id = ?",
            [$aiJson, self::ACME_FOODS],
        ));
    }

    /**
     * The audit entries pinround_app sees under $organizationId: actor kind, actor, action, target type, target, data.
     *
     * @return list<array{string, string|null, string, string, string|null, array<string, mixed>}>
     */
    private function auditRowsOf(string $organizationId): array
    {
        $rows = self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchAllNumeric(
            'SELECT actor_kind, actor_user_id, action, target_type, target_id, data::text FROM aud_event ORDER BY id',
        ));

        return array_map(static function (array $row): array {
            [$actorKind, $actorUserId, $action, $targetType, $targetId, $data] = $row;
            \assert(\is_string($actorKind) && (null === $actorUserId || \is_string($actorUserId)) && \is_string($action));
            \assert(\is_string($targetType) && (null === $targetId || \is_string($targetId)) && \is_string($data));
            /** @var array<string, mixed> $decoded a JSON object, as DbalAuditLog writes */
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);

            return [$actorKind, $actorUserId, $action, $targetType, $targetId, $decoded];
        }, $rows);
    }

    /**
     * @param string|null $model the model name the entry keeps, null for one that breaks LocalModelName's rule
     */
    private function assertOneRefusalAudited(LocalPackageId $packageId, string $reason, ?string $model = self::MODEL): void
    {
        self::assertEquals(
            [['user', self::OMAR, 'local_ai.result_refused', 'local_ai_package', $packageId->toString(), ['reason' => $reason, 'model' => $model]]],
            $this->auditRowsOf(self::ACME_FOODS),
        );
    }

    private static function expectPackage(PromptPackage|LocalPackageRefused $answer): PromptPackage
    {
        if ($answer instanceof LocalPackageRefused) {
            self::fail(\sprintf('The package was refused: %s (%s)', $answer->refusalCode, $answer->getMessage()));
        }

        return $answer;
    }

    private static function assertGone(LocalResult|LocalResultRefused $answer): void
    {
        self::assertInstanceOf(LocalResultRefused::class, $answer);
        self::assertSame(
            [LocalResultRefused::PACKAGE_GONE, 'This laptop AI request was used or ran out of time. Ask again.'],
            [$answer->refusalCode, $answer->getMessage()],
        );
    }

    private static function assertNotFound(LocalResult|LocalResultRefused $answer): void
    {
        self::assertInstanceOf(LocalResultRefused::class, $answer);
        self::assertSame(
            [LocalResultRefused::PACKAGE_NOT_FOUND, 'This laptop AI request does not exist.'],
            [$answer->refusalCode, $answer->getMessage()],
        );
    }

    /**
     * @return list<list<mixed>> the packages pinround_app sees under $organizationId, by id: id, user, ticket, purpose,
     *                           prompt version, created, expires, used (UTC), result text, model
     */
    private function packageRowsOf(string $organizationId): array
    {
        return self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchAllNumeric(
            "SELECT id, user_id, ticket_id, purpose, prompt_version,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
                    to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
                    result_text, model
             FROM ast_local_package ORDER BY id",
        ));
    }

    private function summaryHistory(): TicketSummaryHistory
    {
        $summaryQueries = self::getContainer()->get('test.ticketing_ticket_summary_queries');
        \assert($summaryQueries instanceof TicketSummaryQueries);
        $visibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($visibilities instanceof TicketVisibilities);

        $history = self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): ?TicketSummaryHistory => $summaryQueries->historyOf(
            self::checkoutTicketId(),
            $visibilities->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR)),
        ));
        self::assertNotNull($history);

        return $history;
    }

    private function localAi(): LocalAi
    {
        $localAi = self::getContainer()->get('test.assist_local_ai');
        \assert($localAi instanceof LocalAi);

        return $localAi;
    }

    private function watchedPackages(): WatchedLocalPackages
    {
        $packages = self::getContainer()->get('test.assist_local_packages');
        \assert($packages instanceof LocalPackages);

        return new WatchedLocalPackages($packages);
    }

    private function localAiWith(LocalPackages $localPackages): LocalAiService
    {
        $aiRequesters = self::getContainer()->get('test.assist_ai_requesters');
        \assert($aiRequesters instanceof AiRequesters);
        $aiTaskAdmission = self::getContainer()->get('test.assist_ai_task_admission');
        \assert($aiTaskAdmission instanceof AiTaskAdmission);
        $summaryCommands = self::getContainer()->get('test.ticketing_ticket_summary_commands');
        \assert($summaryCommands instanceof TicketSummaryCommands);
        $container = self::getContainer();

        return new LocalAiService(
            $aiRequesters,
            $aiTaskAdmission,
            $container->get(PromptRenderer::class),
            $container->get(AiAnswerCheck::class),
            $localPackages,
            new UuidV7LocalPackageIds(new Clock()),
            $container->get(MemberNameQueries::class),
            $container->get(AuditLog::class),
            $container->get(Transactions::class),
            $container->get(Savepoints::class),
            $summaryCommands,
            $container->get(\Psr\Clock\ClockInterface::class),
        );
    }

    private function capturedLogRecords(): CapturedLogRecords
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);

        return $capturedLogRecords;
    }

    private static function seedPackage(string $organizationId, string $packageId, string $userId, string $createdAt = '2027-03-15 09:59:00+00', string $expiresAt = '2027-03-15 10:04:00+00', ?string $usedAt = null): void
    {
        $isUsed = null !== $usedAt;
        self::asMigratorOf($organizationId, static function (Connection $migrator) use ($organizationId, $packageId, $userId, $createdAt, $expiresAt, $usedAt, $isUsed): void {
            $migrator->executeStatement(self::INSERT_PACKAGE, [
                $organizationId, $packageId, $userId, self::checkoutTicketId()->toString(), $createdAt, $expiresAt, $usedAt,
                $isUsed ? 'An earlier answer.' : null,
                $isUsed ? self::MODEL : null,
                $isUsed ? 'posted by Omar using local model '.self::MODEL.' (unverified)' : null,
            ]);
        });
    }

    private static function ticketIdOf(string $ticket): TicketId
    {
        return match ($ticket) {
            'checkout' => self::checkoutTicketId(),
            'team-only' => self::teamOnlyTicketId(),
            'unknown' => new TicketId('0197a3c0-0000-7000-8000-0000000e6a39'),
            default => throw new \LogicException('No ticket is called '.$ticket.'.'),
        };
    }

    private static function checkoutTicketId(): TicketId
    {
        return self::$checkoutTicketId ?? throw new \LogicException('The tickets are made before each class.');
    }

    private static function teamOnlyTicketId(): TicketId
    {
        return self::$teamOnlyTicketId ?? throw new \LogicException('The tickets are made before each class.');
    }

    /**
     * The app may not delete packages or audit entries; the migrator owns the tables, and row-level security applies
     * to it too. Omar leaves the Shop team a test put him in.
     */
    private static function removePackagesAndAuditEntries(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::asMigratorOf($organizationId, static function (Connection $migrator) use ($organizationId): void {
                $migrator->executeStatement('DELETE FROM ast_local_package WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]);
            });
        }
        self::removeOmarFromTheShopTeam();
    }

    private static function removeOmarFromTheShopTeam(): void
    {
        self::asMigratorOf(self::ACME_FOODS, static function (Connection $migrator): void {
            $migrator->executeStatement('DELETE FROM org_team_member WHERE organization_id = ? AND team_id = ? AND user_id = ?', [self::ACME_FOODS, self::SHOP_TEAM, self::OMAR]);
        });
    }

    private static function removeOmarFromAcmeFoods(): void
    {
        self::asMigratorOf(self::ACME_FOODS, static function (Connection $migrator): void {
            $migrator->executeStatement('DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?', [self::ACME_FOODS, self::OMAR]);
        });
    }

    private static function removeRows(): void
    {
        self::removePackagesAndAuditEntries();
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
    }

    /**
     * @param \Closure(Connection): void $work
     */
    private static function asMigratorOf(string $organizationId, \Closure $work): void
    {
        self::tenantContext()->set(new OrganizationId($organizationId));
        try {
            self::migrator()->transactional($work);
        } finally {
            self::tenantContext()->clear();
        }
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::entityManager()->getConnection();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
