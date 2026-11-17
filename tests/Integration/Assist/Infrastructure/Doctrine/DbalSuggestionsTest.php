<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Infrastructure\Doctrine;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeSuggestion;
use App\Assist\Application\Suggestions;
use App\Assist\Infrastructure\Doctrine\DbalSuggestions;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Suggestions on the real ast_suggestion table (Version20261118100000, ADR-0005) as pinround_app, with one row of
 * Acme Foods and one of Beta Bakery seeded by the migrator, which owns the table and removes the rows around each test.
 */
final class DbalSuggestionsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c502';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c511';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c512';
    private const string ACME_CHECK = '0197a3c0-0000-7000-8000-00000000c521';
    private const string BETA_CHECK = '0197a3c0-0000-7000-8000-00000000c522';
    private const string NEW_CHECK = '0197a3c0-0000-7000-8000-00000000c523';
    private const string CREATED_AT = '2027-03-15 10:00:00.250+00';
    private const string INSERT = 'INSERT INTO ast_suggestion (organization_id, id, user_id, purpose, result, model, prompt_version, created_at)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    protected function setUp(): void
    {
        self::removeSuggestions();
        $this->seed(self::ACME_FOODS, self::ACME_CHECK);
        $this->seed(self::BETA_BAKERY, self::BETA_CHECK);
    }

    protected function tearDown(): void
    {
        self::tenantContext()->reset();
        self::removeSuggestions();
        parent::tearDown();
    }

    #[Test]
    public function eachOrganizationSeesOnlyItsOwnSuggestionAndNoneWithoutATenant(): void
    {
        $acmeIds = $this->idsAs(self::ACME_FOODS);
        $betaIds = $this->idsAs(self::BETA_BAKERY);

        self::assertNotSame([], $acmeIds);
        self::assertNotSame([], $betaIds);
        self::assertSame([self::ACME_CHECK], $acmeIds);
        self::assertSame([self::BETA_CHECK], $betaIds);
        self::assertSame([], self::connection()->fetchFirstColumn('SELECT id FROM ast_suggestion'), 'Rows were seen outside a transaction.');
        self::assertSame([], self::connection()->transactional(static fn (Connection $connection): array => $connection->fetchFirstColumn('SELECT id FROM ast_suggestion')), 'Rows were seen in a transaction without a tenant.');
    }

    #[Test]
    public function anotherOrganizationCannotAddARowInTheNameOfTheOrganization(): void
    {
        try {
            self::transactions()->rolledBackAs(self::BETA_BAKERY, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_CHECK));
            self::fail('Row-level security did not refuse a row of Acme Foods under Beta Bakery.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "ast_suggestion"', $refusal->getMessage());
        }

        self::assertSame([self::ACME_CHECK], $this->idsAs(self::ACME_FOODS));
        self::assertSame([self::BETA_CHECK], $this->idsAs(self::BETA_BAKERY));
    }

    #[Test]
    public function theRequesterReadsBackTheSuggestionTheyAddedAndNobodyElseDoes(): void
    {
        self::transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addNewIntake(self::ACME_FOODS);
        });

        self::assertEquals(self::suggestion(), self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?IntakeSuggestion => $this->intakeOf(self::ACME_FOODS, self::NEW_CHECK, self::OMAR)));
        self::assertNull(self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?IntakeSuggestion => $this->intakeOf(self::ACME_FOODS, self::NEW_CHECK, self::MIA)));
        self::assertNull(self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?IntakeSuggestion => $this->intakeOf(self::ACME_FOODS, self::BETA_CHECK, self::OMAR)));
        self::assertSame([[self::NEW_CHECK, self::OMAR, 'intake', '2027-03-15 10:00:00.250']], $this->rowsAs(self::ACME_FOODS, self::NEW_CHECK));
    }

    #[Test]
    public function addingWithNoTransactionOpenIsRefusedAndNothingIsWritten(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->addNewIntake(self::ACME_FOODS);
            self::fail('A suggestion was added with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('Suggestions are kept in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame([self::ACME_CHECK], $this->idsAs(self::ACME_FOODS));
    }

    #[Test]
    public function readingWithNoTransactionOpenIsRefused(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->intakeOf(self::ACME_FOODS, self::ACME_CHECK, self::OMAR);
            self::fail('A suggestion was read with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('Suggestions are kept in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }
    }

    #[Test]
    public function addingForAnotherOrganizationThanTheTenantIsRefusedAndNothingIsWritten(): void
    {
        try {
            self::transactions()->committedAs(self::BETA_BAKERY, function (): void {
                $this->addNewIntake(self::ACME_FOODS);
            });
            self::fail('A suggestion of Acme Foods was added under Beta Bakery.');
        } catch (\LogicException $refusal) {
            self::assertSame('Suggestions are kept under their own organization as tenant.', $refusal->getMessage());
        }

        self::assertSame([self::ACME_CHECK], $this->idsAs(self::ACME_FOODS));
        self::assertSame([self::BETA_CHECK], $this->idsAs(self::BETA_BAKERY));
    }

    #[Test]
    public function readingForAnotherOrganizationThanTheTenantIsRefused(): void
    {
        try {
            self::transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?IntakeSuggestion => $this->intakeOf(self::ACME_FOODS, self::ACME_CHECK, self::OMAR));
            self::fail('A suggestion of Acme Foods was read under Beta Bakery.');
        } catch (\LogicException $refusal) {
            self::assertSame('Suggestions are kept under their own organization as tenant.', $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}> the columns that differ from a valid row, and
     *                                                                the constraint that refuses them
     */
    public static function rowsTheTableRefuses(): iterable
    {
        yield 'an unknown purpose' => [['purpose' => 'pins2'], 'ast_suggestion_purpose_check'];
        yield 'a result that is a JSON array' => [['result' => '["summary"]'], 'ast_suggestion_result_check'];
        yield 'a blank model' => [['model' => ''], 'ast_suggestion_model_check'];
        yield 'a model one character over 200' => [['model' => str_repeat('é', 201)], 'ast_suggestion_model_check'];
        yield 'a blank prompt version' => [['prompt_version' => ''], 'ast_suggestion_prompt_version_check'];
        yield 'a prompt version one character over 200' => [['prompt_version' => str_repeat('é', 201)], 'ast_suggestion_prompt_version_check'];
    }

    /**
     * @param array<string, string> $columns
     */
    #[Test]
    #[DataProvider('rowsTheTableRefuses')]
    public function theTableRefusesARowOutOfRangeAndKeepsNothing(array $columns, string $violatedConstraint): void
    {
        try {
            self::transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_CHECK, $columns));
            self::fail('Not refused: '.$violatedConstraint);
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString($violatedConstraint, $refusal->getMessage());
        }

        self::assertSame([self::ACME_CHECK], $this->idsAs(self::ACME_FOODS));
    }

    #[Test]
    public function aRowAtEveryLimitIsKept(): void
    {
        self::transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_CHECK, [
            'purpose' => 'pins',
            'result' => '{}',
            'model' => str_repeat('é', 200),
            'prompt_version' => str_repeat('é', 200),
        ]));

        self::assertSame(
            [['pins', '{}', str_repeat('é', 200), str_repeat('é', 200)]],
            self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::connection()->fetchAllNumeric(
                'SELECT purpose, result::text, model, prompt_version FROM ast_suggestion WHERE id = ?',
                [self::NEW_CHECK],
            )),
        );
    }

    #[Test]
    public function theShortestModelAndPromptVersionAreKept(): void
    {
        self::transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_CHECK, ['model' => 'm', 'prompt_version' => 'v']));

        self::assertSame([self::ACME_CHECK, self::NEW_CHECK], $this->idsAs(self::ACME_FOODS));
    }

    private static function suggestion(): IntakeSuggestion
    {
        return new IntakeSuggestion('Pay does nothing on the last step.', 'bug', 'high', ['SHOP-12'], false, true, 'claude-haiku-4-5', 'intake-v1');
    }

    private function addNewIntake(string $organizationId): void
    {
        $this->suggestions()->addIntake(new IntakeCheckId(self::NEW_CHECK), new OrganizationId($organizationId), new UserId(self::OMAR), self::suggestion(), new \DateTimeImmutable(self::CREATED_AT));
    }

    private function intakeOf(string $organizationId, string $checkId, string $requesterId): ?IntakeSuggestion
    {
        return $this->suggestions()->intakeOf(new OrganizationId($organizationId), new IntakeCheckId($checkId), new UserId($requesterId));
    }

    /**
     * @param array<string, string> $columns values that replace those of a valid row
     */
    private function insertRow(string $organizationId, string $checkId, array $columns = []): int|string
    {
        $row = [...[
            'purpose' => 'intake',
            'result' => '{"summary": "Pay does nothing."}',
            'model' => 'claude-haiku-4-5',
            'prompt_version' => 'intake-v1',
        ], ...$columns];

        return self::connection()->executeStatement(self::INSERT, [$organizationId, $checkId, self::OMAR, $row['purpose'], $row['result'], $row['model'], $row['prompt_version'], self::CREATED_AT]);
    }

    /**
     * @return list<mixed> the ids pinround_app sees under $organizationId
     */
    private function idsAs(string $organizationId): array
    {
        return self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchFirstColumn('SELECT id FROM ast_suggestion ORDER BY id'));
    }

    /**
     * @return list<list<mixed>>
     */
    private function rowsAs(string $organizationId, string $checkId): array
    {
        return self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchAllNumeric(
            "SELECT id, user_id, purpose, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') FROM ast_suggestion WHERE id = ?",
            [$checkId],
        ));
    }

    private function seed(string $organizationId, string $checkId): void
    {
        self::tenantContext()->set(new OrganizationId($organizationId));
        try {
            self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                self::INSERT,
                [$organizationId, $checkId, self::OMAR, 'intake', '{"summary": "Pay does nothing."}', 'claude-haiku-4-5', 'intake-v1', self::CREATED_AT],
            ));
        } finally {
            self::tenantContext()->clear();
        }
    }

    private function suggestions(): Suggestions
    {
        return new DbalSuggestions(self::connection(), self::tenantContext());
    }

    /** ast_suggestion is read-and-add for the app; the migrator owns it, and row-level security applies to it too. */
    private static function removeSuggestions(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::tenantContext()->set(new OrganizationId($organizationId));
            try {
                self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ast_suggestion WHERE organization_id = ?', [$organizationId]));
            } finally {
                self::tenantContext()->clear();
            }
        }
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
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
