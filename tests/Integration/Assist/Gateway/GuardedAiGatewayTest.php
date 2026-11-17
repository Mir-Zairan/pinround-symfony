<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Gateway;

use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiCallStatus;
use App\Assist\Application\AiDraft;
use App\Assist\Application\AiGateway;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiResult;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\RateLimiter\Policy\Window;

/**
 * The gateway as the test container wires it (FakeProvider, the real policy, prompt builder, answer check, limit on
 * the test Valkey and DbalMetering), as pinround_app in a transaction under Acme Foods that each test rolls back, so
 * its usage rows are read inside it. Lena leads the Shop team that owns SHOP; Omar is in Acme Foods but in no team, so
 * he sees the checkout ticket for everyone but not the team-only one. Beta Bakery has a ticket for everyone under the
 * key SHOP-7, the checkout ticket's key in Acme Foods, so a leaked id would summarize the wrong ticket. The clock is
 * frozen in March 2027, a month whose usage partition the migrations always create. A draft report in SHOP has both
 * open tickets as candidates for Lena, the team-only one only when the admin lets team-only tickets go to cloud AI; for
 * Omar it never has the team-only one.
 */
final class GuardedAiGatewayTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a9a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a9a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a9c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000a9d1';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000a9d2';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-00000000a9e2';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a9b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a9b2';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string TICKET_TEXT = 'Pressing Pay on the last checkout step does nothing.';
    private const string AI_ON_IN_THE_CLOUD = '{"on": true, "where": "cloud"}';
    private const string USAGE_LIMITER_PREFIX = 'ai_calls_per_user-';
    private const string AI_ON_IN_THE_CLOUD_WITH_TEAM_ONLY = '{"on": true, "where": "cloud", "restricted_to_cloud": true}';

    private static ?TicketId $checkoutTicketId = null;
    private static ?TicketId $teamOnlyTicketId = null;

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [self::LENA => ['Lena', 'lead', 'developer']]);
        self::shopTeamRows()->addUser(self::OMAR, 'Omar');
        self::transactions()->committedAs(self::ACME_FOODS, static function (): void {
            self::connection()->executeStatement(
                "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::OMAR],
            );
            self::connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, '{}'::jsonb, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS],
            );
        });

        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $tickets = new TicketFactory($ticketCommands, self::transactions(), self::connection());
        self::$checkoutTicketId = $tickets->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Checkout fails', 'description' => self::TICKET_TEXT]);
        $teamOnlyTicketId = $tickets->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Card numbers in the log', 'description' => self::TICKET_TEXT]);
        self::$teamOnlyTicketId = $teamOnlyTicketId;
        // No command restricts a ticket yet; the turn row copies the column.
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::migrator()->transactional(static function (Connection $migrator) use ($teamOnlyTicketId): void {
                $migrator->executeStatement("UPDATE tkt_ticket SET visibility = 'team' WHERE id = ?", [$teamOnlyTicketId->toString()]);
                $migrator->executeStatement("UPDATE ins_ticket_turn SET visibility = 'team' WHERE ticket_id = ?", [$teamOnlyTicketId->toString()]);
            });
        } finally {
            self::tenantContext()->clear();
        }
        new TicketRows(self::migrator(), self::tenantContext())->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA, 7);
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
        // The limiter counts in Valkey across runs; each test starts Omar and Lena with no calls this hour.
        self::forgetCountedCalls();
    }

    protected function tearDown(): void
    {
        self::forgetCountedCalls();
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function aSummaryComesBackWithTheFakesFixedTextAndProviderAndWritesOneUsageRowWithoutContent(): void
    {
        [$result, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(self::checkoutTicketId()));

        self::assertInstanceOf(AiResult::class, $result);
        self::assertSame(
            [AiCallStatus::Answered, FakeProvider::fixedTextFor(AiFeature::Summary), 'fake', 'meta-llama/Llama-3.1-8B-Instruct', 'summary.v1', 'AI summary'],
            [$result->status, $result->text, $result->provider, $result->model, $result->promptVersion, $result->label],
        );
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertStringContainsString(self::TICKET_TEXT, $this->fakeProvider()->calls()[0]['prompt']->content);
        self::assertCount(1, $usageRows);
        self::assertEqualsCanonicalizing(
            ['organization_id', 'id', 'user_id', 'feature', 'status', 'provider', 'model', 'input_tokens', 'output_tokens', 'duration_ms', 'cost_estimate', 'at', 'at_utc'],
            array_keys($usageRows[0]),
        );
        self::assertSame(
            ['user_id' => self::OMAR, 'feature' => 'summary', 'provider' => 'fake', 'model' => 'meta-llama/Llama-3.1-8B-Instruct', 'status' => 'answered', 'at_utc' => '2027-03-15 10:00:00+00'],
            array_intersect_key($usageRows[0], array_flip(['user_id', 'feature', 'status', 'provider', 'model', 'at_utc'])),
        );
        self::assertIsInt($usageRows[0]['input_tokens']);
        self::assertGreaterThan(0, $usageRows[0]['input_tokens']);
        $everyColumn = implode("\n", array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $usageRows[0]));
        self::assertStringNotContainsString(self::TICKET_TEXT, $everyColumn);
        self::assertStringNotContainsString('checkout button', $everyColumn);
    }

    #[Test]
    public function withAiOffTheRefusalSaysSoAndNoProviderCallOrUsageRowIsMade(): void
    {
        [$refusal, $usageRows] = $this->runAsOmar('{"on": false}', self::summaryOf(self::checkoutTicketId()));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame(PolicyRefusal::AiOff, $refusal->refusal);
        self::assertSame('Your admin turned AI help off.', $refusal->getMessage());
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::OMAR));
    }

    #[Test]
    public function aTeamOnlyTicketOmarMayNotSeeIsRefusedAsOneHeCannotSee(): void
    {
        [$refusal, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(self::teamOnlyTicketId()));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame(PolicyRefusal::CannotSeeTicket, $refusal->refusal);
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
    }

    #[Test]
    public function anUnknownTicketIsRefusedExactlyAsAHiddenOne(): void
    {
        [$refusal, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(new TicketId('0197a3c0-0000-7000-8000-00000000a9e9')));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame(PolicyRefusal::CannotSeeTicket, $refusal->refusal);
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
    }

    #[Test]
    public function anotherOrganizationsTicketIsRefusedAsOneOmarCannotSee(): void
    {
        [$refusal, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(new TicketId(self::BETA_TICKET)));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame([PolicyRefusal::CannotSeeTicket, 'AI help works only on tickets you can see.'], [$refusal->refusal, $refusal->getMessage()]);
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::OMAR));
        self::assertSame([self::BETA_TICKET], $this->ticketIdsSeenUnder(self::BETA_BAKERY), 'Beta Bakery does not have the ticket either, so the refusal proves nothing.');
    }

    /**
     * The gateway reads tickets and settings with SQL, so the ORM's tenant filter is not what keeps Beta Bakery's ticket
     * out: with it off, the gateway still refuses, and in the same transaction a read that names no organization finds
     * only Acme Foods' tickets, which is row-level security alone.
     */
    #[Test]
    public function rowLevelSecurityKeepsAnotherOrganizationsTicketFromTheGatewayWithTheTenantFilterOff(): void
    {
        self::entityManager()->getFilters()->disable(TenantFilter::NAME);

        [$refusal, $usageRows, $ticketIdsSeen] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(new TicketId(self::BETA_TICKET)));

        self::assertFalse(self::entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame(PolicyRefusal::CannotSeeTicket, $refusal->refusal);
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::OMAR));
        self::assertEqualsCanonicalizing([self::checkoutTicketId()->toString(), self::teamOnlyTicketId()->toString()], $ticketIdsSeen);
    }

    #[Test]
    public function aTeamOnlyTicketLenaMaySeeIsKeptFromCloudAiWithoutTheAdminsOptIn(): void
    {
        [$refusal, $usageRows] = $this->runAs(self::lena(), self::AI_ON_IN_THE_CLOUD, self::summaryOf(self::teamOnlyTicketId()));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame([PolicyRefusal::RestrictedTicketToCloud, 'Your admin keeps team-only tickets away from cloud AI.'], [$refusal->refusal, $refusal->getMessage()]);
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::LENA));
    }

    #[Test]
    public function aTeamOnlyTicketLenaMaySeeGoesToCloudAiWhenTheAdminAllowsIt(): void
    {
        [$result, $usageRows] = $this->runAs(self::lena(), '{"on": true, "where": "cloud", "restricted_to_cloud": true}', self::summaryOf(self::teamOnlyTicketId()));

        self::assertInstanceOf(AiResult::class, $result);
        self::assertSame([AiCallStatus::Answered, FakeProvider::fixedTextFor(AiFeature::Summary)], [$result->status, $result->text]);
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertStringContainsString('Card numbers in the log', $this->fakeProvider()->calls()[0]['prompt']->content);
        self::assertSame([[self::LENA, 'answered']], array_map(static fn (array $row): array => [$row['user_id'], $row['status']], $usageRows));
        self::assertSame(1, $this->countedCallsOf(self::LENA));
    }

    #[Test]
    public function aPersonAtTheLimitIsRefusedAndTheProviderIsNotAsked(): void
    {
        $limit = self::getContainer()->get('test.assist_ai_call_limit');
        \assert($limit instanceof AiCallLimit);
        for ($call = 1; $call <= 30; ++$call) {
            $limit->consume(self::omar());
        }

        [$refusal, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(self::checkoutTicketId()));

        self::assertInstanceOf(AiRateLimited::class, $refusal);
        self::assertSame('Too many AI requests. Try again in a minute.', $refusal->getMessage());
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(30, $this->countedCallsOf(self::OMAR));
    }

    /** The fake answers intake with "{}", which the schema refuses, so the one retry is refused too. */
    #[Test]
    public function aRejectedAnswerIsReturnedNotThrownAndEachProviderCallKeepsItsRejectedRow(): void
    {
        [$result, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::intakeOf(self::draft()));

        self::assertInstanceOf(AiResult::class, $result);
        self::assertSame(
            [AiCallStatus::Rejected, null, 'The AI answer does not match the intake.v1 schema.'],
            [$result->status, $result->data, $result->failureReason],
        );
        self::assertCount(2, $this->fakeProvider()->calls());
        self::assertSame([['intake', 'rejected', 'fake'], ['intake', 'rejected', 'fake']], array_map(
            static fn (array $row): array => [$row['feature'], $row['status'], $row['provider']],
            $usageRows,
        ));
        self::assertSame(1, $this->countedCallsOf(self::OMAR));
    }

    #[Test]
    public function aProviderFailureIsReturnedNotThrownAndKeepsItsFailedRowWithoutTokens(): void
    {
        $this->fakeProvider()->failNextCallWith(AiUnavailable::failed('huggingface', \RuntimeException::class));

        [$result, $usageRows] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD, self::summaryOf(self::checkoutTicketId()));

        self::assertInstanceOf(AiResult::class, $result);
        self::assertSame([AiCallStatus::Failed, 'The AI provider huggingface did not answer (RuntimeException).'], [$result->status, $result->failureReason]);
        self::assertSame([['failed', 'huggingface', null, null]], array_map(
            static fn (array $row): array => [$row['status'], $row['provider'], $row['input_tokens'], $row['output_tokens']],
            $usageRows,
        ));
    }

    #[Test]
    public function anUndecryptableOrganizationKeyRefusesTheCallWithoutAskingTheProvider(): void
    {
        [$refusal, $usageRows] = $this->runAsOmar(
            '{"on": true, "where": "cloud", "provider": "anthropic", "secret_enc": "not base64!", "key_id": "change-me-test"}',
            self::summaryOf(self::checkoutTicketId()),
        );

        self::assertInstanceOf(AiUnavailable::class, $refusal);
        self::assertSame('The organization\'s key for the AI provider anthropic cannot be read. An admin must enter it again.', $refusal->getMessage());
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::OMAR));
    }

    #[Test]
    public function aDraftGoesOutWithTheOpenTicketsLenaMaySeeButNotTheTeamOnlyOneAndOnlyASentKeySurvives(): void
    {
        [$checkoutKey, $teamOnlyKey] = [self::keyOf(self::checkoutTicketId()), self::keyOf(self::teamOnlyTicketId())];
        $this->fakeProvider()->answerNextCallsWith(self::intakeJsonNaming($teamOnlyKey, $checkoutKey, 'SHOP-999'));

        [$result, $usageRows] = $this->runAs(self::lena(), self::AI_ON_IN_THE_CLOUD, self::intakeOf(self::draft()));

        self::assertInstanceOf(AiResult::class, $result);
        self::assertSame([AiCallStatus::Answered, [$checkoutKey]], [$result->status, $result->data['duplicates'] ?? null]);
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertSame([$checkoutKey], self::candidateKeysIn($this->fakeProvider()->calls()[0]['prompt']->content));
        self::assertSame([['intake', 'answered']], array_map(static fn (array $row): array => [$row['feature'], $row['status']], $usageRows));
    }

    #[Test]
    public function aDraftGoesOutWithTheTeamOnlyTicketLenaMaySeeWhenTheAdminLetsItGoToCloudAi(): void
    {
        [$checkoutKey, $teamOnlyKey] = [self::keyOf(self::checkoutTicketId()), self::keyOf(self::teamOnlyTicketId())];
        $this->fakeProvider()->answerNextCallsWith('not json', self::intakeJsonNaming($teamOnlyKey, 'SHOP-999'));

        [$result] = $this->runAs(self::lena(), self::AI_ON_IN_THE_CLOUD_WITH_TEAM_ONLY, self::intakeOf(self::draft()));

        self::assertInstanceOf(AiResult::class, $result);
        self::assertSame([AiCallStatus::Answered, [$teamOnlyKey]], [$result->status, $result->data['duplicates'] ?? null]);
        self::assertCount(2, $this->fakeProvider()->calls());
        foreach ($this->fakeProvider()->calls() as $call) {
            self::assertEqualsCanonicalizing([$checkoutKey, $teamOnlyKey], self::candidateKeysIn($call['prompt']->content));
        }
    }

    #[Test]
    public function aDraftOfOmarNeverCarriesTheShopTeamsTeamOnlyTicketEvenWhenTheAdminLetsTeamOnlyTicketsGoToCloudAi(): void
    {
        [$checkoutKey, $teamOnlyKey] = [self::keyOf(self::checkoutTicketId()), self::keyOf(self::teamOnlyTicketId())];
        $this->fakeProvider()->answerNextCallsWith(self::intakeJsonNaming($teamOnlyKey, $checkoutKey), self::intakeJsonNaming($teamOnlyKey, $checkoutKey));

        [$omarsResult] = $this->runAsOmar(self::AI_ON_IN_THE_CLOUD_WITH_TEAM_ONLY, self::intakeOf(self::draft()));
        [$lenasResult] = $this->runAs(self::lena(), self::AI_ON_IN_THE_CLOUD_WITH_TEAM_ONLY, self::intakeOf(self::draft()));

        self::assertCount(2, $this->fakeProvider()->calls());
        $omarsCandidateKeys = self::candidateKeysIn($this->fakeProvider()->calls()[0]['prompt']->content);
        $lenasCandidateKeys = self::candidateKeysIn($this->fakeProvider()->calls()[1]['prompt']->content);
        self::assertNotSame([], $omarsCandidateKeys);
        self::assertNotSame([], $lenasCandidateKeys);
        self::assertSame([$checkoutKey], $omarsCandidateKeys);
        self::assertEqualsCanonicalizing([$checkoutKey, $teamOnlyKey], $lenasCandidateKeys);
        self::assertInstanceOf(AiResult::class, $omarsResult);
        self::assertInstanceOf(AiResult::class, $lenasResult);
        self::assertSame([[$checkoutKey], [$teamOnlyKey, $checkoutKey]], [$omarsResult->data['duplicates'] ?? null, $lenasResult->data['duplicates'] ?? null]);
    }

    #[Test]
    public function aDraftThatMentionsSecurityIsRefusedWithoutAProviderCallAUsageRowOrACountedCall(): void
    {
        $draft = new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay does nothing.', ['The password field is empty.']);

        [$refusal, $usageRows] = $this->runAs(self::lena(), self::AI_ON_IN_THE_CLOUD_WITH_TEAM_ONLY, self::intakeOf($draft));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame(
            [PolicyRefusal::SecuritySensitiveDraftToCloud, 'This report mentions security, so it stays out of cloud AI.'],
            [$refusal->refusal, $refusal->getMessage()],
        );
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::LENA));
    }

    #[Test]
    public function aDraftThatMentionsSecurityWithAiOffIsRefusedAsAiOff(): void
    {
        $draft = new AiDraft(self::SHOP_PROJECT, 'Password reset mail never comes', 'I asked twice.');

        [$refusal, $usageRows] = $this->runAs(self::lena(), '{"on": false}', self::intakeOf($draft));

        self::assertInstanceOf(AiNotAllowed::class, $refusal);
        self::assertSame(PolicyRefusal::AiOff, $refusal->refusal);
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame([], $usageRows);
        self::assertSame(0, $this->countedCallsOf(self::LENA));
    }

    /**
     * @return array{AiResult|\Throwable, list<array<string, mixed>>, list<string>} as runAs
     */
    private function runAsOmar(string $aiJson, AiTask $task): array
    {
        return $this->runAs(self::omar(), $aiJson, $task);
    }

    /**
     * Stores data.ai, runs the gateway as the viewer and reads the usage rows and the ids of every ticket the
     * transaction sees, all in one transaction under Acme Foods that is rolled back.
     *
     * @return array{AiResult|\Throwable, list<array<string, mixed>>, list<string>} the result or what the gateway threw,
     *                                                                              the usage rows, the ticket ids
     */
    private function runAs(ActorContext $viewer, string $aiJson, AiTask $task): array
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($viewer, $aiJson, $task): array {
            self::connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb) WHERE organization_id = ?", [$aiJson, self::ACME_FOODS]);
            try {
                $outcome = $this->gateway()->run($task, $viewer);
            } catch (AiNotAllowed|AiRateLimited|AiUnavailable $refusal) {
                $outcome = $refusal;
            }

            /** @var list<array<string, mixed>> $usageRows */
            $usageRows = self::connection()->fetchAllAssociative("SELECT *, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00') AS at_utc FROM ast_usage ORDER BY id");
            /** @var list<string> $ticketIds */
            $ticketIds = self::connection()->fetchFirstColumn('SELECT id FROM tkt_ticket');

            return [$outcome, $usageRows, $ticketIds];
        });
    }

    /**
     * @return list<string>
     */
    private function ticketIdsSeenUnder(string $organizationId): array
    {
        /** @var list<string> $ticketIds */
        $ticketIds = self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchFirstColumn('SELECT id FROM tkt_ticket'));

        return $ticketIds;
    }

    private function gateway(): AiGateway
    {
        $gateway = self::getContainer()->get('test.assist_ai_gateway');
        \assert($gateway instanceof AiGateway);

        return $gateway;
    }

    private function fakeProvider(): FakeProvider
    {
        return self::getContainer()->get(FakeProvider::class);
    }

    private function countedCallsOf(string $userId): int
    {
        $window = self::getContainer()->get(ValkeyLimiterStorage::class)->fetch(self::USAGE_LIMITER_PREFIX.self::ACME_FOODS.':'.$userId);

        if (null === $window) {
            return 0;
        }
        self::assertInstanceOf(Window::class, $window);

        return $window->getHitCount();
    }

    private static function forgetCountedCalls(): void
    {
        foreach ([self::OMAR, self::LENA] as $userId) {
            self::getContainer()->get(ValkeyLimiterStorage::class)->delete(self::USAGE_LIMITER_PREFIX.self::ACME_FOODS.':'.$userId);
        }
    }

    private static function summaryOf(TicketId $ticketId): AiTask
    {
        return new AiTask(AiFeature::Summary, $ticketId);
    }

    private static function intakeOf(AiDraft $draft): AiTask
    {
        return new AiTask(AiFeature::Intake, draft: $draft);
    }

    private static function draft(): AiDraft
    {
        return new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay on the last step does nothing.', ['The button.']);
    }

    private static function intakeJsonNaming(string ...$duplicateKeys): string
    {
        return json_encode(
            ['summary' => 'Pay does nothing.', 'type' => 'Bug', 'priority' => 'Major', 'duplicates' => array_values($duplicateKeys), 'missing' => false, 'risky' => false],
            \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string> the keys between <candidates> and </candidates>, in prompt order; none when the block is missing
     */
    private static function candidateKeysIn(string $promptContent): array
    {
        if (1 !== preg_match('#<candidates>\n(.*?)</candidates>#s', $promptContent, $block)) {
            return [];
        }
        preg_match_all('/^([A-Z][A-Z0-9]+-[0-9]+): /m', $block[1], $keys);

        return $keys[1];
    }

    private static function keyOf(TicketId $ticketId): string
    {
        $key = self::transactions()->rolledBackAs(
            self::ACME_FOODS,
            static fn (): mixed => self::connection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId->toString()]),
        );
        if (!\is_string($key)) {
            throw new \LogicException('The tickets are made before each class.');
        }

        return $key;
    }

    private static function omar(): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none());
    }

    private static function lena(): ActorContext
    {
        return new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [], []));
    }

    private static function checkoutTicketId(): TicketId
    {
        return self::$checkoutTicketId ?? throw new \LogicException('The tickets are made before each class.');
    }

    private static function teamOnlyTicketId(): TicketId
    {
        return self::$teamOnlyTicketId ?? throw new \LogicException('The tickets are made before each class.');
    }

    private static function removeRows(): void
    {
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
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
