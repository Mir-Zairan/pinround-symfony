<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Infrastructure\Doctrine;

use App\Assist\Application\AiCallStatus;
use App\Assist\Application\AiCallUsage;
use App\Assist\Application\ProviderAnswer;
use App\Assist\Application\ProviderChoice;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Doctrine\DbalMetering;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * ast_usage.status (Version20261117143000) as DbalMetering writes it, on the real table as pinround_app. The migrator,
 * which owns the table, truncates it around each test. The clock sits in March 2027, a month whose partition the
 * migrations always create.
 */
final class DbalMeteringStatusTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a601';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a611';
    private const string NOW = '2027-03-15 10:00:00.250 UTC';

    protected function setUp(): void
    {
        self::truncateUsage();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
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
    public function anAnsweredCallIsMeteredAsAnsweredWithItsTokens(): void
    {
        $this->record(AiCallUsage::answered(new ProviderAnswer('A summary.', 'claude-haiku-4-5', 'anthropic', 812, 64, 1350)));

        self::assertSame(
            [['status' => 'answered', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_tokens' => 812, 'output_tokens' => 64, 'duration_ms' => 1350]],
            $this->visibleRows(),
        );
    }

    #[Test]
    public function aRejectedAnswerIsMeteredAsRejectedWithTheTokensItCost(): void
    {
        $this->record(AiCallUsage::rejected(new ProviderAnswer('Not JSON.', 'qwen3:4b', 'ollama', 640, 12, 900)));

        self::assertSame(
            [['status' => 'rejected', 'provider' => 'ollama', 'model' => 'qwen3:4b', 'input_tokens' => 640, 'output_tokens' => 12, 'duration_ms' => 900]],
            $this->visibleRows(),
        );
    }

    #[Test]
    public function aFailedCallIsMeteredAsFailedWithUnknownTokensNotZero(): void
    {
        $this->record(AiCallUsage::failed(ProviderChoice::fromSettings('anthropic', 'claude-haiku-4-5'), 30000));

        self::assertSame(
            [['status' => 'failed', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_tokens' => null, 'output_tokens' => null, 'duration_ms' => 30000]],
            $this->visibleRows(),
        );
    }

    #[Test]
    public function aFailedCallWithoutAModelSetIsMeteredWithTheProvidersDefaultModel(): void
    {
        $this->record(AiCallUsage::failed(ProviderChoice::fromSettings(null), 15));

        $rows = $this->visibleRows();
        self::assertCount(1, $rows);
        self::assertSame('huggingface', $rows[0]['provider']);
        self::assertSame('meta-llama/Llama-3.1-8B-Instruct', $rows[0]['model']);
    }

    #[Test]
    public function aStatusOutsideTheCheckIsRefusedAndNothingIsKept(): void
    {
        try {
            $this->insertAsTheApp("'pending'");
            self::fail('A status outside answered, rejected and failed was kept.');
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString('ast_usage_status_check', $refusal->getMessage());
        }

        self::assertSame([], $this->visibleRows());
    }

    /** The default of the migration only filled the rows that existed; a writer that forgets the status fails. */
    #[Test]
    public function aRowWithoutAStatusIsRefusedAndNothingIsKept(): void
    {
        try {
            $this->insertAsTheApp('DEFAULT');
            self::fail('A row without a status was kept.');
        } catch (DriverException $refusal) {
            self::assertSame('23502', $refusal->getSQLState());
            self::assertStringContainsString('"status"', $refusal->getMessage());
        }

        self::assertSame([], $this->visibleRows());
    }

    #[Test]
    public function theStatusCheckAllowsExactlyTheCallStatuses(): void
    {
        $definition = $this->connection()->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'public.ast_usage'::regclass AND conname = 'ast_usage_status_check'",
        );
        self::assertIsString($definition);
        self::assertSame(1, preg_match('/^CHECK \(\(status = ANY \(ARRAY\[(.+)\]\)\)\)\z/', $definition, $match));
        $allowedStatuses = array_map(static fn (string $literal): string => substr($literal, 1, -\strlen("'::text")), explode(', ', $match[1]));

        self::assertEqualsCanonicalizing(array_map(static fn (AiCallStatus $status): string => $status->value, AiCallStatus::cases()), $allowedStatuses);
    }

    /** Neither the parent nor any partition keeps the migration's temporary default. */
    #[Test]
    public function noUsageTableHasADefaultStatus(): void
    {
        $tablesWithTheColumn = $this->connection()->fetchFirstColumn(
            "SELECT c.oid::regclass::text FROM pg_class c JOIN pg_attribute a ON a.attrelid = c.oid
             WHERE a.attname = 'status' AND NOT a.attisdropped
               AND (c.oid = 'public.ast_usage'::regclass OR c.oid IN (SELECT inhrelid FROM pg_inherits WHERE inhparent = 'public.ast_usage'::regclass))",
        );
        self::assertContains('ast_usage', $tablesWithTheColumn);
        self::assertContains('ast_usage_202703', $tablesWithTheColumn);

        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT d.adrelid::regclass::text FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
             WHERE a.attname = 'status'
               AND (d.adrelid = 'public.ast_usage'::regclass OR d.adrelid IN (SELECT inhrelid FROM pg_inherits WHERE inhparent = 'public.ast_usage'::regclass))",
        ));
    }

    private function record(AiCallUsage $usage): void
    {
        $metering = new DbalMetering($this->connection(), $this->tenantContext(), new MockClock(self::NOW));
        $metering->record($usage, AiFeature::Summary, new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()));
    }

    private function insertAsTheApp(string $statusLiteral): void
    {
        $this->connection()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
            'INSERT INTO ast_usage (organization_id, id, user_id, feature, status, provider, model, duration_ms, at)'
            ." VALUES (?, gen_random_uuid(), ?, 'summary', ".$statusLiteral.", 'anthropic', 'claude-haiku-4-5', 1350, '2027-03-15 10:00:00+00')",
            [self::ACME_FOODS, self::OMAR],
        ));
    }

    /**
     * @return list<array<string, mixed>> the rows the tenant sees
     */
    private function visibleRows(): array
    {
        return $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchAllAssociative(
            'SELECT status, provider, model, input_tokens, output_tokens, duration_ms FROM ast_usage ORDER BY at',
        ));
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private static function truncateUsage(): void
    {
        self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('TRUNCATE ast_usage');
    }
}
