<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Review\Api\Notebooks;
use App\Review\Api\NotebookSummary;
use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Notebook\NotebookSummary as StoredNotebookSummary;
use App\Review\Infrastructure\Doctrine\DbalNotebooks;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A person's private notebook (ADR-0046, P13.T6) on every read path of Review that can hold it: Notebooks::get and
 * Notebooks::forOwner, as the container wires them for a request with the viewer's login (SymfonyCurrentUser,
 * NotebookVoter through SymfonyNotebookAccess, DbalNotebooks), and DbalNotebooks under another organization as tenant.
 * Lena's notebook, titled with the leak marker, holds a picture; Omar has one of his own, Ben one at Beta Bakery, all
 * last used at the same time. Lena's reaches Lena alone: not Omar, not Sara (an Admin), nobody else of Acme Foods and
 * not another organization's Admin.
 */
final class NotebookLeakTest extends VisibilityLeakCast
{
    private const string LENAS_NOTEBOOK = '0197a3c0-0000-7000-8000-000000005d01';
    private const string OMARS_NOTEBOOK = '0197a3c0-0000-7000-8000-000000005d02';
    private const string BENS_NOTEBOOK = '0197a3c0-0000-7000-8000-000000005d03';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000005d11';
    private const string LAST_USED = '2027-03-15 10:00:00+00:00';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::removeNotebooks();
        $at = new \DateTimeImmutable(self::LAST_USED);
        $notebooks = [
            Notebook::start(new NotebookId(self::LENAS_NOTEBOOK), new OrganizationId(self::ACME_FOODS), new UserId(self::VIEWERS['Lena'][0]), self::LEAK_MARKER, $at)
                ->withScreenshotAdded(NotebookScreenshot::of(self::MEDIA, self::LEAK_MARKER, null, []), $at),
            Notebook::start(new NotebookId(self::OMARS_NOTEBOOK), new OrganizationId(self::ACME_FOODS), new UserId(self::VIEWERS['Omar'][0]), 'Order page', $at),
            Notebook::start(new NotebookId(self::BENS_NOTEBOOK), new OrganizationId(self::BETA_BAKERY), new UserId(self::VIEWERS['Ben'][0]), 'Bread page', $at),
        ];
        foreach ($notebooks as $notebook) {
            self::transactions()->committedAs($notebook->organizationId()->toString(), static function () use ($notebook): void {
                self::notebookRows()->insert($notebook);
            });
        }
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeNotebooks();
        parent::tearDownAfterClass();
    }

    /**
     * Each viewer of the cast and the notebooks of their own they list.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function viewersOfLenasNotebook(): iterable
    {
        yield 'Lena, whose notebook it is' => ['Lena', [self::LENAS_NOTEBOOK]];
        yield 'Omar, with a notebook of his own' => ['Omar', [self::OMARS_NOTEBOOK]];
        yield 'Sara, an Admin of the same organization' => ['Sara', []];
        yield 'Mia, the outside reporter' => ['Mia', []];
        yield 'Raj, a manager' => ['Raj', []];
        yield 'Noor, an outsider with no link' => ['Noor', []];
        yield 'Ben, an Admin of another organization, with a notebook there' => ['Ben', [self::BENS_NOTEBOOK]];
    }

    /**
     * @param list<string> $ownNotebooks
     */
    #[Test]
    #[DataProvider('viewersOfLenasNotebook')]
    public function getAndTheListGiveLenasNotebookToLenaAlone(string $viewer, array $ownNotebooks): void
    {
        $read = self::asLoggedIn($viewer, static fn (Notebooks $notebooks): array => [
            'get' => $notebooks->get(self::LENAS_NOTEBOOK)?->title,
            'list' => array_map(static fn (NotebookSummary $notebook): string => $notebook->id, $notebooks->forOwner()->notebooks),
        ]);

        self::assertSame('Lena' === $viewer ? self::LEAK_MARKER : null, $read['get'], \sprintf('%s gets Lena\'s notebook.', $viewer));
        self::assertSame($ownNotebooks, $read['list'], \sprintf('%s lists notebooks not their own.', $viewer));
    }

    /**
     * Written out by viewer, so a change to the provider cannot hide one. Omar and Ben each get their own, so the path
     * is proven to answer, not merely to answer nothing.
     */
    #[Test]
    public function lenasNotebookIsInvisibleToOmarSaraAndAnotherOrganization(): void
    {
        self::assertSame(self::LEAK_MARKER, self::asLoggedIn('Lena', static fn (Notebooks $notebooks): ?string => $notebooks->get(self::LENAS_NOTEBOOK)?->title));
        foreach (['Omar', 'Sara', 'Ben'] as $viewer) {
            self::assertNull(self::asLoggedIn($viewer, static fn (Notebooks $notebooks): ?string => $notebooks->get(self::LENAS_NOTEBOOK)?->title), $viewer);
            self::assertNull(self::asLoggedIn($viewer, static fn (Notebooks $notebooks): ?string => $notebooks->get(strtoupper(self::LENAS_NOTEBOOK))?->title), $viewer.', the id in capitals');
        }
        self::assertSame('Order page', self::asLoggedIn('Omar', static fn (Notebooks $notebooks): ?string => $notebooks->get(self::OMARS_NOTEBOOK)?->title));
        self::assertSame('Bread page', self::asLoggedIn('Ben', static fn (Notebooks $notebooks): ?string => $notebooks->get(self::BENS_NOTEBOOK)?->title));
    }

    /**
     * Beta Bakery as tenant, asking for Lena by her own id: the organization alone hides her Acme Foods notebook, in
     * the repository's queries and, under them, in row-level security.
     */
    #[Test]
    public function theRepositoryUnderAnotherOrganizationFindsNoneOfAcmeFoodsNotebooks(): void
    {
        $lena = new UserId(self::VIEWERS['Lena'][0]);

        $underBeta = self::transactions()->rolledBackAs(self::BETA_BAKERY, static fn (): array => [
            'ofOwner' => self::notebookRows()->ofOwner(new NotebookId(self::LENAS_NOTEBOOK), $lena),
            'forOwner' => self::notebookRows()->forOwner($lena, null, 100)->notebooks,
            'rows' => self::connection()->fetchFirstColumn('SELECT id FROM rvw_notebook ORDER BY id'),
        ]);
        $underAcme = self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => array_map(
            static fn (StoredNotebookSummary $notebook): string => $notebook->id->toString(),
            self::notebookRows()->forOwner($lena, null, 100)->notebooks,
        ));

        self::assertSame(['ofOwner' => null, 'forOwner' => [], 'rows' => [self::BENS_NOTEBOOK]], $underBeta);
        self::assertSame([self::LENAS_NOTEBOOK], $underAcme, 'Under Acme Foods the same query finds it.');
    }

    /**
     * Runs $work on the container's Notebooks with the viewer's login in the token storage, as a request would, in a
     * rolled-back transaction under their organization.
     *
     * @template T
     *
     * @param \Closure(Notebooks): T $work
     *
     * @return T
     */
    private static function asLoggedIn(string $viewer, \Closure $work): mixed
    {
        return self::asViewer($viewer, static function (TokenInterface $token) use ($work): mixed {
            $tokenStorage = self::getContainer()->get('security.token_storage');
            $tokenStorage->setToken($token);
            try {
                $notebooks = self::getContainer()->get('test.review_notebooks');
                \assert($notebooks instanceof Notebooks);

                return $work($notebooks);
            } finally {
                $tokenStorage->setToken(null);
            }
        });
    }

    private static function notebookRows(): DbalNotebooks
    {
        return new DbalNotebooks(self::connection(), self::tenantContext());
    }

    private static function removeNotebooks(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_notebook WHERE organization_id = ?', [$organizationId]));
        }
    }
}
