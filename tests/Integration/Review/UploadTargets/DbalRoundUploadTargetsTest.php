<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\UploadTargets;

use App\Review\Application\RoundUploadTarget;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\Review\Infrastructure\Doctrine\DbalRoundUploadTargets;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRoundUploadTargets on the real rvw_round as pinround_app, under row-level security, on rounds DbalRounds commits.
 * Every time is a fixed one in March 2027; nothing reads the clock. Omar's draft is unshared and open, Lena's draft is
 * shared, Mia's report is open, and the closed report and Omar's closed draft were closed after they were added.
 */
final class DbalRoundUploadTargetsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5201';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f5202';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5203';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f5204';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f5205';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5211';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f5212';
    private const string THIRD_TICKET = '0197a3c0-0000-7000-8000-0000000f5213';
    private const string OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000f5221';
    private const string LENA_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5222';
    private const string MIA_REPORT = '0197a3c0-0000-7000-8000-0000000f5223';
    private const string CLOSED_REPORT = '0197a3c0-0000-7000-8000-0000000f5224';
    private const string OMAR_CLOSED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5225';
    private const string OTHER_ORGANIZATION_REPORT = '0197a3c0-0000-7000-8000-0000000f5226';
    private const string NO_ROUND = '0197a3c0-0000-7000-8000-0000000f5227';
    private const string MIA_REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5231';
    private const string CLOSED_REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5232';
    private const string OTHER_ORGANIZATION_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5233';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f5241';
    private const string STARTED_AT = '2027-03-16 08:00:00+00:00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $lenaSharedDraft = self::draft(self::LENA_SHARED_DRAFT, self::TICKET, self::LENA);
        $lenaSharedDraft->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2027-03-16 09:00:00+00:00'));
        foreach ([
            self::draft(self::OMAR_DRAFT, self::TICKET, self::OMAR),
            $lenaSharedDraft,
            self::report(self::MIA_REPORT, self::ACME_FOODS, self::OTHER_TICKET, self::MIA, self::MIA_REPORT_SCREENSHOT),
            self::report(self::CLOSED_REPORT, self::ACME_FOODS, self::THIRD_TICKET, self::LENA, self::CLOSED_REPORT_SCREENSHOT),
            self::draft(self::OMAR_CLOSED_DRAFT, self::THIRD_TICKET, self::OMAR),
            self::report(self::OTHER_ORGANIZATION_REPORT, self::OTHER_ORGANIZATION, self::TICKET, self::OMAR, self::OTHER_ORGANIZATION_SCREENSHOT),
        ] as $round) {
            $this->transactions()->committedAs($round->organizationId()->toString(), function () use ($round): void {
                new DbalRounds($this->connection())->add($round);
            });
        }
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE rvw_round SET state = 'closed' WHERE id IN (?, ?)",
            [self::CLOSED_REPORT, self::OMAR_CLOSED_DRAFT],
        ));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function eachRoundGivesItsTicketItsOwnerWhetherItIsAnUnsharedDraftAndWhetherItIsOpen(): void
    {
        $fieldsByRound = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $fieldsByRound = [];
            foreach ([self::OMAR_DRAFT, self::LENA_SHARED_DRAFT, self::MIA_REPORT, self::CLOSED_REPORT, self::OMAR_CLOSED_DRAFT] as $roundId) {
                $fieldsByRound[$roundId] = self::fieldsOf($this->uploadTargets()->of($roundId));
            }

            return $fieldsByRound;
        });

        self::assertSame(
            [
                self::OMAR_DRAFT => [self::TICKET, self::OMAR, true, true],
                self::LENA_SHARED_DRAFT => [self::TICKET, self::LENA, false, true],
                self::MIA_REPORT => [self::OTHER_TICKET, self::MIA, false, true],
                self::CLOSED_REPORT => [self::THIRD_TICKET, self::LENA, false, false],
                self::OMAR_CLOSED_DRAFT => [self::THIRD_TICKET, self::OMAR, true, false],
            ],
            $fieldsByRound,
        );
    }

    #[Test]
    public function anIdInCapitalsFindsTheRound(): void
    {
        $round = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?RoundUploadTarget => $this->uploadTargets()->of(strtoupper(self::OMAR_DRAFT)));

        self::assertSame([self::TICKET, self::OMAR, true, true], self::fieldsOf($round));
    }

    #[Test]
    public function aMalformedIdIsNoRoundAndSendsNoQuery(): void
    {
        $debugData = $this->debugData();

        [$rounds, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData): array {
            $debugData->reset();
            $rounds = array_map(fn (string $roundId): ?RoundUploadTarget => $this->uploadTargets()->of($roundId), ['', 'Round 2', self::OMAR_DRAFT."\n", ' '.self::OMAR_DRAFT]);

            return [$rounds, self::queryCountOf($debugData)];
        });

        self::assertSame([[null, null, null, null], 0], [$rounds, $queryCount]);
    }

    #[Test]
    public function aRoundTheOrganizationHasNotIsNone(): void
    {
        $rounds = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->uploadTargets()->of(self::OTHER_ORGANIZATION_REPORT),
            $this->uploadTargets()->of(self::NO_ROUND),
        ]);

        self::assertSame([null, null], $rounds);
        self::assertNotNull($this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): ?RoundUploadTarget => $this->uploadTargets()->of(self::OTHER_ORGANIZATION_REPORT)));
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' draft from the other organization's transaction is row-level security
     * alone.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsRound(): void
    {
        $roundUnderAcmeContext = $this->ownConnectionTransactions()->rolledBackUnderAnotherContext(
            self::OTHER_ORGANIZATION,
            self::ACME_FOODS,
            fn (Connection $connection): ?RoundUploadTarget => new DbalRoundUploadTargets($connection, $this->tenantContext())->of(self::OMAR_DRAFT),
        );

        self::assertNull($roundUnderAcmeContext);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $debugData = $this->debugData();
        $debugData->reset();

        try {
            $this->uploadTargets()->of(self::OMAR_DRAFT);
            self::fail('The round was read with no transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame(
                ['A round is read as an upload target in a transaction under its organization as tenant; none is open.', 0],
                [$refusal->getMessage(), self::queryCountOf($debugData)],
            );
        } finally {
            $this->tenantContext()->clear();
        }
    }

    #[Test]
    public function withNoTenantItRefusesBeforeAnyQuery(): void
    {
        $debugData = $this->debugData();
        $this->connection()->beginTransaction();
        try {
            $debugData->reset();
            $this->uploadTargets()->of(self::OMAR_DRAFT);
            self::fail('The round was read with no tenant.');
        } catch (TenantNotSet) {
            self::assertSame(0, self::queryCountOf($debugData));
        } finally {
            $this->connection()->rollBack();
        }
    }

    /**
     * @return array{string, string, bool, bool}|null
     */
    private static function fieldsOf(?RoundUploadTarget $round): ?array
    {
        return null === $round ? null : [$round->ticketId->toString(), $round->ownerId->toString(), $round->isUnsharedDraft, $round->isOpen];
    }

    private static function draft(string $roundId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId($ownerId), new \DateTimeImmutable(self::STARTED_AT));
    }

    private static function report(string $roundId, string $organizationId, string $ticketId, string $reporterId, string $screenshotId): Round
    {
        return Round::report(new RoundId($roundId), new OrganizationId($organizationId), new TicketId($ticketId), new UserId($reporterId), [
            Screenshot::of(new ScreenshotId($screenshotId), self::MEDIA, 0, null, null, []),
        ], new \DateTimeImmutable(self::STARTED_AT));
    }

    private function uploadTargets(): DbalRoundUploadTargets
    {
        return new DbalRoundUploadTargets($this->connection(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ownConnectionTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions($this->connection(), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
