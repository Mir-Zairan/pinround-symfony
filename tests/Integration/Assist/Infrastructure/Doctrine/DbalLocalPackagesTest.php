<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Infrastructure\Doctrine;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Application\LocalAi\IssuedLocalPackage;
use App\Assist\Application\LocalAi\LocalPackageLimit;
use App\Assist\Application\LocalAi\LocalPackageOwner;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\PromptVersion;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Doctrine\DbalLocalPackages;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * LocalPackages on the real ast_local_package table and resolve_laptop_package (Version20261118120000, ADR-0005) as
 * pinround_app, with an open package of Omar in Acme Foods and one of Mia in Beta Bakery seeded by the migrator, which
 * owns the table and removes the rows around each test. The clock stands two minutes into the packages' five.
 */
final class DbalLocalPackagesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000d502';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000d511';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000d512';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000d531';
    private const string ACME_PACKAGE = '0197a3c0-0000-7000-8000-00000000d521';
    private const string BETA_PACKAGE = '0197a3c0-0000-7000-8000-00000000d522';
    private const string NEW_PACKAGE = '0197a3c0-0000-7000-8000-00000000d523';
    private const string UNKNOWN_PACKAGE = '0197a3c0-0000-7000-8000-00000000d529';
    private const string CREATED_AT = '2027-03-15 10:00:00.250+00';
    private const string EXPIRES_AT = '2027-03-15 10:05:00.250+00';
    private const string NOW = '2027-03-15 10:02:00.500+00';
    private const string LATER = '2027-03-15 10:03:30.750+00';
    private const string RESULT = 'Pay does nothing on the last step.';
    private const string MODEL = 'qwen3:4b';
    private const string INSERT = 'INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at, used_at, result_text, model)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    /** The key DbalLocalPackages::issue() locks on: organization, then the person. */
    private const string LOCK_KEY_FORMAT = 'ast_local_package:open:%s:%s';
    private const string LOCK_TIMEOUT = '200ms';

    protected function setUp(): void
    {
        self::removePackages();
        self::seed(self::ACME_FOODS, self::ACME_PACKAGE, self::OMAR);
        self::seed(self::BETA_BAKERY, self::BETA_PACKAGE, self::MIA);
    }

    protected function tearDown(): void
    {
        self::tenantContext()->reset();
        self::removePackages();
        parent::tearDown();
    }

    #[Test]
    public function eachOrganizationSeesOnlyItsOwnPackageAndNoneWithoutATenant(): void
    {
        $acmeIds = $this->idsAs(self::ACME_FOODS);
        $betaIds = $this->idsAs(self::BETA_BAKERY);

        self::assertNotSame([], $acmeIds);
        self::assertNotSame([], $betaIds);
        self::assertSame([self::ACME_PACKAGE], $acmeIds);
        self::assertSame([self::BETA_PACKAGE], $betaIds);
        self::assertSame([], self::connection()->fetchFirstColumn('SELECT id FROM ast_local_package'), 'Rows were seen outside a transaction.');
        self::assertSame([], self::connection()->transactional(static fn (Connection $connection): array => $connection->fetchFirstColumn('SELECT id FROM ast_local_package')), 'Rows were seen in a transaction without a tenant.');
    }

    #[Test]
    public function anotherOrganizationCannotAddAPackageInTheNameOfTheOrganization(): void
    {
        try {
            self::transactions()->rolledBackAs(self::BETA_BAKERY, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_PACKAGE));
            self::fail('Row-level security did not refuse a package of Acme Foods under Beta Bakery.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "ast_local_package"', $refusal->getMessage());
        }

        self::assertSame([self::ACME_PACKAGE], $this->idsAs(self::ACME_FOODS));
        self::assertSame([self::BETA_PACKAGE], $this->idsAs(self::BETA_BAKERY));
    }

    #[Test]
    public function theResolverGivesTheOrganizationAndUserOfAPackageOfAnyOrganization(): void
    {
        self::assertEquals(self::owner(self::ACME_FOODS, self::OMAR), $this->packages()->ownerOf(new LocalPackageId(self::ACME_PACKAGE)));
        self::assertEquals(self::owner(self::BETA_BAKERY, self::MIA), $this->packages()->ownerOf(new LocalPackageId(self::BETA_PACKAGE)));
        self::assertEquals(
            self::owner(self::ACME_FOODS, self::OMAR),
            self::transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?LocalPackageOwner => $this->packages()->ownerOf(new LocalPackageId(self::ACME_PACKAGE))),
            'The resolver kept to the tenant, so the intake could not tell another organization\'s package from an unknown one.',
        );
        self::assertEquals(self::owner(self::ACME_FOODS, self::OMAR), $this->packages()->ownerOf(new LocalPackageId(strtoupper(self::ACME_PACKAGE))));
    }

    #[Test]
    public function theResolverGivesNothingForAnUnknownPackage(): void
    {
        self::assertNull($this->packages()->ownerOf(new LocalPackageId(self::UNKNOWN_PACKAGE)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPackageIds(): iterable
    {
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'a UUIDv4' => ['0197a3c0-0000-4000-8000-00000000d521'];
        yield 'a trailing line feed' => [self::ACME_PACKAGE."\n"];
        yield 'empty' => [''];
    }

    /** The resolver's ?::uuid would fail on these; the id refuses them first, and a page answers that as not found. */
    #[Test]
    #[DataProvider('malformedPackageIds')]
    public function aMalformedPackageIdIsRefusedBeforeTheResolverIsAsked(string $value): void
    {
        try {
            $this->packages()->ownerOf(new LocalPackageId($value));
            self::fail('A malformed package id reached the resolver.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A laptop package id must be a canonical UUIDv7.', $refusal->getMessage());
        }
    }

    #[Test]
    public function issuingStoresAnOpenPackageThatExpiresAfterItsLifetime(): void
    {
        $expiresAt = self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 10));

        self::assertInstanceOf(\DateTimeImmutable::class, $expiresAt);
        self::assertSame('2027-03-15 10:07:00.500', $expiresAt->format('Y-m-d H:i:s.v'));
        self::assertSame(
            [[self::NEW_PACKAGE, self::OMAR, self::TICKET, 'summary', 'summary.v1', '2027-03-15 10:02:00.500', '2027-03-15 10:07:00.500', null, null, null]],
            $this->rowsAs(self::ACME_FOODS, self::NEW_PACKAGE),
        );
    }

    #[Test]
    public function issuingPastTheCapOfOpenPackagesStoresNothing(): void
    {
        self::assertSame(
            LocalPackageLimit::OpenPackages,
            self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 1)),
        );

        self::assertSame([self::ACME_PACKAGE], $this->idsAs(self::ACME_FOODS));
    }

    #[Test]
    public function usedExpiredAndOtherPeoplesPackagesDoNotCountTowardsTheCap(): void
    {
        self::seed(self::ACME_FOODS, '0197a3c0-0000-7000-8000-00000000d541', self::OMAR, ['used_at' => self::NOW, 'result_text' => self::RESULT, 'model' => self::MODEL]);
        self::seed(self::ACME_FOODS, '0197a3c0-0000-7000-8000-00000000d542', self::OMAR, ['expires_at' => self::NOW]);
        self::seed(self::ACME_FOODS, '0197a3c0-0000-7000-8000-00000000d543', self::MIA);
        self::seed(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-00000000d544', self::OMAR);

        self::assertInstanceOf(\DateTimeImmutable::class, self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 2)));
        self::assertContains(self::NEW_PACKAGE, $this->idsAs(self::ACME_FOODS));
    }

    /**
     * Omar's open package and a used one were created this hour, and only the open one counts towards the open cap of
     * 2, so the refusal is the hour's; Mia's and Beta Bakery's packages of the same hour do not count.
     */
    #[Test]
    public function issuingPastTheCapOfPackagesThisHourStoresNothingWhetherTheyAreUsedOrNot(): void
    {
        self::seed(self::ACME_FOODS, '0197a3c0-0000-7000-8000-00000000d545', self::OMAR, ['used_at' => self::NOW, 'result_text' => self::RESULT, 'model' => self::MODEL]);
        self::seed(self::ACME_FOODS, '0197a3c0-0000-7000-8000-00000000d546', self::MIA);
        self::seed(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-00000000d547', self::OMAR);
        $idsBefore = $this->idsAs(self::ACME_FOODS);

        self::assertSame(
            LocalPackageLimit::PackagesPerHour,
            self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 2, 2)),
        );
        self::assertSame($idsBefore, $this->idsAs(self::ACME_FOODS));
        self::assertInstanceOf(
            \DateTimeImmutable::class,
            self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 2, 3)),
            'Three a hour let a third through, so the refusal above counted two.',
        );
    }

    /**
     * Omar holds 10 open packages (the one setUp seeds and 9 more) and 20 used ones, all created this hour, so both caps
     * are reached at once; the open cap is the one he can wait out in minutes, so it is the one he is told.
     */
    #[Test]
    public function aPersonAtBothCapsIsRefusedForTheOpenPackagesAndNothingIsStored(): void
    {
        for ($i = 1; $i <= 29; ++$i) {
            $columns = $i <= 9 ? [] : ['used_at' => self::NOW, 'result_text' => self::RESULT, 'model' => self::MODEL];
            self::seed(self::ACME_FOODS, \sprintf('0197a3c0-0000-7000-8000-00000000e%03d', $i), self::OMAR, $columns);
        }
        $rowsBefore = $this->rowsAs(self::ACME_FOODS);
        self::assertCount(30, $rowsBefore);
        self::assertCount(10, array_filter($rowsBefore, static fn (array $row): bool => null === $row[7]));

        self::assertSame(
            LocalPackageLimit::OpenPackages,
            self::transactions()->committedAs(
                self::ACME_FOODS,
                fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, LocalAi::MAX_OPEN_PACKAGES_PER_PERSON, LocalAi::MAX_PACKAGES_PER_PERSON_PER_HOUR),
            ),
        );
        self::assertSame($rowsBefore, $this->rowsAs(self::ACME_FOODS));
    }

    /**
     * Two requests of one person must not both count before either inserts, so issue() waits on that person's lock;
     * here a second connection holds it, and lock_timeout ends the wait instead of the test hanging.
     */
    #[Test]
    public function issuingWaitsWhileAnotherRequestOfThePersonHoldsTheirLockAndStoresNothing(): void
    {
        $idsBefore = $this->idsAs(self::ACME_FOODS);
        self::assertNotSame([], $idsBefore);

        $this->holdingLockOf(self::ACME_FOODS, self::OMAR, function (): void {
            try {
                self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackageWithLockTimeout());
                self::fail('issue() did not wait for the lock another request of Omar holds.');
            } catch (DriverException $timeout) {
                self::assertSame('55P03', $timeout->getSQLState());
                self::assertStringContainsString('canceling statement due to lock timeout', $timeout->getMessage());
            }
        });

        self::assertSame($idsBefore, $this->idsAs(self::ACME_FOODS));
    }

    /** The lock is per person and organization: Mia's, or Omar's in Beta Bakery, does not hold up Omar in Acme Foods. */
    #[Test]
    public function anotherPersonsOrOrganizationsLockDoesNotHoldUpTheIssue(): void
    {
        $this->holdingLockOf(self::ACME_FOODS, self::MIA, function (): void {
            $this->holdingLockOf(self::BETA_BAKERY, self::OMAR, function (): void {
                self::assertInstanceOf(
                    \DateTimeImmutable::class,
                    self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackageWithLockTimeout()),
                );
            });
        });

        self::assertSame([self::ACME_PACKAGE, self::NEW_PACKAGE], $this->idsAs(self::ACME_FOODS));
    }

    /** Both packages were created at 10:00:00.250; an hour later to the millisecond they no longer count. */
    #[Test]
    public function packagesCreatedAnHourAgoNoLongerCount(): void
    {
        self::seed(self::ACME_FOODS, '0197a3c0-0000-7000-8000-00000000d545', self::OMAR, ['used_at' => self::NOW, 'result_text' => self::RESULT, 'model' => self::MODEL]);

        self::assertSame(
            LocalPackageLimit::PackagesPerHour,
            self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 10, 2, '2027-03-15 11:00:00.249+00')),
        );
        self::assertInstanceOf(
            \DateTimeImmutable::class,
            self::transactions()->committedAs(self::ACME_FOODS, fn (): \DateTimeImmutable|LocalPackageLimit => $this->issueNewPackage(self::ACME_FOODS, 10, 2, '2027-03-15 11:00:00.250+00')),
        );
    }

    #[Test]
    public function theHolderReadsTheirOpenPackageAndNobodyElseDoes(): void
    {
        self::assertEquals(
            new IssuedLocalPackage(LocalAiPurpose::Summary, new TicketId(self::TICKET), new PromptVersion(AiFeature::Summary, 1), true),
            $this->issuedAs(self::ACME_FOODS, self::ACME_PACKAGE, self::OMAR),
        );
        self::assertNull($this->issuedAs(self::ACME_FOODS, self::ACME_PACKAGE, self::MIA));
        self::assertNull($this->issuedAs(self::ACME_FOODS, self::BETA_PACKAGE, self::MIA));
        self::assertNull($this->issuedAs(self::ACME_FOODS, self::UNKNOWN_PACKAGE, self::OMAR));
    }

    #[Test]
    public function postingKeepsTheResultAndMarksThePackageUsedAtTheClocksTime(): void
    {
        $usedAt = self::transactions()->committedAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->post(self::ACME_PACKAGE, self::OMAR, self::RESULT));

        self::assertNotNull($usedAt);
        self::assertSame('2027-03-15 10:02:00.500', $usedAt->format('Y-m-d H:i:s.v'));
        self::assertSame(
            [[self::ACME_PACKAGE, self::OMAR, self::TICKET, 'summary', 'summary.v1', '2027-03-15 10:00:00.250', '2027-03-15 10:05:00.250', '2027-03-15 10:02:00.500', self::RESULT, self::MODEL]],
            $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE),
        );
        self::assertFalse($this->issuedAs(self::ACME_FOODS, self::ACME_PACKAGE, self::OMAR)?->isOpen);
    }

    #[Test]
    public function aSecondPostChangesNothingAndKeepsTheFirstResult(): void
    {
        self::transactions()->committedAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->post(self::ACME_PACKAGE, self::OMAR, self::RESULT));
        $firstRow = $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE);

        self::assertNull(self::transactions()->committedAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->post(self::ACME_PACKAGE, self::OMAR, 'A second answer.', self::LATER)));
        self::assertSame($firstRow, $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function momentsAtOrPastTheExpiry(): iterable
    {
        yield 'at the expiry' => [self::EXPIRES_AT];
        yield 'one second past it' => ['2027-03-15 10:05:01.250+00'];
    }

    #[Test]
    #[DataProvider('momentsAtOrPastTheExpiry')]
    public function anExpiredPackageTakesNoResult(string $now): void
    {
        $unusedRow = $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE);

        self::assertNull(self::transactions()->committedAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->post(self::ACME_PACKAGE, self::OMAR, self::RESULT, $now)));
        self::assertSame($unusedRow, $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE));
        self::assertFalse(self::transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): ?bool => $this->packages($now)->issuedOf(new OrganizationId(self::ACME_FOODS), new LocalPackageId(self::ACME_PACKAGE), new UserId(self::OMAR))?->isOpen,
        ));
    }

    #[Test]
    public function anotherPersonsPostChangesNothing(): void
    {
        $unusedRow = $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE);

        self::assertNull(self::transactions()->committedAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->post(self::ACME_PACKAGE, self::MIA, self::RESULT)));
        self::assertSame($unusedRow, $this->rowsAs(self::ACME_FOODS, self::ACME_PACKAGE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tenantOperations(): iterable
    {
        yield 'issue' => ['issue'];
        yield 'issuedOf' => ['issuedOf'];
        yield 'post' => ['post'];
    }

    #[Test]
    #[DataProvider('tenantOperations')]
    public function anOperationWithNoTransactionOpenIsRefusedAndNothingIsWritten(string $operation): void
    {
        $rowsBefore = [$this->rowsAs(self::ACME_FOODS), $this->rowsAs(self::BETA_BAKERY)];
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->runOperation($operation, self::ACME_FOODS);
            self::fail(\sprintf('%s ran with no transaction open.', $operation));
        } catch (\LogicException $refusal) {
            self::assertSame('Laptop packages are kept in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame($rowsBefore, [$this->rowsAs(self::ACME_FOODS), $this->rowsAs(self::BETA_BAKERY)]);
    }

    #[Test]
    #[DataProvider('tenantOperations')]
    public function anOperationForAnotherOrganizationThanTheTenantIsRefusedAndNothingIsWritten(string $operation): void
    {
        $rowsBefore = [$this->rowsAs(self::ACME_FOODS), $this->rowsAs(self::BETA_BAKERY)];
        try {
            self::transactions()->committedAs(self::BETA_BAKERY, function () use ($operation): void {
                $this->runOperation($operation, self::ACME_FOODS);
            });
            self::fail(\sprintf('%s of Acme Foods ran under Beta Bakery.', $operation));
        } catch (\LogicException $refusal) {
            self::assertSame('Laptop packages are kept under their own organization as tenant.', $refusal->getMessage());
        }

        self::assertSame($rowsBefore, [$this->rowsAs(self::ACME_FOODS), $this->rowsAs(self::BETA_BAKERY)]);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}> the columns that differ from a valid row, and
     *                                                                     the constraint that refuses them
     */
    public static function rowsTheTableRefuses(): iterable
    {
        $used = ['used_at' => self::NOW, 'result_text' => self::RESULT, 'model' => self::MODEL];

        yield 'an unknown purpose' => [['purpose' => 'pins'], 'ast_local_package_purpose_check'];
        yield 'a blank prompt version' => [['prompt_version' => ''], 'ast_local_package_prompt_version_check'];
        yield 'a prompt version one character over 200' => [['prompt_version' => str_repeat('é', 201)], 'ast_local_package_prompt_version_check'];
        yield 'an expiry at the creation' => [['expires_at' => self::CREATED_AT], 'ast_local_package_expires_at_check'];
        yield 'a blank result' => [[...$used, 'result_text' => ''], 'ast_local_package_result_text_check'];
        yield 'a result one character over 10,000' => [[...$used, 'result_text' => str_repeat('é', 10001)], 'ast_local_package_result_text_check'];
        yield 'an uppercase model' => [[...$used, 'model' => 'Qwen3:4b'], 'ast_local_package_model_check'];
        yield 'a model with a trailing line feed' => [[...$used, 'model' => "qwen3:4b\n"], 'ast_local_package_model_check'];
        yield 'a model of 65 characters' => [[...$used, 'model' => str_repeat('a', 62).':4b'], 'ast_local_package_model_check'];
        yield 'a multibyte model' => [[...$used, 'model' => 'qwén3:4b'], 'ast_local_package_model_check'];
        yield 'used with no result' => [['used_at' => self::NOW], 'ast_local_package_result_check'];
        yield 'a result while unused' => [['result_text' => self::RESULT, 'model' => self::MODEL], 'ast_local_package_result_check'];
        yield 'used with a result but no model' => [[...$used, 'model' => null], 'ast_local_package_result_check'];
    }

    /**
     * @param array<string, string|null> $columns
     */
    #[Test]
    #[DataProvider('rowsTheTableRefuses')]
    public function theTableRefusesARowOutOfRangeAndKeepsNothing(array $columns, string $violatedConstraint): void
    {
        try {
            self::transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_PACKAGE, $columns));
            self::fail('Not refused: '.$violatedConstraint);
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString($violatedConstraint, $refusal->getMessage());
        }

        self::assertSame([self::ACME_PACKAGE], $this->idsAs(self::ACME_FOODS));
    }

    #[Test]
    public function aUsedRowAtEveryLimitIsKept(): void
    {
        $model = str_repeat('a', 61).':4b';
        self::transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->insertRow(self::ACME_FOODS, self::NEW_PACKAGE, [
            'prompt_version' => str_repeat('é', 200),
            'used_at' => self::NOW,
            'result_text' => str_repeat('é', 10000),
            'model' => $model,
        ]));

        self::assertSame(
            [[self::NEW_PACKAGE, self::OMAR, self::TICKET, 'summary', str_repeat('é', 200), '2027-03-15 10:00:00.250', '2027-03-15 10:05:00.250', '2027-03-15 10:02:00.500', str_repeat('é', 10000), $model]],
            $this->rowsAs(self::ACME_FOODS, self::NEW_PACKAGE),
        );
    }

    private static function owner(string $organizationId, string $userId): LocalPackageOwner
    {
        return new LocalPackageOwner(new OrganizationId($organizationId), new UserId($userId));
    }

    private function runOperation(string $operation, string $organizationId): void
    {
        match ($operation) {
            'issue' => $this->issueNewPackage($organizationId, 10),
            'issuedOf' => $this->packages()->issuedOf(new OrganizationId($organizationId), new LocalPackageId(self::ACME_PACKAGE), new UserId(self::OMAR)),
            'post' => $this->packages()->post(new OrganizationId($organizationId), new LocalPackageId(self::ACME_PACKAGE), new UserId(self::OMAR), self::RESULT, new LocalModelName(self::MODEL)),
            default => throw new \UnexpectedValueException('Unknown operation '.$operation),
        };
    }

    private function issueNewPackage(string $organizationId, int $maxOpenPackages, int $maxPackagesPerHour = 30, string $now = self::NOW): \DateTimeImmutable|LocalPackageLimit
    {
        return $this->packages($now)->issue(
            new LocalPackageId(self::NEW_PACKAGE),
            new OrganizationId($organizationId),
            new UserId(self::OMAR),
            new TicketId(self::TICKET),
            LocalAiPurpose::Summary,
            new PromptVersion(AiFeature::Summary, 1),
            300,
            $maxOpenPackages,
            $maxPackagesPerHour,
        );
    }

    /**
     * Issues Omar's new package in Acme Foods in the open transaction, giving up on a lock after LOCK_TIMEOUT.
     */
    private function issueNewPackageWithLockTimeout(): \DateTimeImmutable|LocalPackageLimit
    {
        self::connection()->executeStatement(\sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

        return $this->issueNewPackage(self::ACME_FOODS, 10);
    }

    /**
     * Runs $work while the migrator's own connection holds the advisory lock issue() takes for $userId in
     * $organizationId, as another request of that person would.
     *
     * @param \Closure(): void $work
     */
    private function holdingLockOf(string $organizationId, string $userId, \Closure $work): void
    {
        $lockKey = \sprintf(self::LOCK_KEY_FORMAT, $organizationId, $userId);
        self::assertTrue(self::migrator()->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$lockKey]), 'The lock was already held.');
        try {
            $work();
        } finally {
            self::migrator()->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
        }
    }

    private function issuedAs(string $organizationId, string $packageId, string $userId): ?IssuedLocalPackage
    {
        return self::transactions()->rolledBackAs(
            $organizationId,
            fn (): ?IssuedLocalPackage => $this->packages()->issuedOf(new OrganizationId($organizationId), new LocalPackageId($packageId), new UserId($userId)),
        );
    }

    private function post(string $packageId, string $posterId, string $text, string $now = self::NOW): ?\DateTimeImmutable
    {
        return $this->packages($now)->post(new OrganizationId(self::ACME_FOODS), new LocalPackageId($packageId), new UserId($posterId), $text, new LocalModelName(self::MODEL));
    }

    /**
     * @param array<string, string|null> $columns values that replace those of an open package of Omar
     */
    private function insertRow(string $organizationId, string $packageId, array $columns = []): int|string
    {
        return self::connection()->executeStatement(self::INSERT, self::rowValues($organizationId, $packageId, self::OMAR, $columns));
    }

    /**
     * @param array<string, string|null> $columns
     *
     * @return list<string|null>
     */
    private static function rowValues(string $organizationId, string $packageId, string $userId, array $columns): array
    {
        $row = [...[
            'purpose' => 'summary',
            'prompt_version' => 'summary.v1',
            'expires_at' => self::EXPIRES_AT,
            'used_at' => null,
            'result_text' => null,
            'model' => null,
        ], ...$columns];

        return [$organizationId, $packageId, $userId, self::TICKET, $row['purpose'], $row['prompt_version'], self::CREATED_AT, $row['expires_at'], $row['used_at'], $row['result_text'], $row['model']];
    }

    /**
     * @return list<mixed> the ids pinround_app sees under $organizationId
     */
    private function idsAs(string $organizationId): array
    {
        return self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchFirstColumn('SELECT id FROM ast_local_package ORDER BY id'));
    }

    /**
     * @return list<list<mixed>> the packages pinround_app sees under $organizationId, all of them when $packageId is null
     */
    private function rowsAs(string $organizationId, ?string $packageId = null): array
    {
        return self::transactions()->rolledBackAs($organizationId, static fn (): array => self::connection()->fetchAllNumeric(
            "SELECT id, user_id, ticket_id, purpose, prompt_version,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS'),
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS'),
                    to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS'),
                    result_text, model
             FROM ast_local_package WHERE ?::uuid IS NULL OR id = ?::uuid ORDER BY id",
            [$packageId, $packageId],
        ));
    }

    private function packages(string $now = self::NOW): LocalPackages
    {
        return new DbalLocalPackages(self::connection(), self::tenantContext(), new MockClock($now));
    }

    /**
     * @param array<string, string|null> $columns values that replace those of an open package
     */
    private static function seed(string $organizationId, string $packageId, string $userId, array $columns = []): void
    {
        self::tenantContext()->set(new OrganizationId($organizationId));
        try {
            self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                self::INSERT,
                self::rowValues($organizationId, $packageId, $userId, $columns),
            ));
        } finally {
            self::tenantContext()->clear();
        }
    }

    /** The app may not delete packages; the migrator owns the table, and row-level security applies to it too. */
    private static function removePackages(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::tenantContext()->set(new OrganizationId($organizationId));
            try {
                self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ast_local_package WHERE organization_id = ?', [$organizationId]));
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
