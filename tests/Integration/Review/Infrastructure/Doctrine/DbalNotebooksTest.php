<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Infrastructure\Doctrine;

use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Notebook\NotebooksPage;
use App\Review\Domain\Notebook\NotebookSummary;
use App\Review\Infrastructure\Doctrine\DbalNotebooks;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalNotebooks on the real rvw_notebook as pinround_app, under row-level security. Every time is a fixed one in March
 * 2027; nothing reads the clock. Each test commits its notebooks, so what another transaction wrote is what is read
 * back, and removes them through the migrator.
 */
final class DbalNotebooksTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000a6101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000a6102';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000a6103';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000a6104';
    private const string LENAS_MOST_RECENT = '0197a3c0-0000-7000-8000-0000000a6110';
    private const string LENAS_FIRST = '0197a3c0-0000-7000-8000-0000000a6111';
    private const string LENAS_SECOND = '0197a3c0-0000-7000-8000-0000000a6112';
    private const string OMARS = '0197a3c0-0000-7000-8000-0000000a6113';
    private const string BETA_LENAS = '0197a3c0-0000-7000-8000-0000000a6114';
    private const string LENAS_THIRD = '0197a3c0-0000-7000-8000-0000000a6115';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000a6121';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000a6122';
    // An offset other than UTC and microseconds, so a time written without either would read back off.
    private const string EARLIER = '2027-03-15 11:20:30.250001+01:00';
    private const string LATER = '2027-03-16 09:00:00.5+01:00';
    private const string LATEST = '2027-03-17 08:00:00+01:00';

    protected function setUp(): void
    {
        $this->removeNotebooks();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeNotebooks();
        parent::tearDown();
    }

    #[Test]
    public function aNotebookIsReadBackAsWrittenWithItsPicturesAndScenariosInOrder(): void
    {
        $written = self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER)
            ->withScreenshotAdded(NotebookScreenshot::of(self::SECOND_MEDIA, 'Case B — Prix affiché en €', null, [NotebookPin::placed(12.5, 0.0, 'Why grey?'), NotebookPin::placed(100.0, 99.99, '')]), new \DateTimeImmutable(self::LATER))
            ->withScreenshotAdded(NotebookScreenshot::of(self::FIRST_MEDIA, null, "Line one\r\nline two\u{2028}", []), new \DateTimeImmutable(self::LATER))
            ->withScenarios([
                ['title' => 'The banner shows', 'steps' => [['type' => 'go_to', 'url' => 'http://localhost:8000/cart'], ['type' => 'expect_text', 'target' => ['role' => 'alert'], 'value' => 'Out of stock']]],
                ['title' => 'No steps yet', 'steps' => []],
            ], new \DateTimeImmutable(self::LATER));
        $this->insertCommitted($written);

        $read = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));

        self::assertNotNull($read);
        self::assertEquals($written, $read);
        self::assertSame([self::SECOND_MEDIA, self::FIRST_MEDIA], array_map(static fn (NotebookScreenshot $screenshot): string => $screenshot->mediaId(), $read->screenshots()));
        self::assertSame('2027-03-15 11:20:30.250001+01:00', $read->createdAt()->setTimezone(new \DateTimeZone('+01:00'))->format('Y-m-d H:i:s.uP'));
    }

    /**
     * Lena's notebooks are inserted out of their order: the most recently used has the lowest id, and two tie on their
     * last use, the higher id inserted last. Omar's and another organization's notebook of Lena sit at the same time and
     * would come between them if the owner or the tenant were not in the query.
     */
    #[Test]
    public function theOwnersListHoldsOnlyTheirOwnNotebooksLastUsedFirstAPageAtATime(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_SECOND, self::LENA, self::LATER));
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER)->withScenarios([['title' => 'One']], new \DateTimeImmutable(self::EARLIER)));
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::OMARS, self::OMAR, self::LATER));
        $this->insertCommitted(self::notebook(self::BETA_BAKERY, self::BETA_LENAS, self::LENA, self::LATER));
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_THIRD, self::LENA, self::LATER)->withScreenshotAdded(NotebookScreenshot::of(self::FIRST_MEDIA, null, null, []), new \DateTimeImmutable(self::LATER)));
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_MOST_RECENT, self::LENA, self::LATEST));

        // One notebook a page, so a cursor falls between the two tied notebooks.
        $pages = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $pages = [$this->notebooks()->forOwner(new UserId(self::LENA), null, 1)];
            while (null !== $pages[\count($pages) - 1]->nextCursor && \count($pages) < 6) {
                $pages[] = $this->notebooks()->forOwner(new UserId(self::LENA), $pages[\count($pages) - 1]->nextCursor, 1);
            }

            return $pages;
        });

        self::assertSame(
            [
                [[self::LENAS_MOST_RECENT, 0, 0]],
                [[self::LENAS_THIRD, 1, 0]],
                [[self::LENAS_SECOND, 0, 0]],
                [[self::LENAS_FIRST, 0, 1]],
            ],
            array_map(static fn (NotebooksPage $page): array => array_map(static fn (NotebookSummary $notebook): array => [$notebook->id->toString(), $notebook->screenshotCount, $notebook->scenarioCount], $page->notebooks), $pages),
        );
        self::assertSame('Notebook '.self::LENAS_THIRD, $pages[1]->notebooks[0]->title);
        self::assertEquals(new \DateTimeImmutable(self::LATER), $pages[2]->notebooks[0]->lastUsedAt);
        self::assertNull($pages[3]->nextCursor, 'No notebook follows the last one.');
    }

    #[Test]
    public function aFullLastPageHasNoNextCursorAndAPageSizeOutsideOneToAHundredIsRefused(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER));
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_SECOND, self::LENA, self::LATER));

        $page = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): NotebooksPage => $this->notebooks()->forOwner(new UserId(self::LENA), null, 2));
        $largest = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): NotebooksPage => $this->notebooks()->forOwner(new UserId(self::LENA), null, 100));

        self::assertCount(2, $page->notebooks);
        self::assertNull($page->nextCursor);
        self::assertCount(2, $largest->notebooks);
        foreach ([0, 101] as $pageSize) {
            try {
                $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): NotebooksPage => $this->notebooks()->forOwner(new UserId(self::LENA), null, $pageSize));
                self::fail(\sprintf('A page of %d notebooks was read.', $pageSize));
            } catch (\InvalidArgumentException $refused) {
                self::assertSame('A page holds 1 to 100 notebooks.', $refused->getMessage());
            }
        }
    }

    /**
     * Another person's notebook and another organization's (the owner is the same, so the tenant alone hides it) are
     * not found alike.
     */
    #[Test]
    public function anotherPersonsNotebookAndAnotherOrganizationsAreNotFound(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::OMARS, self::OMAR, self::EARLIER));
        $this->insertCommitted(self::notebook(self::BETA_BAKERY, self::BETA_LENAS, self::LENA, self::EARLIER));

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->notebooks()->ofOwner(new NotebookId(self::OMARS), new UserId(self::LENA)),
            $this->notebooks()->ofOwner(new NotebookId(self::BETA_LENAS), new UserId(self::LENA)),
            $this->notebooks()->forOwner(new UserId(self::LENA), null, 20)->notebooks,
        ]);

        self::assertSame([null, null, []], $found);
        self::assertNotNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::OMARS), new UserId(self::OMAR))), 'Omar finds his own.');
        self::assertNotNull($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::BETA_LENAS), new UserId(self::LENA))), 'Lena finds hers at Beta Bakery.');
    }

    /**
     * Statements with no WHERE of their own, as pinround_app: row-level security alone keeps Beta Bakery's transaction
     * from reading, changing or removing Acme Foods' notebook.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsNotebook(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER));
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        $asBeta = $this->transactions()->committedAs(self::BETA_BAKERY, static fn (): array => [
            'read' => $connection->fetchFirstColumn('SELECT id FROM rvw_notebook'),
            'changed' => $connection->executeStatement("UPDATE rvw_notebook SET title = 'Taken'"),
            'removed' => $connection->executeStatement('DELETE FROM rvw_notebook'),
        ]);
        $asAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => $connection->fetchAllNumeric('SELECT id, title FROM rvw_notebook'));

        self::assertSame(['read' => [], 'changed' => 0, 'removed' => 0], $asBeta);
        self::assertSame([[self::LENAS_FIRST, 'Notebook '.self::LENAS_FIRST]], $asAcme);
    }

    #[Test]
    public function aSaveWritesOnlyAtTheVersionItReadAndOnlyForTheOwner(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER));
        $later = new \DateTimeImmutable(self::LATER);
        $read = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
        self::assertNotNull($read);
        $asOmar = Notebook::restored($read->id(), $read->organizationId(), new UserId(self::OMAR), 'Taken', [], [], 1, $read->createdAt(), $later, $later);

        $written = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'Omar' => $this->notebooks()->save($asOmar),
            'Lena' => $this->notebooks()->save($read->renamed('Cart page', $later)),
            'again at 1' => $this->notebooks()->save($read->renamed('Stale tab', $later)),
        ]);

        self::assertSame(['Omar' => false, 'Lena' => true, 'again at 1' => false], $written);
        $saved = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
        self::assertNotNull($saved);
        self::assertSame(['Cart page', 2], [$saved->title(), $saved->version()]);
        self::assertEquals([new \DateTimeImmutable(self::EARLIER), $later, $later], [$saved->createdAt(), $saved->updatedAt(), $saved->lastUsedAt()]);
    }

    #[Test]
    public function aDeleteRemovesOnlyTheOwnersNotebookAndOnlyAtTheExpectedVersionWhenOneIsGiven(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER));
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::OMARS, self::OMAR, self::EARLIER));

        $removed = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'Omar' => $this->notebooks()->delete(new NotebookId(self::LENAS_FIRST), new UserId(self::OMAR)),
            'stale' => $this->notebooks()->delete(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA), 2),
            'Lena' => $this->notebooks()->delete(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA), 1),
            'again' => $this->notebooks()->delete(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)),
        ]);

        self::assertSame(['Omar' => false, 'stale' => false, 'Lena' => true, 'again' => false], $removed);
        self::assertNotNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::OMARS), new UserId(self::OMAR))), "Omar's notebook stays.");
    }

    #[Test]
    public function aNotebookOfAnotherOrganizationThanTheTenantIsNotWritten(): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notebooks()->insert(self::notebook(self::BETA_BAKERY, self::BETA_LENAS, self::LENA, self::EARLIER)));
            self::fail('A notebook of Beta Bakery was written under Acme Foods.');
        } catch (\LogicException $refused) {
            self::assertSame('A notebook is written under its organization as tenant.', $refused->getMessage());
        }

        self::assertNull($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::BETA_LENAS), new UserId(self::LENA))));
    }

    #[Test]
    public function withoutATransactionNothingIsRead(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Notebooks are read and written in a transaction under the organization as tenant; none is open.');
        $this->notebooks()->forOwner(new UserId(self::LENA), null, 20);
    }

    private static function notebook(string $organizationId, string $id, string $owner, string $at): Notebook
    {
        return Notebook::start(new NotebookId($id), new OrganizationId($organizationId), new UserId($owner), 'Notebook '.$id, new \DateTimeImmutable($at));
    }

    private function insertCommitted(Notebook $notebook): void
    {
        $this->transactions()->committedAs($notebook->organizationId()->toString(), function () use ($notebook): void {
            $this->notebooks()->insert($notebook);
        });
    }

    private function removeNotebooks(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                $migrator->transactional(static fn (Connection $connection): int|string => $connection->executeStatement('DELETE FROM rvw_notebook WHERE organization_id = ?', [$organizationId]));
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function notebooks(): DbalNotebooks
    {
        return new DbalNotebooks(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
