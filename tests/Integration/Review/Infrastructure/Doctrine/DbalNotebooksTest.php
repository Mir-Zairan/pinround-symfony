<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Infrastructure\Doctrine;

use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookRefused;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Notebook\NotebooksPage;
use App\Review\Domain\Notebook\NotebookSummary;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use App\Review\Domain\Scenario\Scenarios;
use App\Review\Infrastructure\Doctrine\DbalNotebooks;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The click's target name is as deep as ScenarioRules lets a scenario nest, the depth DbalNotebooks reads back.
     */
    #[Test]
    public function aNotebookIsReadBackAsWrittenWithItsPicturesAndScenariosInOrder(): void
    {
        $written = self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER)
            ->withScreenshotAdded(NotebookScreenshot::of(self::SECOND_MEDIA, 'Case B — Prix affiché en €', null, [NotebookPin::placed(12.5, 0.0, 'Why grey?'), NotebookPin::placed(100.0, 99.99, '')]), new \DateTimeImmutable(self::LATER))
            ->withScreenshotAdded(NotebookScreenshot::of(self::FIRST_MEDIA, null, "Line one\r\nline two\u{2028}", []), new \DateTimeImmutable(self::LATER))
            ->withScenarios(ScenarioRules::parse([
                self::scenario('The banner shows', [
                    ['type' => 'go_to', 'say' => 'Open the cart.', 'url' => 'http://localhost:8000/cart'],
                    ['type' => 'click', 'say' => 'Order.', 'target' => ['role' => 'button', 'name' => 'Order now']],
                    ['type' => 'type', 'say' => 'Clear it.', 'target' => ['label' => 'Quantity'], 'text' => ''],
                    ['type' => 'expect_text', 'say' => "\u{FEFF}Prix affiché.\r\n", 'target' => ['role' => 'alert'], 'text' => 'Out of stock — 0 €'],
                ]),
                self::scenario('A second case', [['type' => 'expect_visible', 'say' => 'The banner.', 'target' => ['text' => 'Stock']]]),
            ]), new \DateTimeImmutable(self::LATER));
        $this->insertCommitted($written);

        $read = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));

        self::assertNotNull($read);
        self::assertEquals($written, $read);
        self::assertSame([self::SECOND_MEDIA, self::FIRST_MEDIA], array_map(static fn (NotebookScreenshot $screenshot): string => $screenshot->mediaId(), $read->screenshots()));
        self::assertSame($written->scenarios()->toArray(), $read->scenarios()->toArray());
        self::assertSame(['The banner shows', 'A second case'], array_map(static fn (array $scenario): string => $scenario['title'], $read->scenarios()->toArray()));
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
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER)->withScenarios(ScenarioRules::parse([self::scenario('One', [['type' => 'pause', 'say' => 'Wait.']])]), new \DateTimeImmutable(self::EARLIER)));
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
        $asOmar = Notebook::restored($read->id(), $read->organizationId(), new UserId(self::OMAR), 'Taken', [], new Scenarios([]), 1, $read->createdAt(), $later, $later);

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

    /**
     * Changed at LATER and used at LATEST: each time column holds its own value, so a write or read that swapped or
     * shared them would show.
     */
    #[Test]
    public function aNotebookUsedAfterItsLastChangeKeepsBothTimes(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER)
            ->renamed('Cart page', new \DateTimeImmutable(self::LATER))
            ->markedUsed(new \DateTimeImmutable(self::LATEST)));

        $read = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
        $columns = $this->storedTimesOf(self::LENAS_FIRST);

        self::assertNotNull($read);
        self::assertEquals([new \DateTimeImmutable(self::EARLIER), new \DateTimeImmutable(self::LATER), new \DateTimeImmutable(self::LATEST)], [$read->createdAt(), $read->updatedAt(), $read->lastUsedAt()]);
        self::assertEquals(['created_at' => new \DateTimeImmutable(self::EARLIER), 'updated_at' => new \DateTimeImmutable(self::LATER), 'last_used_at' => new \DateTimeImmutable(self::LATEST)], $columns);

        $changedAt = new \DateTimeImmutable('2027-03-18 07:30:00.125+01:00');
        $usedAt = new \DateTimeImmutable('2027-03-19 16:45:10.000002+01:00');
        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notebooks()->save($read->renamed('Shop page', $changedAt)->markedUsed($usedAt))));
        self::assertEquals(['created_at' => new \DateTimeImmutable(self::EARLIER), 'updated_at' => $changedAt, 'last_used_at' => $usedAt], $this->storedTimesOf(self::LENAS_FIRST), 'A save writes each time to its own column too.');
    }

    #[Test]
    public function markingUsedMovesOnlyTheLastUseTimeAndOnlyForTheOwner(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::LATER));
        $read = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
        self::assertNotNull($read);

        $marked = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'Omar' => $this->notebooks()->markUsed(new NotebookId(self::LENAS_FIRST), new UserId(self::OMAR), new \DateTimeImmutable('2027-03-20 10:00:00+01:00')),
            'unknown' => $this->notebooks()->markUsed(new NotebookId(self::LENAS_SECOND), new UserId(self::LENA), new \DateTimeImmutable('2027-03-20 10:00:00+01:00')),
            'Lena' => $this->notebooks()->markUsed(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA), new \DateTimeImmutable(self::LATEST)),
        ]);

        self::assertSame(['Omar' => false, 'unknown' => false, 'Lena' => true], $marked);
        $used = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
        self::assertNotNull($used);
        self::assertSame([1, 'Notebook '.self::LENAS_FIRST], [$used->version(), $used->title()], 'Marking used is no save.');
        self::assertEquals(['created_at' => new \DateTimeImmutable(self::LATER), 'updated_at' => new \DateTimeImmutable(self::LATER), 'last_used_at' => new \DateTimeImmutable(self::LATEST)], $this->storedTimesOf(self::LENAS_FIRST));
        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notebooks()->save($read->renamed('Cart page', new \DateTimeImmutable(self::LATEST)))), 'The version read before still saves.');
    }

    #[Test]
    public function aClockBehindTheCreationTimeMarksUsedAtTheCreationTime(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::LATER)->markedUsed(new \DateTimeImmutable(self::LATEST)));

        $marked = $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notebooks()->markUsed(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA), new \DateTimeImmutable(self::EARLIER)));

        self::assertTrue($marked);
        self::assertEquals(new \DateTimeImmutable(self::LATER), $this->storedTimesOf(self::LENAS_FIRST)['last_used_at']);
    }

    /**
     * PostgreSQL refuses a NUL in text and jsonb and json_encode refuses invalid UTF-8; the domain refuses both first,
     * so no row is written or changed and no driver error reaches the person.
     */
    #[Test]
    public function aTitleOrNoteWithANulCharacterOrInvalidUtf8IsRefusedAndNothingIsWritten(): void
    {
        $this->insertCommitted(self::notebook(self::ACME_FOODS, self::LENAS_FIRST, self::LENA, self::EARLIER));
        $read = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
        self::assertNotNull($read);
        $later = new \DateTimeImmutable(self::LATER);
        $refusedReasons = [];

        foreach (["Cart\0page", "Caf\xC3\x28"] as $text) {
            $changes = [
                'new' => fn () => $this->notebooks()->insert(Notebook::start(new NotebookId(self::LENAS_SECOND), new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $text, $later)),
                'rename' => fn () => $this->notebooks()->save($read->renamed($text, $later)),
                'pin note' => fn () => $this->notebooks()->save($read->withScreenshotAdded(NotebookScreenshot::of(self::FIRST_MEDIA, null, null, [NotebookPin::placed(1.0, 1.0, $text)]), $later)),
            ];
            foreach ($changes as $change) {
                try {
                    $this->transactions()->committedAs(self::ACME_FOODS, $change);
                    self::fail('A text with a NUL character or invalid UTF-8 was written.');
                } catch (NotebookRefused $refused) {
                    $refusedReasons[] = $refused->getMessage();
                }
            }
        }

        self::assertSame(array_fill(0, 6, NotebookRefused::UNSAVABLE_TEXT), $refusedReasons);
        $stored = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): NotebooksPage => $this->notebooks()->forOwner(new UserId(self::LENA), null, 20));
        self::assertSame([[self::LENAS_FIRST, 'Notebook '.self::LENAS_FIRST, 0]], array_map(static fn (NotebookSummary $notebook): array => [$notebook->id->toString(), $notebook->title, $notebook->screenshotCount], $stored->notebooks));
        self::assertSame(1, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)))?->version());
    }

    /**
     * Rows in another shape than DbalNotebooks writes, put in through the migrator: each read fails loudly with the
     * reason, never as a notebook with the broken part left out.
     *
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function malformedRows(): iterable
    {
        $deeper = self::scenario('A case', [['type' => 'click', 'say' => 'Order.', 'target' => ['role' => 'button', 'name' => ['text' => 'Order now']]]]);

        yield 'a screenshot without media_id' => [
            '[{"title": null, "summary": null, "pins": []}]',
            '[]',
            'A stored notebook screenshot has another shape than DbalNotebooks writes.',
            null,
        ];
        yield 'a pin that is not {x_pct, y_pct, note}' => [
            '[{"media_id": "'.self::FIRST_MEDIA.'", "title": null, "summary": null, "pins": [{"x": 1, "y": 1, "note": ""}]}]',
            '[]',
            'A stored notebook pin is not {x_pct, y_pct, note}.',
            null,
        ];
        yield 'a scenario that is not an object' => [
            '[]',
            '["Show the stock warning"]',
            'A stored notebook has scenarios ScenarioRules refuses.',
            'Scenario 1: a scenario has exactly title, why, setup, start_url and steps.',
        ];
        yield 'a scenario one level deeper than the rules allow' => [
            '[]',
            json_encode([$deeper], \JSON_THROW_ON_ERROR),
            'A stored notebook nests its screenshots or scenarios deeper than DbalNotebooks writes.',
            null,
        ];
    }

    #[Test]
    #[DataProvider('malformedRows')]
    public function aStoredRowInAnotherShapeIsNotReadAsANotebook(string $screenshotsJson, string $scenariosJson, string $expectedMessage, ?string $expectedScenarioReason): void
    {
        $this->insertRow(self::LENAS_FIRST, $screenshotsJson, $scenariosJson);

        try {
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebooks()->ofOwner(new NotebookId(self::LENAS_FIRST), new UserId(self::LENA)));
            self::fail('A malformed row was read as a notebook.');
        } catch (\LogicException $broken) {
            self::assertSame($expectedMessage, $broken->getMessage());
            if (null !== $expectedScenarioReason) {
                $reason = $broken->getPrevious();
                self::assertInstanceOf(ScenarioRefused::class, $reason);
                self::assertSame($expectedScenarioReason, $reason->reason);
            }
        }
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

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private static function scenario(string $title, array $steps): array
    {
        return ['title' => $title, 'why' => 'OrderLine::warn() runs when stock is 0.', 'setup' => 'One SHOP product with no stock.', 'start_url' => 'http://localhost:8000/orders', 'steps' => $steps];
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

    /**
     * @return array{created_at: \DateTimeImmutable, updated_at: \DateTimeImmutable, last_used_at: \DateTimeImmutable}
     */
    private function storedTimesOf(string $id): array
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array|false => $connection->fetchAssociative('SELECT created_at, updated_at, last_used_at FROM rvw_notebook WHERE id = ?', [$id]));
        self::assertIsArray($row);
        self::assertIsString($row['created_at']);
        self::assertIsString($row['updated_at']);
        self::assertIsString($row['last_used_at']);

        return ['created_at' => new \DateTimeImmutable($row['created_at']), 'updated_at' => new \DateTimeImmutable($row['updated_at']), 'last_used_at' => new \DateTimeImmutable($row['last_used_at'])];
    }

    private function insertRow(string $id, string $screenshotsJson, string $scenariosJson): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $migrator->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
                'INSERT INTO rvw_notebook (organization_id, id, owner_id, title, screenshots, scenarios, version, created_at, updated_at, last_used_at)'
                .' VALUES (?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), 1, ?, ?, ?)',
                [self::ACME_FOODS, $id, self::LENA, 'Broken', $screenshotsJson, $scenariosJson, self::EARLIER, self::EARLIER, self::EARLIER],
            ));
        } finally {
            $tenantContext->clear();
        }
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
