<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftsPage;
use App\Review\Api\ReportDraftSummary;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Infrastructure\Doctrine\DbalReportDraftRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalReportDraftRows on the real rvw_report_draft as pinround_app, under row-level security. Every time is a fixed one
 * in March 2027; nothing reads the clock. Each test commits its drafts, so what another transaction wrote is what is
 * read back, and removes them through the migrator.
 */
final class DbalReportDraftRowsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f6102';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6103';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6104';
    private const string MIAS_FIRST = '0197a3c0-0000-7000-8000-0000000f6111';
    private const string MIAS_SECOND = '0197a3c0-0000-7000-8000-0000000f6112';
    private const string OMARS = '0197a3c0-0000-7000-8000-0000000f6113';
    private const string BETA_MIAS = '0197a3c0-0000-7000-8000-0000000f6114';
    private const string MIAS_THIRD = '0197a3c0-0000-7000-8000-0000000f6115';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000f6121';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000f6122';
    // An offset other than UTC, so a time written without its offset would read back an hour off.
    private const string EARLIER = '2027-03-15 11:20:30+01:00';
    private const string LATER = '2027-03-16 09:00:00+01:00';

    protected function setUp(): void
    {
        $this->removeDrafts();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeDrafts();
        parent::tearDown();
    }

    #[Test]
    public function aDraftIsReadBackAsWrittenWithItsPicturesInOrder(): void
    {
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_FIRST, self::MIA, self::content(), self::EARLIER);

        $draft = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->rows()->ofOwner(self::MIAS_FIRST, new UserId(self::MIA)));

        self::assertNotNull($draft);
        self::assertSame([self::MIAS_FIRST, 'SHOP', 'Total is doubled', 'Seen on the cart page.', 1], [$draft->id, $draft->projectKey, $draft->title, $draft->detail, $draft->version]);
        self::assertEquals(new \DateTimeImmutable(self::EARLIER), $draft->createdAt);
        self::assertEquals(self::content()->screenshots, $draft->screenshots);
    }

    /**
     * Two of Mia's drafts tie on updated_at, inserted lowest id first; Omar's and another organization's draft of Mia
     * sit at the same time and would come between them if the owner or the tenant were not in the query.
     */
    #[Test]
    public function theOwnersListHoldsOnlyTheirOwnDraftsNewestChangeFirstAPageAtATime(): void
    {
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_FIRST, self::MIA, self::content(), self::EARLIER);
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_SECOND, self::MIA, new ReportDraftContent('No pictures', '', []), self::LATER);
        $this->insertCommitted(self::ACME_FOODS, self::OMARS, self::OMAR, self::content(), self::LATER);
        $this->insertCommitted(self::BETA_BAKERY, self::BETA_MIAS, self::MIA, self::content(), self::LATER);
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_THIRD, self::MIA, self::content(), self::LATER);

        // One draft a page, so the cursor falls between the two tied drafts.
        $pages = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $pages = [$this->rows()->forOwner(new UserId(self::MIA), null, 1)];
            while (null !== $pages[\count($pages) - 1]->nextCursor && \count($pages) < 5) {
                $pages[] = $this->rows()->forOwner(new UserId(self::MIA), $pages[\count($pages) - 1]->nextCursor, 1);
            }

            return $pages;
        });

        self::assertSame(
            [[[self::MIAS_THIRD, 'Total is doubled', 2]], [[self::MIAS_SECOND, 'No pictures', 0]], [[self::MIAS_FIRST, 'Total is doubled', 2]]],
            array_map(static fn (ReportDraftsPage $page): array => array_map(static fn (ReportDraftSummary $draft): array => [$draft->id, $draft->title, $draft->screenshotCount], $page->drafts), $pages),
        );
        self::assertEquals(new \DateTimeImmutable(self::LATER), $pages[1]->drafts[0]->updatedAt);
        self::assertNull($pages[2]->nextCursor, 'No draft follows the last one.');
    }

    #[Test]
    public function aFullLastPageHasNoNextCursor(): void
    {
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_FIRST, self::MIA, self::content(), self::EARLIER);
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_SECOND, self::MIA, self::content(), self::LATER);

        $page = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->rows()->forOwner(new UserId(self::MIA), null, 2));

        self::assertCount(2, $page->drafts);
        self::assertNull($page->nextCursor);
    }

    /**
     * Another person's draft, another organization's (row-level security alone hides it, as the owner is the same) and
     * a malformed id are not found alike.
     */
    #[Test]
    public function anotherPersonsDraftAnotherOrganizationsAndAMalformedIdAreNotFound(): void
    {
        $this->insertCommitted(self::ACME_FOODS, self::OMARS, self::OMAR, self::content(), self::EARLIER);
        $this->insertCommitted(self::BETA_BAKERY, self::BETA_MIAS, self::MIA, self::content(), self::EARLIER);

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->rows()->ofOwner(self::OMARS, new UserId(self::MIA)),
            $this->rows()->ofOwner(self::BETA_MIAS, new UserId(self::MIA)),
            $this->rows()->ofOwner("not-a-uuid\n", new UserId(self::MIA)),
        ]);

        self::assertSame([null, null, null], $found);
        self::assertNotNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->rows()->ofOwner(self::OMARS, new UserId(self::OMAR))), 'Omar finds his own.');
    }

    #[Test]
    public function anUpdateWritesOnlyAtTheExpectedVersionAndOnlyForTheOwner(): void
    {
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_FIRST, self::MIA, self::content(), self::EARLIER);
        $changed = new ReportDraftContent('Total is doubled at checkout', '', []);

        $written = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'Omar' => $this->rows()->update(self::MIAS_FIRST, new UserId(self::OMAR), 1, $changed, new \DateTimeImmutable(self::LATER)),
            'stale' => $this->rows()->update(self::MIAS_FIRST, new UserId(self::MIA), 2, $changed, new \DateTimeImmutable(self::LATER)),
            'Mia' => $this->rows()->update(self::MIAS_FIRST, new UserId(self::MIA), 1, $changed, new \DateTimeImmutable(self::LATER)),
            'again at 1' => $this->rows()->update(self::MIAS_FIRST, new UserId(self::MIA), 1, self::content(), new \DateTimeImmutable(self::LATER)),
        ]);

        self::assertSame(['Omar' => false, 'stale' => false, 'Mia' => true, 'again at 1' => false], $written);
        $draft = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->rows()->ofOwner(self::MIAS_FIRST, new UserId(self::MIA)));
        self::assertNotNull($draft);
        self::assertSame(['Total is doubled at checkout', '', [], 2], [$draft->title, $draft->detail, $draft->screenshots, $draft->version]);
        self::assertEquals(new \DateTimeImmutable(self::LATER), $draft->updatedAt);
    }

    #[Test]
    public function aDeleteRemovesOnlyTheOwnersDraftAndOnlyAtTheExpectedVersionWhenOneIsGiven(): void
    {
        $this->insertCommitted(self::ACME_FOODS, self::MIAS_FIRST, self::MIA, self::content(), self::EARLIER);

        $removed = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'Omar' => $this->rows()->delete(self::MIAS_FIRST, new UserId(self::OMAR)),
            'stale' => $this->rows()->delete(self::MIAS_FIRST, new UserId(self::MIA), 2),
            'Mia' => $this->rows()->delete(self::MIAS_FIRST, new UserId(self::MIA), 1),
            'again' => $this->rows()->delete(self::MIAS_FIRST, new UserId(self::MIA)),
        ]);

        self::assertSame(['Omar' => false, 'stale' => false, 'Mia' => true, 'again' => false], $removed);
    }

    #[Test]
    public function withoutATransactionNothingIsRead(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->rows()->forOwner(new UserId(self::MIA), null, 20);
    }

    private static function content(): ReportDraftContent
    {
        return new ReportDraftContent('Total is doubled', 'Seen on the cart page.', [
            new ScreenshotWithPins(self::SECOND_MEDIA, 'Case B — Prix affiché en €', null, [new PlacedPin(12.5, 0.0, 'Total'), new PlacedPin(100.0, 99.99, '')]),
            new ScreenshotWithPins(self::FIRST_MEDIA, null, "Line one\nline two", []),
        ]);
    }

    private function insertCommitted(string $organizationId, string $id, string $owner, ReportDraftContent $content, string $at): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $id, $owner, $content, $at): void {
            $this->rows()->insert(new OrganizationId($organizationId), $id, new UserId($owner), 'SHOP', $content, new \DateTimeImmutable($at));
        });
    }

    private function removeDrafts(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                $migrator->transactional(static fn (Connection $connection): int|string => $connection->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [$organizationId]));
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function rows(): DbalReportDraftRows
    {
        return new DbalReportDraftRows(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
