<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application\Notebook;

use App\Identity\Api\CurrentUser;
use App\Media\Api\UploadedMedia;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Infrastructure\Doctrine\DbalMembershipQueries;
use App\Organization\Infrastructure\Security\RequestScopedCurrentActor;
use App\Review\Api\AddedNotebookScreenshot;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebooksPage;
use App\Review\Api\NotebookSummary;
use App\Review\Api\NotebookView;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftView;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\Notebook\UseCaseNotebooks;
use App\Review\Application\PicturesToKeep;
use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookRefused as NotebookRuleBroken;
use App\Review\Domain\Notebook\NotebookRows;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use App\Review\Infrastructure\Doctrine\DbalNotebooks;
use App\Review\Infrastructure\Doctrine\DbalReportDraftRows;
use App\Review\UI\Voter\NotebookVoter;
use App\Review\UI\Voter\SymfonyNotebookAccess;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * UseCaseNotebooks on the real rvw_notebook as pinround_app, under row-level security, with Media's real UploadedMedia
 * over med_object rows and NotebookVoter deciding through SymfonyNotebookAccess on the acting person's token. Lena owns
 * the notebook; Omar and Sara (an Admin) are of Acme Foods too; Ben is an Admin of Beta Bakery, where Lena is a member
 * as well. Every time is a fixed one in March 2027; nothing reads the clock. Each call is committed, or rolled back by
 * its refusal, before the row is read back.
 */
final class UseCaseNotebooksTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e4001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e4002';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e4003';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e4004';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000e4005';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000e4006';
    private const string LENAS_REPORT_DRAFT = '0197a3c0-0000-7000-8000-0000000e4007';
    /** Notebook ids end in 01 to 21. */
    private const string NOTEBOOK_PREFIX = '0197a3c0-0000-7000-8000-0000000e41';
    private const string LENAS_NOTEBOOK = self::NOTEBOOK_PREFIX.'50';
    private const string OMARS_NOTEBOOK = self::NOTEBOOK_PREFIX.'51';
    private const string LENAS_NOTEBOOK_AT_BETA = self::NOTEBOOK_PREFIX.'52';
    private const string UNKNOWN_NOTEBOOK = self::NOTEBOOK_PREFIX.'99';
    /** Lena uploaded the ready pictures ending in 01 to 12 at Acme Foods. */
    private const string MEDIA_PREFIX = '0197a3c0-0000-7000-8000-0000000e42';
    private const string LENAS_REJECTED_PICTURE = self::MEDIA_PREFIX.'50';
    private const string LENAS_PICTURE_STILL_PROCESSING = self::MEDIA_PREFIX.'51';
    private const string OMARS_PICTURE = self::MEDIA_PREFIX.'52';
    private const string LENAS_PICTURE_AT_BETA = self::MEDIA_PREFIX.'53';
    private const string PICTURE_NEVER_UPLOADED = self::MEDIA_PREFIX.'54';
    private const string CREATED = '2027-03-15 11:20:30+01:00';
    private const string ADDED = '2027-03-16 09:00:00+01:00';

    /** Each acting person: their user id and the organization they act in, the tenant of their request. */
    private const array PEOPLE = [
        'Lena' => [self::LENA, self::ACME_FOODS],
        'Omar' => [self::OMAR, self::ACME_FOODS],
        'Sara' => [self::SARA, self::ACME_FOODS],
        'Ben' => [self::BEN, self::BETA_BAKERY],
        'Lena at Beta Bakery' => [self::LENA, self::BETA_BAKERY],
    ];

    protected function setUp(): void
    {
        $this->removeRows();
        $this->addPicture(self::ACME_FOODS, self::LENAS_REJECTED_PICTURE, self::LENA, 'rejected');
        $this->addPicture(self::ACME_FOODS, self::LENAS_PICTURE_STILL_PROCESSING, self::LENA, 'pending');
        $this->addPicture(self::ACME_FOODS, self::OMARS_PICTURE, self::OMAR, 'ready');
        $this->addPicture(self::BETA_BAKERY, self::LENAS_PICTURE_AT_BETA, self::LENA, 'ready');
        foreach (range(1, 12) as $number) {
            $this->addPicture(self::ACME_FOODS, self::mediaId($number), self::LENA, 'ready');
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleOtherThanTheOwner(): iterable
    {
        yield 'Omar, of the same organization' => ['Omar'];
        yield 'Sara, an Admin of the same organization' => ['Sara'];
        yield 'Ben, an Admin of another organization' => ['Ben'];
        yield 'Lena herself, acting in another organization' => ['Lena at Beta Bakery'];
    }

    /**
     * Omar's own notebook was last used when Lena's was, so a list that dropped the owner would show both.
     */
    #[Test]
    #[DataProvider('peopleOtherThanTheOwner')]
    public function everyMethodOnLenasNotebookIsNotFoundForAnyoneElseAndNothingChanges(string $person): void
    {
        $this->insertLenasNotebook();
        $this->insert(self::notebook(self::ACME_FOODS, self::OMARS_NOTEBOOK, self::OMAR, self::CREATED));
        $before = $this->storedRow(self::LENAS_NOTEBOOK);
        self::assertNotFalse($before);

        $writes = self::writesOn(self::LENAS_NOTEBOOK);
        self::assertNotEmpty($writes);
        foreach ($writes as $method => $write) {
            $refused = $this->refusalOf($person, $write);
            self::assertSame([NotebookRefused::NOT_FOUND, true], [$refused->getMessage(), $refused->isNotFound()], $method);
        }
        $read = $this->committedAs($person, static fn (Notebooks $notebooks): array => [
            'get' => $notebooks->get(self::LENAS_NOTEBOOK),
            'forOwner' => self::idsOf($notebooks->forOwner()->notebooks),
        ]);

        self::assertNull($read['get']);
        self::assertSame('Omar' === $person ? [self::OMARS_NOTEBOOK] : [], $read['forOwner']);
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
    }

    /**
     * An id in capitals is still the notebook's; anything else that is not its canonical UUIDv7 is no notebook.
     *
     * @return iterable<string, array{string}>
     */
    public static function idsOfNoNotebook(): iterable
    {
        yield 'not a uuid' => ['not-a-notebook'];
        yield 'empty' => [''];
        yield 'the id with a newline after it' => [self::LENAS_NOTEBOOK."\n"];
        yield 'the id with a CR LF after it' => [self::LENAS_NOTEBOOK."\r\n"];
        yield 'a version 4 uuid' => ['0197a3c0-0000-4000-8000-0000000e4150'];
        yield 'a well-formed id of no notebook' => [self::UNKNOWN_NOTEBOOK];
    }

    #[Test]
    #[DataProvider('idsOfNoNotebook')]
    public function aMalformedOrUnknownIdIsNotFoundForTheOwnerAndNothingChanges(string $notebookId): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);

        $writes = self::writesOn($notebookId);
        self::assertNotEmpty($writes);
        foreach ($writes as $method => $write) {
            $refused = $this->refusalOf('Lena', $write);
            self::assertSame([NotebookRefused::NOT_FOUND, true], [$refused->getMessage(), $refused->isNotFound()], $method);
        }

        self::assertNull($this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get($notebookId)));
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
        self::assertSame(self::LENAS_NOTEBOOK, $this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get(strtoupper(self::LENAS_NOTEBOOK)))?->id, 'The id in capitals finds it.');
    }

    /**
     * NotebookVoter has no actor for Lena and denies, though the notebook is hers, so the read alone must not let a
     * change through, and create refuses to make one.
     */
    #[Test]
    public function whenTheVoterDeniesTheOwnerHerNotebookIsNotFoundAndNothingIsWritten(): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);

        $writes = self::writesOn(self::LENAS_NOTEBOOK);
        self::assertNotEmpty($writes);
        foreach ($writes as $method => $write) {
            $refused = $this->refusalOf('Lena', $write, hasActor: false);
            self::assertSame([NotebookRefused::NOT_FOUND, true], [$refused->getMessage(), $refused->isNotFound()], $method);
        }
        $created = $this->refusalOf('Lena', static fn (Notebooks $notebooks): string => $notebooks->create('Cart page', new \DateTimeImmutable(self::ADDED)), hasActor: false);

        self::assertSame([NotebookRefused::NOT_YOURS_TO_MAKE, false], [$created->getMessage(), $created->isNotFound()]);
        self::assertNull($this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get(self::LENAS_NOTEBOOK), hasActor: false));
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
        self::assertSame([self::LENAS_NOTEBOOK], $this->storedIdsOf(self::ACME_FOODS), 'No notebook was made.');
        self::assertNotNull($this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get(self::LENAS_NOTEBOOK)), 'With an actor she gets it.');
    }

    /**
     * The real actor lookup gives a person with no membership in the tenant's organization an actor with no roles, so
     * NotebookVoter grants her her own new notebook there. What keeps a non-member out is the tenant: as for report
     * drafts, EnterChosenOrganization never makes such an organization the tenant of her request.
     */
    #[Test]
    public function theVoterLetsANonMemberMakeANotebookUnderATenantSheIsNoMemberOf(): void
    {
        $notebookId = $this->transactions()->committedAs(self::ACME_FOODS, function (): string {
            $membershipQueries = new DbalMembershipQueries(self::getContainer()->get('doctrine.dbal.default_connection'));
            self::assertSame([], $membershipQueries->membersAmong(new OrganizationId(self::ACME_FOODS), [new UserId(self::LENA)]), 'Lena is no member of Acme Foods.');

            return $this->notebooksWithTheRealActorLookupOf('Lena', $membershipQueries)->create('Cart page', new \DateTimeImmutable(self::CREATED));
        });

        self::assertSame([$notebookId], $this->storedIdsOf(self::ACME_FOODS));
        $view = $this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get($notebookId));
        self::assertNotNull($view);
        self::assertSame([self::LENA, self::ACME_FOODS, 'Cart page'], [$view->ownerId->toString(), $view->organizationId->toString(), $view->title]);
    }

    #[Test]
    public function aNewNotebookIsTheLoggedInPersonsOwnAndEmptyAndABlankTitleMakesNone(): void
    {
        $notebookId = $this->committedAs('Lena', static fn (Notebooks $notebooks): string => $notebooks->create('  Cart page  ', new \DateTimeImmutable(self::CREATED)));
        $blank = $this->refusalOf('Lena', static fn (Notebooks $notebooks): string => $notebooks->create(" \t ", new \DateTimeImmutable(self::CREATED)));

        $view = $this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get($notebookId));
        self::assertNotNull($view);
        self::assertSame(['Cart page', self::LENA, self::ACME_FOODS, [], [], 1], [$view->title, $view->ownerId->toString(), $view->organizationId->toString(), $view->screenshots, $view->scenarios, $view->version]);
        self::assertEquals(new \DateTimeImmutable(self::CREATED), $view->createdAt);
        self::assertSame(NotebookRuleBroken::TITLE_BLANK, $blank->getMessage());
        self::assertSame([$notebookId], $this->storedIdsOf(self::ACME_FOODS));
    }

    /**
     * A notebook may take a picture still processing, as an agent adds one right after its upload (Notebooks).
     */
    #[Test]
    public function aPictureStillProcessingGoesAtTheEndAndTheVersionGoesUp(): void
    {
        $this->insertLenasNotebook();

        $this->committedAs('Lena', static function (Notebooks $notebooks): void {
            $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins(strtoupper(self::LENAS_PICTURE_STILL_PROCESSING), 'Banner', null, []), new \DateTimeImmutable(self::ADDED));
        });

        $view = $this->lenasNotebook();
        self::assertSame([self::mediaId(1), self::LENAS_PICTURE_STILL_PROCESSING], array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $view->screenshots));
        self::assertSame(['Banner', 2], [$view->screenshots[1]->title, $view->version]);
        self::assertEquals([new \DateTimeImmutable(self::ADDED), new \DateTimeImmutable(self::ADDED)], [$view->updatedAt, $view->lastUsedAt]);
    }

    /**
     * Three pictures stored at version 1, so the count it answers differs from the version it saves.
     */
    #[Test]
    public function addScreenshotAnswersThePictureCountItSaved(): void
    {
        $notebook = self::notebook(self::ACME_FOODS, self::LENAS_NOTEBOOK, self::LENA, self::CREATED);
        foreach (range(1, 3) as $number) {
            $notebook = $notebook->withScreenshotAdded(NotebookScreenshot::of(self::mediaId($number), null, null, []), new \DateTimeImmutable(self::CREATED));
        }
        $this->insert($notebook);

        $added = $this->committedAs('Lena', static fn (Notebooks $notebooks): AddedNotebookScreenshot => $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins(self::mediaId(4), null, null, []), new \DateTimeImmutable(self::ADDED)));

        self::assertEquals(new AddedNotebookScreenshot(false, 4), $added);
        self::assertSame([4, 2], [\count($this->lenasNotebook()->screenshots), $this->lenasNotebook()->version]);
    }

    #[Test]
    public function anEleventhScreenshotIsRefusedAndTheNotebookIsUnchanged(): void
    {
        $full = self::notebook(self::ACME_FOODS, self::LENAS_NOTEBOOK, self::LENA, self::CREATED);
        foreach (range(1, 10) as $number) {
            $full = $full->withScreenshotAdded(NotebookScreenshot::of(self::mediaId($number), 'Picture '.$number, null, []), new \DateTimeImmutable(self::CREATED));
        }
        $this->insert($full);
        $before = $this->storedRow(self::LENAS_NOTEBOOK);

        $refused = $this->refusalOf('Lena', static function (Notebooks $notebooks): void {
            $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins(self::mediaId(11), 'Eleventh', null, []), new \DateTimeImmutable(self::ADDED));
        });

        self::assertSame(NotebookRuleBroken::TOO_MANY_SCREENSHOTS, $refused->getMessage());
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
        self::assertCount(10, $this->lenasNotebook()->screenshots);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function thePictureTheNotebookHolds(): iterable
    {
        yield 'in lowercase' => [self::mediaId(1)];
        yield 'in capitals' => [strtoupper(self::mediaId(1))];
    }

    /**
     * The same add sent again, as after a lost answer: the picture is Lena's own and ready, so only the notebook's
     * one copy of each picture keeps it from a second one. The answer says so, with the count read, and nothing is
     * saved: not the version, the last use or the picture's title.
     */
    #[Test]
    #[DataProvider('thePictureTheNotebookHolds')]
    public function aPictureTheNotebookHoldsAlreadyIsThereAlreadyAndTheNotebookIsUnchanged(string $mediaId): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);
        self::assertNotFalse($before);

        $added = $this->committedAs('Lena', static fn (Notebooks $notebooks): AddedNotebookScreenshot => $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins($mediaId, 'Again', null, []), new \DateTimeImmutable(self::ADDED)));

        self::assertEquals(new AddedNotebookScreenshot(true, 1), $added);
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
        $view = $this->lenasNotebook();
        self::assertSame([[self::mediaId(1)], 1], [array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $view->screenshots), $view->version]);
    }

    /**
     * Ten pictures, the tenth sent again: it is there already, not the cap's refusal.
     */
    #[Test]
    public function thePictureAFullNotebookHoldsAlreadyIsThereAlreadyNotRefused(): void
    {
        $full = self::notebook(self::ACME_FOODS, self::LENAS_NOTEBOOK, self::LENA, self::CREATED);
        foreach (range(1, 10) as $number) {
            $full = $full->withScreenshotAdded(NotebookScreenshot::of(self::mediaId($number), 'Picture '.$number, null, []), new \DateTimeImmutable(self::CREATED));
        }
        $this->insert($full);
        $before = $this->storedRow(self::LENAS_NOTEBOOK);
        self::assertNotFalse($before);

        $added = $this->committedAs('Lena', static fn (Notebooks $notebooks): AddedNotebookScreenshot => $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins(self::mediaId(10), 'Again', null, []), new \DateTimeImmutable(self::ADDED)));

        self::assertEquals(new AddedNotebookScreenshot(true, 10), $added);
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
    }

    /**
     * The picture is in the notebook, but the repeat breaks a picture rule: the rule refuses first, as for report
     * drafts.
     */
    #[Test]
    public function aPictureTheNotebookHoldsWithATitleOverItsCapIsRefusedByThatRule(): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);

        $refused = $this->refusalOf('Lena', static function (Notebooks $notebooks): void {
            $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins(self::mediaId(1), str_repeat('t', 121), null, []), new \DateTimeImmutable(self::ADDED));
        });

        self::assertSame([NotebookRuleBroken::PICTURE_TITLE_TOO_LONG, false], [$refused->getMessage(), $refused->isNotFound()]);
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
    }

    /**
     * Lena's actor and token would let her through, so only the missing logged-in person refuses: a read or write by
     * id is not found, and create and forOwner, which have no notebook to answer for, fail closed.
     */
    #[Test]
    public function withNobodyLoggedInNothingIsFoundCreateAndTheListFailAndNothingChanges(): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);
        self::assertNotFalse($before);

        $writes = self::writesOn(self::LENAS_NOTEBOOK);
        self::assertNotEmpty($writes);
        foreach ($writes as $method => $write) {
            $refused = $this->thrownWithNobodyLoggedIn($write);
            self::assertSame([NotebookRefused::class, NotebookRefused::NOT_FOUND], [$refused::class, $refused->getMessage()], $method);
        }
        $read = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?NotebookView => $this->notebooksOf('Lena', true, $this->notebookRows(), isLoggedIn: false)->get(self::LENAS_NOTEBOOK));
        $created = $this->thrownWithNobodyLoggedIn(static fn (Notebooks $notebooks): string => $notebooks->create('Cart page', new \DateTimeImmutable(self::ADDED)));
        $listed = $this->thrownWithNobodyLoggedIn(static fn (Notebooks $notebooks): NotebooksPage => $notebooks->forOwner());

        self::assertNull($read);
        foreach (['create' => $created, 'forOwner' => $listed] as $method => $thrown) {
            self::assertSame([\LogicException::class, 'Notebooks are a logged-in person\'s own; nobody is logged in.'], [$thrown::class, $thrown->getMessage()], $method);
        }
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
        self::assertSame([self::LENAS_NOTEBOOK], $this->storedIdsOf(self::ACME_FOODS), 'No notebook was made.');
        self::assertSame(self::LENAS_NOTEBOOK, $this->lenasNotebook()->id, 'Logged in, she gets it.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function picturesLenaMayNotAdd(): iterable
    {
        yield 'a rejected picture of hers' => [self::LENAS_REJECTED_PICTURE, RoundRefused::MEDIA_REJECTED];
        yield 'a picture Omar uploaded' => [self::OMARS_PICTURE, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a picture she uploaded at another organization' => [self::LENAS_PICTURE_AT_BETA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a picture nobody uploaded' => [self::PICTURE_NEVER_UPLOADED, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    #[Test]
    #[DataProvider('picturesLenaMayNotAdd')]
    public function aPictureThatIsNotHerUsableUploadIsRefusedAndNothingIsWritten(string $mediaId, string $reason): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);

        $refused = $this->refusalOf('Lena', static function (Notebooks $notebooks) use ($mediaId): void {
            $notebooks->addScreenshot(self::LENAS_NOTEBOOK, new ScreenshotWithPins($mediaId, 'Banner', null, []), new \DateTimeImmutable(self::ADDED));
        });

        self::assertSame([$reason, false], [$refused->getMessage(), $refused->isNotFound()]);
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function scenarioListsRefusedWhole(): iterable
    {
        yield 'twenty-one scenarios' => [self::decoded(array_map(static fn (int $number): array => self::scenario('Case '.$number), range(1, 21))), ScenarioRefused::TOO_MANY_SCENARIOS];
        yield 'one broken scenario among valid ones' => [
            self::decoded([self::scenario('Valid first'), self::scenario('Leaves the app', 'http://other.example/orders'), self::scenario('Valid third')]),
            'Scenario 2, step 1: '.ScenarioRefused::GO_TO_OTHER_HOST,
        ];
        yield 'an object, not a list' => [json_decode('{"0": {}}'), ScenarioRefused::NOT_A_LIST];
    }

    #[Test]
    #[DataProvider('scenarioListsRefusedWhole')]
    public function aScenarioListWithOneBrokenRuleIsRefusedAndTheOldScenariosStay(mixed $decodedScenarios, string $reason): void
    {
        $this->insertLenasNotebook();
        $before = $this->storedRow(self::LENAS_NOTEBOOK);

        $refused = $this->refusalOf('Lena', static function (Notebooks $notebooks) use ($decodedScenarios): void {
            $notebooks->replaceScenarios(self::LENAS_NOTEBOOK, $decodedScenarios, new \DateTimeImmutable(self::ADDED));
        });

        self::assertSame([$reason, false], [$refused->getMessage(), $refused->isNotFound()]);
        self::assertSame($before, $this->storedRow(self::LENAS_NOTEBOOK));
        self::assertSame(['Old one', 'Old two'], self::titlesOf($this->lenasNotebook()->scenarios));
    }

    #[Test]
    public function aValidListReplacesEveryScenarioInItsOwnOrder(): void
    {
        $this->insertLenasNotebook();

        $this->committedAs('Lena', static function (Notebooks $notebooks): void {
            $notebooks->replaceScenarios(self::LENAS_NOTEBOOK, self::decoded([self::scenario('New C'), self::scenario('New A'), self::scenario('New B')]), new \DateTimeImmutable(self::ADDED));
        });

        $view = $this->lenasNotebook();
        self::assertSame(['New C', 'New A', 'New B'], self::titlesOf($view->scenarios));
        self::assertSame(2, $view->version);
        self::assertEquals(new \DateTimeImmutable(self::ADDED), $view->updatedAt);
    }

    #[Test]
    public function twentyScenariosAreTakenAndAnEmptyListLeavesNone(): void
    {
        $this->insertLenasNotebook();

        $this->committedAs('Lena', static function (Notebooks $notebooks): void {
            $notebooks->replaceScenarios(self::LENAS_NOTEBOOK, self::decoded(array_map(static fn (int $number): array => self::scenario('Case '.$number), range(1, Notebooks::MAX_SCENARIOS))), new \DateTimeImmutable(self::ADDED));
        });
        $withTwenty = $this->lenasNotebook();
        self::assertSame(array_map(static fn (int $number): string => 'Case '.$number, range(1, 20)), self::titlesOf($withTwenty->scenarios));

        $this->committedAs('Lena', static function (Notebooks $notebooks): void {
            $notebooks->replaceScenarios(self::LENAS_NOTEBOOK, self::decoded([]), new \DateTimeImmutable(self::ADDED));
        });
        $withNone = $this->lenasNotebook();
        self::assertSame([[], 3], [$withNone->scenarios, $withNone->version]);
    }

    /**
     * The notebook and Lena's report draft hold the same picture: removing the notebook leaves the picture in Media and
     * in the report draft (Notebooks::delete).
     */
    #[Test]
    public function deletingANotebookKeepsAPictureAReportDraftStillUses(): void
    {
        $this->insertLenasNotebook();
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->reportDraftRows()->insert(new OrganizationId(self::ACME_FOODS), self::LENAS_REPORT_DRAFT, new UserId(self::LENA), 'SHOP', new ReportDraftContent('Total is doubled', '', [new ScreenshotWithPins(self::mediaId(1), 'Cart total', null, [])]), new \DateTimeImmutable(self::CREATED));
        });

        $this->committedAs('Lena', static function (Notebooks $notebooks): void {
            $notebooks->delete(self::LENAS_NOTEBOOK);
        });

        self::assertFalse($this->storedRow(self::LENAS_NOTEBOOK));
        self::assertNull($this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get(self::LENAS_NOTEBOOK)));
        $draft = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?ReportDraftView => $this->reportDraftRows()->ofOwner(self::LENAS_REPORT_DRAFT, new UserId(self::LENA)));
        self::assertNotNull($draft);
        self::assertSame([self::mediaId(1)], array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $draft->screenshots));
        self::assertSame('ready', $this->pictureStatus(self::mediaId(1)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        foreach (array_keys(self::writesOn(self::LENAS_NOTEBOOK)) as $method) {
            yield $method => [$method];
        }
    }

    /**
     * Two tabs read the notebook at version 1; the first renames it, then the second's change over version 1 is refused
     * and the first's stays.
     */
    #[Test]
    #[DataProvider('writes')]
    public function aWriteOverAVersionAnotherTabSavedOverIsRefusedAndTheFirstChangeStays(string $method): void
    {
        $this->insertLenasNotebook();
        $readByBothTabs = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Notebook => $this->notebookRows()->ofOwner(new NotebookId(self::LENAS_NOTEBOOK), new UserId(self::LENA)));
        self::assertNotNull($readByBothTabs);

        $this->committedAs('Lena', static function (Notebooks $notebooks): void {
            $notebooks->rename(self::LENAS_NOTEBOOK, 'First tab', new \DateTimeImmutable(self::ADDED));
        });
        $afterTheFirstTab = $this->storedRow(self::LENAS_NOTEBOOK);
        $refused = $this->refusalOf('Lena', self::writesOn(self::LENAS_NOTEBOOK)[$method], rowsOfTheTab: new NotebookRowsReadEarlier($this->notebookRows(), $readByBothTabs));

        self::assertSame([NotebookRefused::NOTEBOOK_CHANGED, false], [$refused->getMessage(), $refused->isNotFound()]);
        self::assertSame($afterTheFirstTab, $this->storedRow(self::LENAS_NOTEBOOK));
        self::assertSame(['First tab', 2], [$this->lenasNotebook()->title, $this->lenasNotebook()->version]);
    }

    /**
     * Lena's notebooks 1 to 21 are inserted out of order; 1 and 2 tie on their last use, the earliest, so the page
     * boundary falls between them. Omar's notebook and Lena's at Beta Bakery tie with them too.
     */
    #[Test]
    public function theListIsTheActingPersonsOwnLastUsedFirstTiesByIdAPageAtATime(): void
    {
        $earliest = '2027-03-01 08:00:00+01:00';
        foreach ([2, 21, 1, ...range(3, 20)] as $number) {
            $lastUsedAt = $number <= 2 ? $earliest : \sprintf('2027-03-01 09:%02d:00+01:00', $number);
            $this->insert(self::notebook(self::ACME_FOODS, self::notebookId($number), self::LENA, $lastUsedAt));
        }
        $this->insert(self::notebook(self::ACME_FOODS, self::OMARS_NOTEBOOK, self::OMAR, $earliest));
        $this->insert(self::notebook(self::BETA_BAKERY, self::LENAS_NOTEBOOK_AT_BETA, self::LENA, $earliest));

        $pages = $this->committedAs('Lena', static function (Notebooks $notebooks): array {
            $first = $notebooks->forOwner();

            return [$first, null === $first->nextCursor ? null : $notebooks->forOwner($first->nextCursor)];
        });

        self::assertSame([...array_map(self::notebookId(...), range(21, 3)), self::notebookId(2)], self::idsOf($pages[0]->notebooks));
        self::assertNotNull($pages[1], 'A second page follows.');
        self::assertSame([self::notebookId(1)], self::idsOf($pages[1]->notebooks));
        self::assertNull($pages[1]->nextCursor);
        self::assertSame(['Notebook '.self::notebookId(21), 0, 0], [$pages[0]->notebooks[0]->title, $pages[0]->notebooks[0]->screenshotCount, $pages[0]->notebooks[0]->scenarioCount]);
        $listOf = fn (string $person): array => $this->committedAs($person, static fn (Notebooks $notebooks): array => self::idsOf($notebooks->forOwner()->notebooks));
        self::assertSame([self::OMARS_NOTEBOOK], $listOf('Omar'));
        self::assertSame([], $listOf('Sara'));
        self::assertSame([], $listOf('Ben'));
        self::assertSame([self::LENAS_NOTEBOOK_AT_BETA], $listOf('Lena at Beta Bakery'));
    }

    /**
     * Every change of a notebook by its id, as the request's person.
     *
     * @return array<string, \Closure(Notebooks): void>
     */
    private static function writesOn(string $notebookId): array
    {
        $at = new \DateTimeImmutable(self::ADDED);

        return [
            'addScreenshot' => static function (Notebooks $notebooks) use ($notebookId, $at): void {
                $notebooks->addScreenshot($notebookId, new ScreenshotWithPins(self::mediaId(2), 'Second', null, []), $at);
            },
            'rename' => static function (Notebooks $notebooks) use ($notebookId, $at): void {
                $notebooks->rename($notebookId, 'Taken', $at);
            },
            'replaceScenarios' => static function (Notebooks $notebooks) use ($notebookId, $at): void {
                $notebooks->replaceScenarios($notebookId, self::decoded([self::scenario('Replaced')]), $at);
            },
            'delete' => static function (Notebooks $notebooks) use ($notebookId): void {
                $notebooks->delete($notebookId);
            },
        ];
    }

    /**
     * Lena's notebook at Acme Foods, with one picture and the scenarios "Old one" and "Old two".
     */
    private function insertLenasNotebook(): void
    {
        $at = new \DateTimeImmutable(self::CREATED);
        $this->insert(self::notebook(self::ACME_FOODS, self::LENAS_NOTEBOOK, self::LENA, self::CREATED)
            ->withScreenshotAdded(NotebookScreenshot::of(self::mediaId(1), 'Cart total', 'The total doubles', [NotebookPin::placed(12.5, 40.0, 'Why twice?')]), $at)
            ->withScenarios(ScenarioRules::parse(self::decoded([self::scenario('Old one'), self::scenario('Old two')])), $at));
    }

    private static function notebook(string $organizationId, string $id, string $owner, string $at): Notebook
    {
        return Notebook::start(new NotebookId($id), new OrganizationId($organizationId), new UserId($owner), 'Notebook '.$id, new \DateTimeImmutable($at));
    }

    private function insert(Notebook $notebook): void
    {
        $this->transactions()->committedAs($notebook->organizationId()->toString(), function () use ($notebook): void {
            $this->notebookRows()->insert($notebook);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function scenario(string $title, string $goToUrl = 'http://localhost:8000/orders/7'): array
    {
        return [
            'title' => $title,
            'why' => 'OrderLine::warn() runs when stock is 0.',
            'setup' => 'One SHOP product with no stock.',
            'start_url' => 'http://localhost:8000/orders',
            'steps' => [['type' => 'go_to', 'say' => 'Open the order.', 'url' => $goToUrl]],
        ];
    }

    /**
     * The scenarios as json_decode($json) gives them, objects as stdClass, as Notebooks::replaceScenarios takes them.
     *
     * @param list<array<string, mixed>> $scenarios
     */
    private static function decoded(array $scenarios): mixed
    {
        return json_decode(json_encode($scenarios, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{title: string, why: string, setup: string, start_url: string, steps: list<array<string, string|array<string, string>>>}> $scenarios
     *
     * @return list<string>
     */
    private static function titlesOf(array $scenarios): array
    {
        return array_map(static fn (array $scenario): string => $scenario['title'], $scenarios);
    }

    /**
     * @param list<NotebookSummary> $notebooks
     *
     * @return list<string>
     */
    private static function idsOf(array $notebooks): array
    {
        return array_map(static fn (NotebookSummary $notebook): string => $notebook->id, $notebooks);
    }

    private static function notebookId(int $number): string
    {
        return self::NOTEBOOK_PREFIX.\sprintf('%02d', $number);
    }

    private static function mediaId(int $number): string
    {
        return self::MEDIA_PREFIX.\sprintf('%02d', $number);
    }

    /**
     * Runs $work on the Notebooks of $person's request in one committed transaction under their organization.
     *
     * @template T
     *
     * @param \Closure(Notebooks): T $work
     * @param bool                   $hasActor false when the actor lookup NotebookVoter asks finds none for $person
     *
     * @return T
     */
    private function committedAs(string $person, \Closure $work, bool $hasActor = true, ?NotebookRows $rowsOfTheTab = null): mixed
    {
        [, $organizationId] = self::PEOPLE[$person];

        return $this->transactions()->committedAs($organizationId, fn (): mixed => $work($this->notebooksOf($person, $hasActor, $rowsOfTheTab ?? $this->notebookRows())));
    }

    /**
     * @param \Closure(Notebooks): mixed $work
     */
    private function refusalOf(string $person, \Closure $work, bool $hasActor = true, ?NotebookRows $rowsOfTheTab = null): NotebookRefused
    {
        try {
            $this->committedAs($person, $work, $hasActor, $rowsOfTheTab);
        } catch (NotebookRefused $refused) {
            // The rollback closed the entity manager; the next transaction needs a fresh one, as a new request has.
            self::getContainer()->get('doctrine')->resetManager();

            return $refused;
        }
        self::fail(\sprintf('%s was let through.', $person));
    }

    /**
     * Runs $work on Notebooks with Lena's token and actor but no logged-in person, committed under Acme Foods.
     *
     * @param \Closure(Notebooks): mixed $work
     */
    private function thrownWithNobodyLoggedIn(\Closure $work): \LogicException
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): mixed => $work($this->notebooksOf('Lena', true, $this->notebookRows(), isLoggedIn: false)));
        } catch (\LogicException $thrown) {
            self::getContainer()->get('doctrine')->resetManager();

            return $thrown;
        }
        self::fail('Nobody logged in was let through.');
    }

    /**
     * @param bool $isLoggedIn false when CurrentUser finds nobody, while the token and actor NotebookVoter asks stay
     */
    private function notebooksOf(string $person, bool $hasActor, NotebookRows $rows, bool $isLoggedIn = true): UseCaseNotebooks
    {
        [$userId] = self::PEOPLE[$person];
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $betaBakery = new OrganizationId(self::BETA_BAKERY);
        $actors = [
            'Lena' => new ActorContext(new UserId(self::LENA), $acmeFoods, Roles::none()),
            'Omar' => new ActorContext(new UserId(self::OMAR), $acmeFoods, Roles::none()),
            'Sara' => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            'Ben' => new ActorContext(new UserId(self::BEN), $betaBakery, new Roles(true, false, [], [], [])),
            'Lena at Beta Bakery' => new ActorContext(new UserId(self::LENA), $betaBakery, Roles::none()),
        ];
        if (!$hasActor) {
            unset($actors[$person]);
        }
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser($person, null), 'main'));
        $access = new SymfonyNotebookAccess(new AuthorizationChecker($tokenStorage, new AccessDecisionManager([new NotebookVoter(new FixedCurrentActor($actors), $tenantContext)])));
        $currentUser = self::createStub(CurrentUser::class);
        $currentUser->method('id')->willReturn($isLoggedIn ? new UserId($userId) : null);
        $uploadedMedia = self::getContainer()->get('test.media_uploaded_media');
        \assert($uploadedMedia instanceof UploadedMedia);

        return new UseCaseNotebooks($rows, $access, $currentUser, new PicturesToKeep($uploadedMedia), $tenantContext);
    }

    /**
     * Notebooks whose NotebookVoter finds $person's actor as a request does, through RequestScopedCurrentActor on
     * $membershipQueries.
     */
    private function notebooksWithTheRealActorLookupOf(string $person, MembershipQueries $membershipQueries): UseCaseNotebooks
    {
        [$userId] = self::PEOPLE[$person];
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tokenUser = new InMemoryUser($person, null);
        $currentUser = self::createStub(CurrentUser::class);
        $currentUser->method('id')->willReturn(new UserId($userId));
        $currentUser->method('idOf')->willReturnCallback(static fn (mixed $user): ?UserId => $user === $tokenUser ? new UserId($userId) : null);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($tokenUser, 'main'));
        $currentActor = new RequestScopedCurrentActor($currentUser, $tenantContext, $membershipQueries);
        $access = new SymfonyNotebookAccess(new AuthorizationChecker($tokenStorage, new AccessDecisionManager([new NotebookVoter($currentActor, $tenantContext)])));
        $uploadedMedia = self::getContainer()->get('test.media_uploaded_media');
        \assert($uploadedMedia instanceof UploadedMedia);

        return new UseCaseNotebooks($this->notebookRows(), $access, $currentUser, new PicturesToKeep($uploadedMedia), $tenantContext);
    }

    private function lenasNotebook(): NotebookView
    {
        $view = $this->committedAs('Lena', static fn (Notebooks $notebooks): ?NotebookView => $notebooks->get(self::LENAS_NOTEBOOK));
        self::assertNotNull($view);

        return $view;
    }

    /**
     * The stored row as text, column by column, read as the migrator with no owner in the query.
     *
     * @return array<string, mixed>|false false when there is no such row
     */
    private function storedRow(string $notebookId): array|false
    {
        return $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): array|false => $migrator->fetchAssociative(
            'SELECT organization_id, owner_id, title, version, screenshots::text AS screenshots, scenarios::text AS scenarios,'
            .' created_at::text AS created_at, updated_at::text AS updated_at, last_used_at::text AS last_used_at FROM rvw_notebook WHERE id = ?',
            [$notebookId],
        ));
    }

    /**
     * @return list<string>
     */
    private function storedIdsOf(string $organizationId): array
    {
        $ids = $this->asTenantMigrator($organizationId, static fn (Connection $migrator): array => $migrator->fetchFirstColumn('SELECT id FROM rvw_notebook WHERE organization_id = ? ORDER BY id', [$organizationId]));

        return array_map(static function (mixed $id): string {
            self::assertIsString($id);

            return $id;
        }, $ids);
    }

    private function pictureStatus(string $mediaId): mixed
    {
        return $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT status FROM med_object WHERE id = ?', [$mediaId]));
    }

    /**
     * @param 'pending'|'ready'|'rejected' $status
     */
    private function addPicture(string $organizationId, string $mediaId, string $uploader, string $status): void
    {
        $isReady = 'ready' === $status;
        $this->asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, TIMESTAMPTZ '2027-03-15 10:00:00+00', ?, ?, ?, ?, ?, CAST(? AS JSONB))",
            [
                $organizationId, $mediaId, 'uploads/'.$mediaId, $uploader, $status,
                'rejected' === $status ? 'Unreadable' : null,
                $isReady ? hash('sha256', $mediaId) : null,
                $isReady ? 1280 : null,
                $isReady ? 800 : null,
                $isReady ? '{"view": "media/x/view.webp"}' : null,
            ],
        ));
    }

    private function removeRows(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->asTenantMigrator($organizationId, static function (Connection $migrator) use ($organizationId): void {
                foreach (['rvw_notebook', 'rvw_report_draft', 'med_object'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
            });
        }
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function asTenantMigrator(string $organizationId, \Closure $work): mixed
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            return $migrator->transactional($work);
        } finally {
            $tenantContext->clear();
        }
    }

    private function notebookRows(): DbalNotebooks
    {
        return new DbalNotebooks(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function reportDraftRows(): DbalReportDraftRows
    {
        return new DbalReportDraftRows(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
