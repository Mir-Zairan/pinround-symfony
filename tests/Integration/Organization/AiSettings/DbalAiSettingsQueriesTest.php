<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization\AiSettings;

use App\Organization\Api\AiSettings;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DbalAiSettingsQueries;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Encryption\SodiumFieldEncryption;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalAiSettingsQueries as pinround_app on committed org_settings rows, in a transaction under a tenant, as inside a
 * request, with the container's FieldEncryption (the .env.test key).
 */
final class DbalAiSettingsQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a7a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a7b1';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a7c1';
    private const string PROVIDER_KEY = 'hf_acme_foods_provider_key_7731';

    private const array AI_OFF = [false, false, false, false, 'cloud', false, null, null, null, false];

    /** @var list<array{string, array<array-key, mixed>}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->transactions()->committedAs($organizationId, fn () => new DoctrineOrgSettingsStore($this->entityManager())->add(
                new OrgSettings(new OrganizationId($organizationId), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
            ));
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function settingsWithoutAnAiKeyHaveAiOff(): void
    {
        self::assertSame(self::AI_OFF, self::shapeOf($this->settingsWithData(self::ACME_FOODS, "data - 'ai'")));
        self::assertSame(self::AI_OFF, self::shapeOf($this->settingsWithData(self::ACME_FOODS, "jsonb_set(data, '{ai}', 'null')")));
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function anOrganizationWithoutSettingsHasAiOff(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);

        self::assertSame(self::AI_OFF, self::shapeOf($this->aiSettingsAs(self::ACME_FOODS, self::ACME_FOODS)));
    }

    #[Test]
    public function noTenantReadsAiOff(): void
    {
        $this->storeAi(self::ACME_FOODS, ['on' => true]);

        self::assertSame(self::AI_OFF, self::shapeOf($this->queries()->aiSettingsOf(new OrganizationId(self::ACME_FOODS))));
    }

    #[Test]
    public function aNewOrganizationsAiSettingsAreReadAndTheLaptopModelIsNotTheCloudModel(): void
    {
        self::assertSame(
            [true, false, false, true, 'cloud', false, null, null, null, false],
            self::shapeOf($this->aiSettingsAs(self::ACME_FOODS, self::ACME_FOODS)),
        );
    }

    #[Test]
    public function missingKeysAndJsonNullsReadAsTheirDefaults(): void
    {
        self::assertSame(
            [true, false, false, false, 'cloud', false, null, null, null, false],
            self::shapeOf($this->settingsWithAi(self::ACME_FOODS, '{"on": true}')),
        );
        self::assertSame(
            [true, false, false, false, 'cloud', false, null, null, null, false],
            self::shapeOf($this->settingsWithAi(self::ACME_FOODS, '{"on": true, "who": null, "send": null, "sync": null, "where": null, "restricted_to_cloud": null, "provider": null, "provider_model": null, "secret_enc": null, "key_id": null}')),
        );
        self::assertSame(self::AI_OFF, self::shapeOf($this->settingsWithAi(self::ACME_FOODS, '{}')));
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function storedSettingsAreReadBackWithTheProviderKeyDecryptedAndTheRowNeverHoldsItInPlainText(): void
    {
        $secretEnc = base64_encode($this->fieldEncryption()->encrypt(self::PROVIDER_KEY));
        $this->storeAi(self::ACME_FOODS, [
            'on' => true, 'who' => 'leads_only', 'send' => 'title_only', 'sync' => true, 'where' => 'laptop_first',
            'restricted_to_cloud' => true, 'provider' => 'anthropic', 'provider_model' => 'claude-haiku-4-5', 'model' => 'phi4-mini',
            'secret_enc' => $secretEnc, 'key_id' => 'change-me-test',
        ]);

        $everyColumn = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne(
            'SELECT row_to_json(s)::text FROM org_settings s WHERE organization_id = ?',
            [self::ACME_FOODS],
        ));
        self::assertIsString($everyColumn);
        self::assertStringContainsString($secretEnc, $everyColumn);
        self::assertStringNotContainsString(self::PROVIDER_KEY, $everyColumn);
        self::assertStringNotContainsString(base64_encode(self::PROVIDER_KEY), $everyColumn);

        self::assertSame(
            [true, true, true, true, 'laptop_first', true, 'anthropic', 'claude-haiku-4-5', self::PROVIDER_KEY, false],
            self::shapeOf($this->aiSettingsAs(self::ACME_FOODS, self::ACME_FOODS)),
        );
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function eachPlaceWhereTheAiRunsIsReadBack(): void
    {
        $whereRead = [];
        foreach (['cloud', 'laptop', 'laptop_first'] as $whereAiRuns) {
            $whereRead[] = $this->settingsWithAi(self::ACME_FOODS, \sprintf('{"on": true, "where": "%s"}', $whereAiRuns))->whereAiRuns;
        }

        self::assertSame(['cloud', 'laptop', 'laptop_first'], $whereRead);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownAiSettings(): iterable
    {
        yield 'where the moon' => ['{"on": true, "where": "moon"}', 'where'];
        yield 'where in another case' => ['{"on": true, "where": "Cloud"}', 'where'];
        yield 'where as a number' => ['{"on": true, "where": 1}', 'where'];
        yield 'who admins' => ['{"on": true, "who": "admins"}', 'who'];
        yield 'send everything' => ['{"on": true, "send": "everything"}', 'send'];
        yield 'on as a word' => ['{"on": "yes"}', 'on'];
        yield 'on as the string true' => ['{"on": "true"}', 'on'];
        yield 'on as a number' => ['{"on": 1}', 'on'];
        yield 'sync as a word' => ['{"on": true, "sync": "on"}', 'sync'];
        yield 'restricted_to_cloud as the string true' => ['{"on": true, "restricted_to_cloud": "true"}', 'restricted_to_cloud'];
        yield 'provider as a number' => ['{"on": true, "provider": 42}', 'provider'];
        yield 'provider_model as a list' => ['{"on": true, "provider_model": ["a"]}', 'provider_model'];
        yield 'ai as a switch' => ['true', 'ai'];
        yield 'ai as a word' => ['"on"', 'ai'];
        yield 'ai as a list' => ['[{"on": true}]', 'ai'];
    }

    #[Test]
    #[DataProvider('unknownAiSettings')]
    public function anUnknownStoredAiSettingTurnsAiOffAndNamesOnlyTheKey(string $storedJson, string $expectedKey): void
    {
        self::assertSame(self::AI_OFF, self::shapeOf($this->settingsWithAi(self::ACME_FOODS, $storedJson)));
        self::assertSame(
            [['org_settings holds an unknown AI setting; AI help is off.', ['organization_id' => self::ACME_FOODS, 'key' => $expectedKey]]],
            $this->warnings,
        );
    }

    #[Test]
    public function anAiValueNestedTooDeepTurnsAiOff(): void
    {
        $tooDeep = str_repeat('[', 600).str_repeat(']', 600);

        self::assertSame(self::AI_OFF, self::shapeOf($this->settingsWithAi(self::ACME_FOODS, \sprintf('{"on": true, "provider": %s}', $tooDeep))));
        self::assertSame('ai', $this->warnings[0][1]['key'] ?? null);
    }

    /**
     * @return iterable<string, array{\Closure(FieldEncryption): array<string, mixed>}>
     */
    public static function unreadableProviderKeys(): iterable
    {
        yield 'altered ciphertext' => [static function (FieldEncryption $encryption): array {
            $encrypted = $encryption->encrypt(self::PROVIDER_KEY);
            $encrypted[-1] = \chr(\ord($encrypted[-1]) ^ 0x01);

            return ['secret_enc' => base64_encode($encrypted), 'key_id' => 'change-me-test'];
        }];
        yield 'a key id no longer in the ring' => [static fn (): array => [
            'secret_enc' => base64_encode(self::retiredKeyEncryption()->encrypt(self::PROVIDER_KEY)),
            'key_id' => 'retired',
        ]];
        yield 'key_id names another key than the ciphertext' => [static fn (FieldEncryption $encryption): array => [
            'secret_enc' => base64_encode($encryption->encrypt(self::PROVIDER_KEY)),
            'key_id' => 'retired',
        ]];
        yield 'no key_id' => [static fn (FieldEncryption $encryption): array => [
            'secret_enc' => base64_encode($encryption->encrypt(self::PROVIDER_KEY)),
        ]];
        yield 'key_id without secret_enc' => [static fn (): array => ['key_id' => 'change-me-test']];
        yield 'secret_enc not base64' => [static fn (): array => ['secret_enc' => 'not base64!', 'key_id' => 'change-me-test']];
        yield 'secret_enc empty' => [static fn (): array => ['secret_enc' => '', 'key_id' => 'change-me-test']];
        yield 'secret_enc a number' => [static fn (): array => ['secret_enc' => 42, 'key_id' => 'change-me-test']];
        yield 'secret_enc too short' => [static fn (): array => ['secret_enc' => base64_encode("\x0echange-me-test"), 'key_id' => 'change-me-test']];
        yield 'an empty key encrypted' => [static fn (FieldEncryption $encryption): array => [
            'secret_enc' => base64_encode($encryption->encrypt('')),
            'key_id' => 'change-me-test',
        ]];
    }

    /**
     * @param \Closure(FieldEncryption): array<string, mixed> $storedSecret
     */
    #[Test]
    #[DataProvider('unreadableProviderKeys')]
    public function anUnreadableProviderKeyGivesNoKeyAndSaysSoWhileTheOtherSettingsStay(\Closure $storedSecret): void
    {
        $this->storeAi(self::ACME_FOODS, ['on' => true, 'where' => 'laptop', 'provider' => 'huggingface', ...$storedSecret($this->fieldEncryption())]);

        self::assertSame(
            [true, false, false, false, 'laptop', false, 'huggingface', null, null, true],
            self::shapeOf($this->aiSettingsAs(self::ACME_FOODS, self::ACME_FOODS)),
        );
        self::assertCount(1, $this->warnings);
        self::assertSame('org_settings holds an AI provider key that cannot be read; AI calls get no key.', $this->warnings[0][0]);
        self::assertSame(self::ACME_FOODS, $this->warnings[0][1]['organization_id'] ?? null);
        $logged = json_encode($this->warnings, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PROVIDER_KEY, $logged);
        self::assertStringNotContainsString('secret_enc":"', $logged);
    }

    #[Test]
    public function anotherOrganizationsAiSettingsAreNeverRead(): void
    {
        $this->storeAi(self::ACME_FOODS, [
            'on' => true, 'where' => 'laptop', 'provider' => 'anthropic',
            'secret_enc' => base64_encode($this->fieldEncryption()->encrypt(self::PROVIDER_KEY)), 'key_id' => 'change-me-test',
        ]);
        $this->storeAi(self::BETA_BAKERY, ['on' => true, 'where' => 'laptop_first']);

        // Each tenant reads its own settings, so the off below comes from row-level security hiding the other row.
        self::assertSame(
            [true, false, false, false, 'laptop', false, 'anthropic', null, self::PROVIDER_KEY, false],
            self::shapeOf($this->aiSettingsAs(self::ACME_FOODS, self::ACME_FOODS)),
        );
        self::assertSame('laptop_first', $this->aiSettingsAs(self::BETA_BAKERY, self::BETA_BAKERY)->whereAiRuns);
        self::assertSame(self::AI_OFF, self::shapeOf($this->aiSettingsAs(self::BETA_BAKERY, self::ACME_FOODS)));
    }

    /**
     * @return array{bool, bool, bool, bool, string, bool, ?string, ?string, ?string, bool}
     */
    private static function shapeOf(AiSettings $settings): array
    {
        return [
            $settings->isOn, $settings->isForLeadsOnly, $settings->isTitleOnly, $settings->isSummaryOnSyncOn, $settings->whereAiRuns,
            $settings->isRestrictedToCloudAllowed, $settings->provider, $settings->providerModel, $settings->providerKey(), $settings->isProviderKeyUnreadable,
        ];
    }

    private static function retiredKeyEncryption(): FieldEncryption
    {
        return new SodiumFieldEncryption('retired:'.base64_encode(str_repeat("\x07", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), 'retired');
    }

    private function aiSettingsAs(string $tenantId, string $askedId): AiSettings
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): AiSettings => $this->queries()->aiSettingsOf(new OrganizationId($askedId)));
    }

    /**
     * Reads the AI settings after replacing data.ai with $aiJson, all rolled back.
     */
    private function settingsWithAi(string $organizationId, string $aiJson): AiSettings
    {
        return $this->transactions()->rolledBackAs($organizationId, function () use ($organizationId, $aiJson): AiSettings {
            $this->connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb)", [$aiJson]);

            return $this->queries()->aiSettingsOf(new OrganizationId($organizationId));
        });
    }

    /**
     * Reads the AI settings after replacing the whole settings data with $newData, all rolled back.
     */
    private function settingsWithData(string $organizationId, string $newData): AiSettings
    {
        return $this->transactions()->rolledBackAs($organizationId, function () use ($organizationId, $newData): AiSettings {
            $this->connection()->executeStatement('UPDATE org_settings SET data = '.$newData);

            return $this->queries()->aiSettingsOf(new OrganizationId($organizationId));
        });
    }

    /**
     * @param array<string, mixed> $ai
     */
    private function storeAi(string $organizationId, array $ai): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->connection()->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb)",
            [json_encode($ai, \JSON_THROW_ON_ERROR)],
        ));
    }

    private function queries(): DbalAiSettingsQueries
    {
        $logger = new class($this->recordWarning(...)) extends AbstractLogger {
            /**
             * @param \Closure(string, array<array-key, mixed>): void $record
             */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $message, $context);
            }
        };

        return new DbalAiSettingsQueries($this->connection(), $this->fieldEncryption(), $logger);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function recordWarning(string $message, array $context): void
    {
        $this->warnings[] = [$message, $context];
    }

    private function fieldEncryption(): FieldEncryption
    {
        return self::getContainer()->get(FieldEncryption::class);
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
