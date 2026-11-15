<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\NewTicket;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadedMedia;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\Review\Api\RoundRefused;
use App\Review\UI\NewTicket\ScreenshotReportOnNewTicket;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Media\Persistence\MediaRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\NewTicketReport;
use App\Ticketing\Api\NewTicketReportRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NewTicketReport as the container wires it, on the real med_object, tkt_ticket and rvw_ tables as pinround_app under
 * row-level security. Lena reported a ticket of Acme Foods' Shop team; Acme Foods holds a ready and a pending picture
 * of hers and a ready one of Omar's, and Beta Bakery a ready one of hers. Every time is fixed in March 2027.
 */
final class ScreenshotReportOnNewTicketTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f5702';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5711';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f5712';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f5721';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f5722';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5731';
    private const string LENAS_READY_MEDIA = '0197a3c0-0000-7000-8000-0000000f5741';
    private const string LENAS_PENDING_MEDIA = '0197a3c0-0000-7000-8000-0000000f5742';
    private const string OMARS_READY_MEDIA = '0197a3c0-0000-7000-8000-0000000f5743';
    private const string LENAS_MEDIA_AT_BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f5744';
    private const string REPORTED_AT = '2027-03-15 11:20:30+00:00';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        new TicketRows($this->migrator(), $this->tenantContext())->addWithAudienceOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, 1, 'all', self::SHOP_TEAM, self::LENA);
        // TicketRows puts the ticket in a step the turn facts cannot find; the team's To do step is one they can.
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'UPDATE tkt_ticket SET step_id = ? WHERE organization_id = ? AND id = ?',
                [ShopTeamRows::TO_DO_STEP, self::ACME_FOODS, self::TICKET],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
        $this->addMedia(self::ACME_FOODS, self::LENAS_READY_MEDIA, self::LENA, true);
        $this->addMedia(self::ACME_FOODS, self::LENAS_PENDING_MEDIA, self::LENA, false);
        $this->addMedia(self::ACME_FOODS, self::OMARS_READY_MEDIA, self::OMAR, true);
        $this->addMedia(self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, self::LENA, true);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function thePortIsTheScreenshotReportOnNewTicket(): void
    {
        self::assertInstanceOf(ScreenshotReportOnNewTicket::class, self::getContainer()->get('test.ticketing_new_ticket_report'));
    }

    /**
     * As the new ticket form will: the check before the ticket, then attach in the same transaction.
     */
    #[Test]
    public function attachSavesRoundZeroWithTwoPinsForTheReportersReadyPicture(): void
    {
        $posted = self::posted(self::LENAS_READY_MEDIA, [
            2 => ['x' => '50.25', 'y' => '75.50', 'note' => "Logo is cut\r\noff"],
            1 => ['x' => '12.50', 'y' => '80.00', 'note' => 'Total is off'],
        ]);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($posted): ?string {
            $refusal = $this->report()->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $posted);
            $this->report()->attach(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET), $posted, new \DateTimeImmutable(self::REPORTED_AT));

            return $refusal;
        });

        self::assertNull($refusal);
        self::assertSame(
            [['number' => 0, 'kind' => 'report', 'owner_id' => self::LENA, 'media_id' => self::LENAS_READY_MEDIA, 'title' => 'Checkout page']],
            $this->rowsAs('SELECT r.number, r.kind, r.owner_id, s.media_id, s.title FROM rvw_round r JOIN rvw_screenshot s ON s.organization_id = r.organization_id AND s.round_id = r.id WHERE r.ticket_id = ?'),
        );
        self::assertSame(
            [
                ['number' => 1, 'x_pct' => '12.50', 'y_pct' => '80.00', 'note' => 'Total is off', 'author_id' => self::LENA],
                ['number' => 2, 'x_pct' => '50.25', 'y_pct' => '75.50', 'note' => "Logo is cut\noff", 'author_id' => self::LENA],
            ],
            $this->rowsAs(
                'SELECT p.number, p.x_pct, p.y_pct, p.note, p.author_id FROM rvw_pin p JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id'
                .' JOIN rvw_round r ON r.organization_id = s.organization_id AND r.id = s.round_id WHERE r.ticket_id = ? ORDER BY p.number',
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function picturesThatAreRefused(): iterable
    {
        yield 'Omar\'s ready picture in the same organization' => [self::OMARS_READY_MEDIA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'Lena\'s ready picture in another organization' => [self::LENAS_MEDIA_AT_BETA_BAKERY, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'Lena\'s picture still processing' => [self::LENAS_PENDING_MEDIA, RoundRefused::MEDIA_STILL_PROCESSING];
    }

    /**
     * The transaction commits after the refusal, so the empty tables show attach saved nothing, not a rollback.
     */
    #[Test]
    #[DataProvider('picturesThatAreRefused')]
    public function aPictureThatIsNotTheReportersOwnReadyOneIsRefusedAndNothingIsSaved(string $mediaId, string $reason): void
    {
        $posted = self::posted($mediaId, [1 => ['x' => '12.50', 'y' => '80.00', 'note' => 'Total is off']]);

        [$refusal, $attachRefusal] = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($posted): array {
            $refusal = $this->report()->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $posted);
            try {
                $this->report()->attach(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET), $posted, new \DateTimeImmutable(self::REPORTED_AT));
            } catch (NewTicketReportRefused $refused) {
                return [$refusal, $refused->getMessage()];
            }

            return [$refusal, null];
        });

        self::assertSame([$reason, $reason], [$refusal, $attachRefusal]);
        self::assertSame([['rounds' => 0, 'screenshots' => 0, 'pins' => 0]], $this->rowsAs(
            'SELECT (SELECT count(*) FROM rvw_round WHERE ticket_id = ?) AS rounds, (SELECT count(*) FROM rvw_screenshot) AS screenshots, (SELECT count(*) FROM rvw_pin) AS pins',
        ));
    }

    /**
     * The transaction runs as Acme Foods and commits, and Lena's picture is ready there, so only attach's tenant check
     * keeps it out of Acme Foods' rounds for a Beta Bakery request.
     */
    #[Test]
    public function attachForAnotherOrganizationThanTheTenantsFailsAndNothingIsSaved(): void
    {
        $posted = self::posted(self::LENAS_READY_MEDIA, [1 => ['x' => '12.50', 'y' => '80.00', 'note' => 'Total is off']]);

        $failure = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($posted): ?string {
            try {
                $this->report()->attach(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), new TicketId(self::TICKET), $posted, new \DateTimeImmutable(self::REPORTED_AT));
            } catch (\LogicException $wrongTenant) {
                return $wrongTenant->getMessage();
            }

            return null;
        });

        self::assertSame('The new ticket\'s report is read and saved under the tenant of its own organization.', $failure);
        self::assertSame([['rounds' => 0, 'screenshots' => 0, 'pins' => 0]], $this->rowsAs(
            'SELECT (SELECT count(*) FROM rvw_round WHERE ticket_id = ?) AS rounds, (SELECT count(*) FROM rvw_screenshot) AS screenshots, (SELECT count(*) FROM rvw_pin) AS pins',
        ));
    }

    /**
     * Omar's picture is there and ready for Omar, so the refusal above comes from the uploader check alone.
     */
    #[Test]
    public function omarsPictureIsReadyForOmar(): void
    {
        $states = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->uploadedMedia()->statesOf(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [self::OMARS_READY_MEDIA]));

        self::assertSame([self::OMARS_READY_MEDIA => MediaImageStatus::Ready], $states);
    }

    /**
     * @param array<int, array{x: string, y: string, note: string}> $pins
     *
     * @return array{media_id: string, title: string, summary: string, pins: array<int, array{x: string, y: string, note: string}>}
     */
    private static function posted(string $mediaId, array $pins): array
    {
        return ['media_id' => $mediaId, 'title' => 'Checkout page', 'summary' => '', 'pins' => $pins];
    }

    /**
     * Acme Foods' rows as pinround_app; the one parameter is the ticket.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsAs(string $sql): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->entityManager()->getConnection()->fetchAllAssociative($sql, [self::TICKET]));
    }

    private function addMedia(string $organizationId, string $mediaId, string $uploader, bool $isReady): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $mediaId, $uploader, $isReady): void {
            $mediaObject = MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), 'uploads/'.$organizationId.'/'.$mediaId, 'image/png', 2048, new UserId($uploader), new \DateTimeImmutable('2027-03-15 09:00:00 UTC'));
            if ($isReady) {
                $mediaObject->markReady(hash('sha256', $mediaId), 1280, 800, ['view' => 'media/'.$mediaId.'/view.webp']);
            }
            new DoctrineMediaObjects($this->entityManager())->add($mediaObject);
        });
    }

    private function removeRows(): void
    {
        new RoundRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new MediaRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function report(): NewTicketReport
    {
        $report = self::getContainer()->get('test.ticketing_new_ticket_report');
        \assert($report instanceof NewTicketReport);

        return $report;
    }

    private function uploadedMedia(): UploadedMedia
    {
        $uploadedMedia = self::getContainer()->get('test.media_uploaded_media');
        \assert($uploadedMedia instanceof UploadedMedia);

        return $uploadedMedia;
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
