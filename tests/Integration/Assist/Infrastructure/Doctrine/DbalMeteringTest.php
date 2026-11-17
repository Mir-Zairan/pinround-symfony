<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Infrastructure\Doctrine;

use App\Assist\Application\Metering;
use App\Assist\Application\ProviderAnswer;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Doctrine\DbalMetering;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Metering on the real ast_usage table (Version20261117100000) as pinround_app. Rows are committed so another
 * organization can look for them; the migrator, which owns the table, truncates it around each test. The clock sits in
 * March 2027, a month whose partition the migration always creates.
 */
final class DbalMeteringTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a501';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-00000000a502';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a511';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a512';
    private const string NOW = '2027-03-15 10:00:00.250 UTC';

    protected function setUp(): void
    {
        self::truncateUsage();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::truncateUsage();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    #[Test]
    public function oneCallWritesExactlyOneRowWithItsMetadataInTheClocksMonth(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->metering()->record(
            new ProviderAnswer('The checkout button is hidden behind the cookie banner.', 'claude-haiku-4-5', 'anthropic', 812, 64, 1350),
            AiFeature::Explain,
            $this->actor(self::OMAR, self::ACME_FOODS),
        );

        self::assertSame(
            [[
                'organization_id' => self::ACME_FOODS,
                'user_id' => self::OMAR,
                'feature' => 'explain',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
                'input_tokens' => 812,
                'output_tokens' => 64,
                'duration_ms' => 1350,
                'cost_estimate' => null,
                'at' => '2027-03-15 10:00:00.250',
                'partition' => 'ast_usage_202703',
            ]],
            $this->visibleRows(),
        );
    }

    #[Test]
    public function tokenCountsTheProviderDidNotReportStayUnknownNotZero(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->metering()->record(new ProviderAnswer('A summary.', 'qwen3:4b', 'ollama', null, null, 0), AiFeature::Summary, $this->actor(self::OMAR, self::ACME_FOODS));

        $rows = $this->visibleRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['input_tokens']);
        self::assertNull($rows[0]['output_tokens']);
        self::assertSame(0, $rows[0]['duration_ms']);
    }

    /** No prompt, answer or other text from a person or a model has a column to land in. */
    #[Test]
    public function theTableHasOnlyMetadataColumns(): void
    {
        self::assertSame(
            ['organization_id', 'id', 'user_id', 'feature', 'provider', 'model', 'input_tokens', 'output_tokens', 'duration_ms', 'cost_estimate', 'at'],
            $this->connection()->fetchFirstColumn(
                "SELECT attname FROM pg_attribute WHERE attrelid = 'public.ast_usage'::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum",
            ),
        );
    }

    #[Test]
    public function eachOrganizationSeesOnlyItsOwnUsageRows(): void
    {
        $this->recordAs(self::ACME_FOODS, self::OMAR, AiFeature::Summary);
        $this->recordAs(self::ACME_FOODS, self::MIA, AiFeature::Intake);
        $this->recordAs(self::OTHER_ORGANIZATION, self::OMAR, AiFeature::Explain);

        self::assertEqualsCanonicalizing(
            [[self::ACME_FOODS, self::OMAR, 'summary'], [self::ACME_FOODS, self::MIA, 'intake']],
            $this->callsSeenBy(self::ACME_FOODS, 'SELECT organization_id, user_id, feature FROM ast_usage'),
        );
        self::assertSame(
            [[self::OTHER_ORGANIZATION, self::OMAR, 'explain']],
            $this->callsSeenBy(self::OTHER_ORGANIZATION, 'SELECT organization_id, user_id, feature FROM ast_usage'),
        );
    }

    #[Test]
    public function aPartitionQueriedDirectlyKeepsItsRowsToTheirOwnOrganization(): void
    {
        $this->recordAs(self::ACME_FOODS, self::OMAR, AiFeature::Summary);

        // The migrator owns the partition, so only the partition's own row-level security stands between it and the row.
        $seenByOther = $this->asMigratorUnder(self::OTHER_ORGANIZATION, static fn (Connection $migrator): array => $migrator->fetchFirstColumn('SELECT user_id FROM ast_usage_202703'));
        $seenByOwn = $this->asMigratorUnder(self::ACME_FOODS, static fn (Connection $migrator): array => $migrator->fetchFirstColumn('SELECT user_id FROM ast_usage_202703'));

        self::assertSame([], $seenByOther);
        self::assertSame([self::OMAR], $seenByOwn, 'The row is not in the partition under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function aCallerOfAnotherOrganizationIsRefusedAndNothingIsWritten(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        try {
            $this->metering()->record(self::answer(), AiFeature::Summary, $this->actor(self::OMAR, self::OTHER_ORGANIZATION));
            self::fail('A call of another organization was metered under the tenant.');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('the caller acted in another one', $refusal->getMessage());
        }

        self::assertSame([], $this->visibleRows());
        $this->tenantContext()->clear();
        self::assertSame([], $this->callsSeenBy(self::OTHER_ORGANIZATION, 'SELECT user_id FROM ast_usage'));
    }

    #[Test]
    public function withoutATenantNothingIsWritten(): void
    {
        try {
            $this->metering()->record(self::answer(), AiFeature::Summary, $this->actor(self::OMAR, self::ACME_FOODS));
            self::fail('A call was metered with no tenant set.');
        } catch (TenantNotSet) {
        }

        self::assertSame([], $this->callsSeenBy(self::ACME_FOODS, 'SELECT user_id FROM ast_usage'));
    }

    #[Test]
    public function insideATransactionTheRowIsRolledBackWithIt(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->connection()->beginTransaction();
        $this->metering()->record(self::answer(), AiFeature::Summary, $this->actor(self::OMAR, self::ACME_FOODS));
        $this->connection()->rollBack();

        self::assertSame([], $this->visibleRows());
    }

    #[Test]
    public function theAppCanNeitherChangeNorRemoveAUsageRow(): void
    {
        $this->recordAs(self::ACME_FOODS, self::OMAR, AiFeature::Summary);

        foreach (['UPDATE ast_usage SET input_tokens = 0', 'DELETE FROM ast_usage'] as $sql) {
            try {
                $this->callsSeenBy(self::ACME_FOODS, $sql);
                self::fail('Not refused: '.$sql);
            } catch (DriverException $refusal) {
                self::assertSame('42501', $refusal->getSQLState());
                self::assertStringContainsString('permission denied for table ast_usage', $refusal->getMessage());
            }
        }

        self::assertSame([[812]], $this->callsSeenBy(self::ACME_FOODS, 'SELECT input_tokens FROM ast_usage'));
    }

    #[Test]
    public function theFeatureCheckAllowsExactlyTheAiFeatures(): void
    {
        $definition = $this->connection()->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'public.ast_usage'::regclass AND conname = 'ast_usage_feature_check'",
        );
        self::assertIsString($definition);
        self::assertSame(1, preg_match('/^CHECK \(\(feature = ANY \(ARRAY\[(.+)\]\)\)\)\z/', $definition, $match));
        $allowedFeatures = array_map(static fn (string $literal): string => substr($literal, 1, -\strlen("'::text")), explode(', ', $match[1]));

        self::assertEqualsCanonicalizing(array_map(static fn (AiFeature $feature): string => $feature->value, AiFeature::cases()), $allowedFeatures);
    }

    /**
     * @return iterable<string, array{ProviderAnswer, string}>
     */
    public static function answersTheTableRefuses(): iterable
    {
        yield 'negative input tokens' => [new ProviderAnswer('x', 'claude-haiku-4-5', 'anthropic', -1, 64, 1350), 'ast_usage_input_tokens_check'];
        yield 'negative output tokens' => [new ProviderAnswer('x', 'claude-haiku-4-5', 'anthropic', 812, -1, 1350), 'ast_usage_output_tokens_check'];
        yield 'a negative duration' => [new ProviderAnswer('x', 'claude-haiku-4-5', 'anthropic', 812, 64, -1), 'ast_usage_duration_ms_check'];
        yield 'no model' => [new ProviderAnswer('x', '', 'anthropic', 812, 64, 1350), 'ast_usage_model_check'];
        yield 'a model one character over 200' => [new ProviderAnswer('x', str_repeat('é', 201), 'anthropic', 812, 64, 1350), 'ast_usage_model_check'];
        yield 'no provider' => [new ProviderAnswer('x', 'claude-haiku-4-5', '', 812, 64, 1350), 'ast_usage_provider_check'];
        yield 'a provider one character over 64' => [new ProviderAnswer('x', 'claude-haiku-4-5', str_repeat('a', 65), 812, 64, 1350), 'ast_usage_provider_check'];
    }

    #[Test]
    #[DataProvider('answersTheTableRefuses')]
    public function theTableRefusesAnAnswerOutOfRangeAndKeepsNothing(ProviderAnswer $answer, string $violatedConstraint): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        try {
            $this->metering()->record($answer, AiFeature::Summary, $this->actor(self::OMAR, self::ACME_FOODS));
            self::fail('Not refused: '.$violatedConstraint);
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString($violatedConstraint, $refusal->getMessage());
        }

        self::assertSame([], $this->visibleRows());
    }

    #[Test]
    public function theLongestModelAndProviderNamesAreKept(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->metering()->record(new ProviderAnswer('x', str_repeat('é', 200), str_repeat('a', 64), 812, 64, 1350), AiFeature::Summary, $this->actor(self::OMAR, self::ACME_FOODS));

        self::assertSame([[str_repeat('é', 200), str_repeat('a', 64)]], $this->callsSeenBy(self::ACME_FOODS, 'SELECT model, provider FROM ast_usage'));
    }

    private static function answer(): ProviderAnswer
    {
        return new ProviderAnswer('A summary.', 'claude-haiku-4-5', 'anthropic', 812, 64, 1350);
    }

    private function recordAs(string $organizationId, string $userId, AiFeature $feature): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->metering()->record(self::answer(), $feature, $this->actor($userId, $organizationId));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * @return list<list<mixed>>
     */
    private function callsSeenBy(string $organizationId, string $sql): array
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            return $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchAllNumeric($sql));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * @return list<array<string, mixed>> the rows the current tenant sees
     */
    private function visibleRows(): array
    {
        return $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchAllAssociative(
            "SELECT organization_id, user_id, feature, provider, model, input_tokens, output_tokens, duration_ms, cost_estimate,
                    to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') AS at, tableoid::regclass::text AS partition
             FROM ast_usage ORDER BY at",
        ));
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function asMigratorUnder(string $organizationId, \Closure $work): mixed
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        $migrator = self::migrator();
        $migrator->beginTransaction();
        try {
            return $work($migrator);
        } finally {
            $migrator->rollBack();
            $this->tenantContext()->clear();
        }
    }

    private function actor(string $userId, string $organizationId): ActorContext
    {
        return new ActorContext(new UserId($userId), new OrganizationId($organizationId), Roles::none());
    }

    private function metering(): Metering
    {
        return new DbalMetering($this->connection(), $this->tenantContext(), new MockClock(self::NOW));
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function truncateUsage(): void
    {
        self::migrator()->executeStatement('TRUNCATE ast_usage');
    }
}
