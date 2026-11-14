<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadPurpose;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Row-level security on med_upload_intent and med_object (Version20261115120000, ADR-0005) as pinround_app: each
 * organization sees and changes only its own rows, none without a tenant, and cannot add one in the name of another
 * organization. Acme Foods has two rows per table, saved in the reverse order of their ids.
 */
final class MediaTablesIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1201';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000e1202';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1203';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e1204';
    private const string ACME_FIRST = '0197a3c0-0000-7000-8000-0000000e1211';
    private const string ACME_SECOND = '0197a3c0-0000-7000-8000-0000000e1212';
    private const string OTHER_ROW = '0197a3c0-0000-7000-8000-0000000e1213';
    private const string SMUGGLED_ROW = '0197a3c0-0000-7000-8000-0000000e1214';
    private const array ENTITY_CLASSES = ['med_upload_intent' => UploadIntent::class, 'med_object' => MediaObject::class];

    protected function setUp(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            foreach ([self::ACME_SECOND, self::ACME_FIRST] as $id) {
                $this->entityManager()->persist(self::intent(self::ACME_FOODS, $id, self::MIA));
                $this->entityManager()->persist(self::media(self::ACME_FOODS, $id, self::MIA));
            }
            $this->entityManager()->flush();
        });
        $this->transactions()->committedAs(self::OTHER_ORGANIZATION, function (): void {
            $this->entityManager()->persist(self::intent(self::OTHER_ORGANIZATION, self::OTHER_ROW, self::OMAR));
            $this->entityManager()->persist(self::media(self::OTHER_ORGANIZATION, self::OTHER_ROW, self::OMAR));
            $this->entityManager()->flush();
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        yield 'med_upload_intent' => ['med_upload_intent'];
        yield 'med_object' => ['med_object'];
    }

    #[Test]
    public function theCasesCoverEveryMediaTableInTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            array_keys(self::ENTITY_CLASSES),
            $this->connection()->fetchFirstColumn("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND relname LIKE 'med\\_%'"),
        );
    }

    #[Test]
    #[DataProvider('tables')]
    public function eachOrganizationSeesOnlyItsOwnRowsAndNoneWithoutATenant(string $table): void
    {
        self::assertSame([self::ACME_FIRST, self::ACME_SECOND], $this->idsAs(self::ACME_FOODS, $table));
        self::assertSame([self::OTHER_ROW], $this->idsAs(self::OTHER_ORGANIZATION, $table));
        self::assertSame(0, $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)));
    }

    #[Test]
    #[DataProvider('tables')]
    public function rowLevelSecurityAloneHidesTheRowsFromAnotherOrganizationWithTheTenantFilterOff(string $table): void
    {
        $entityClass = self::ENTITY_CLASSES[$table];
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $found = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->entityManager()->getRepository($entityClass)->findBy(['organizationId' => self::ACME_FOODS]));
        $own = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->entityManager()->getRepository($entityClass)->findBy(['organizationId' => self::ACME_FOODS]));

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame([], $found);
        self::assertCount(2, $own, 'Acme Foods does not find its own rows either, so the check above proves nothing.');
    }

    #[Test]
    #[DataProvider('tables')]
    public function anotherOrganizationChangesNoRowOfTheOrganization(string $table): void
    {
        $changedAsOther = $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement(
            \sprintf("UPDATE %s SET storage_key = 'taken' WHERE organization_id = ?", $table),
            [self::ACME_FOODS],
        ));
        $changedAsAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            \sprintf("UPDATE %s SET storage_key = 'taken' WHERE organization_id = ?", $table),
            [self::ACME_FOODS],
        ));

        self::assertSame(0, $changedAsOther);
        self::assertSame(2, $changedAsAcme, 'The same statement changes Acme Foods\' rows under its own tenant, so 0 above is the policy.');
        $storageKeys = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(\sprintf('SELECT storage_key FROM %s ORDER BY id', $table)));
        self::assertNotSame([], $storageKeys);
        foreach ($storageKeys as $storageKey) {
            self::assertIsString($storageKey);
            self::assertStringStartsWith('acme/', $storageKey);
        }
    }

    #[Test]
    #[DataProvider('tables')]
    public function anotherOrganizationCannotAddARowInTheNameOfTheOrganization(string $table): void
    {
        try {
            $this->transactions()->committedAs(self::OTHER_ORGANIZATION, function () use ($table): void {
                $this->entityManager()->persist('med_upload_intent' === $table
                    ? self::intent(self::ACME_FOODS, self::SMUGGLED_ROW, self::OMAR)
                    : self::media(self::ACME_FOODS, self::SMUGGLED_ROW, self::OMAR));
                $this->entityManager()->flush();
            });
            self::fail(\sprintf('Row-level security did not refuse an insert into %s.', $table));
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $refusal->getMessage());
        }
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([self::ACME_FIRST, self::ACME_SECOND], $this->idsAs(self::ACME_FOODS, $table));
    }

    private static function intent(string $organizationId, string $id, string $userId): UploadIntent
    {
        return UploadIntent::open(new UploadIntentId($id), new OrganizationId($organizationId), new UserId($userId), UploadPurpose::Screenshot, 10_485_760, ['image/png'], self::storageKey($organizationId, $id), new \DateTimeImmutable('2027-03-15 10:15:00+00:00'));
    }

    private static function media(string $organizationId, string $id, string $userId): MediaObject
    {
        return MediaObject::upload(new MediaId($id), new OrganizationId($organizationId), self::storageKey($organizationId, $id), 'image/png', 524_288, new UserId($userId), new \DateTimeImmutable('2027-03-15 10:08:00+00:00'));
    }

    private static function storageKey(string $organizationId, string $id): string
    {
        return (self::ACME_FOODS === $organizationId ? 'acme/' : 'other/').$id;
    }

    /**
     * @return list<mixed>
     */
    private function idsAs(string $organizationId, string $table): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function mediaRows(): MediaRows
    {
        return new MediaRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
