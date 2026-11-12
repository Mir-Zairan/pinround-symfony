<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\Component\TicketFilters;
use App\Web\UI\Component\PersonPickerSearch;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tickets list's person picker in filter mode, through the real firewall and database. Shop has Lena (lead), Omar,
 * Tom, Eva and Ana (members) and Mia (viewer, no assignee), so its toolbar shows "Person ▾". Ops, led by Raj, owns a
 * team-only ticket assigned to Tom that Lena may not see.
 */
final class PersonPickerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use InteractsWithLiveComponents;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string TICKETS = '/tickets';
    private const string COUNT_QUERY = '/GROUP BY ticket\.assignee_id/';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by group code */
    private array $steps = [];

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->people = $this->createAcmeFoodsPeople();
        $this->addShopMembers(['Tom', 'Eva', 'Ana']);
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * The page renders the dialog with its search but no row and no count: the picker reads them in its own request.
     */
    #[Test]
    public function aTeamOfFiveOpensThePickerFromThePersonChipWithoutReadingItsPeople(): void
    {
        $this->addTicket('todo', 'Omar');
        $this->logIn($this->client, $this->people['Lena']);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        $chip = $page->filter('button[data-person-picker-trigger]');
        self::assertSame(['Person ▾', 'dialog', null, 'dialog#open person-picker#open'], [$chip->text(), $chip->attr('aria-haspopup'), $chip->attr('disabled'), $chip->attr('data-action')]);
        $dialog = $page->filter('dialog#ticket-filters-person-picker');
        self::assertSame('Show tickets of…', $dialog->filter('.dialog__title')->text());
        self::assertSame('Sorted by open tickets assigned to them.', $dialog->filter('.person-picker__sub')->text());
        self::assertSame(['Find a person', 'debounce(200)|search'], [$dialog->filter('input[type="search"]')->attr('placeholder'), $dialog->filter('input[type="search"]')->attr('data-model')]);
        self::assertSame('Cancel', $dialog->filter('.dialog__actions')->text());
        self::assertCount(0, $dialog->filter('.pick'));
        $queries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                $queries[] = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }
        self::assertNotSame([], $queries);
        self::assertSame(0, self::countMatching(self::COUNT_QUERY, $queries));
    }

    /**
     * Mobile has Lena alone, so its toolbar names its people and holds no picker.
     */
    #[Test]
    public function aSmallTeamNamesItsPeopleAndHasNoPicker(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?team='.$this->mobile->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me'], $page->filter('.ticket-filters__people .chip')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertCount(0, $page->filter('dialog, [data-person-picker-trigger]'));
    }

    /**
     * Omar has two open tickets and a done one; Tom one Lena may see and one of Ops she may not; Eva one; Ana and Lena
     * none. Ties go by name; Mia, a viewer, is no assignee.
     */
    #[Test]
    public function thePickerListsTheTeamByTheOpenTicketsTheViewerMaySee(): void
    {
        $this->addTicket('todo', 'Omar');
        $this->addTicket('doing', 'Omar');
        $this->addTicket('done', 'Omar');
        $this->addTicket('wait', 'Tom');
        $this->addTicket('todo', 'Eva');
        $ops = $this->createTeam($this->acmeFoods, 'Ops', $this->people['Raj']);
        $this->addTicket('todo', 'Tom', $ops, 'team', 'Raj');
        $this->addTicket('check', 'Tom', $ops, 'team', 'Raj');

        $picker = $this->openedPickerAs($this->people['Lena']);

        self::assertSame(
            [['Omar', '2 open'], ['Eva', '1 open'], ['Tom', '1 open'], ['Ana', '0 open'], ['Lena', '0 open']],
            $this->rowsOf($picker->render()->crawler()),
        );
        self::assertCount(0, $picker->render()->crawler()->filter('[data-person-picker-hint]'));

        $sarasPicker = $this->openedPickerAs($this->people['Sara']);

        self::assertSame(['Tom', '3 open'], $this->rowsOf($sarasPicker->render()->crawler())[0], 'An admin may see the Ops tickets, so counts them.');
    }

    #[Test]
    public function thePickerReadsNothingUntilItIsOpened(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $picker = $this->createLiveComponent('PersonPickerSearch', $this->pickerProps(), $this->client);

        self::assertSame('Loading people…', $picker->render()->crawler()->filter('.person-picker__note')->text());
        self::assertCount(0, $picker->render()->crawler()->filter('.pick'));
    }

    /**
     * 21 more members are named Kim…: the search matches them by first name on the server and shows the first
     * PAGE_SIZE, with a hint, then narrows; a name nobody has says so.
     */
    #[Test]
    public function theSearchNarrowsOnTheServerAndCapsThePage(): void
    {
        $kims = array_map(static fn (int $number): string => \sprintf('Kim%02d', $number), range(1, 21));
        $this->addShopMembers($kims);
        $this->addTicket('todo', 'Kim21');
        $picker = $this->openedPickerAs($this->people['Lena']);
        self::assertCount(PersonPickerSearch::PAGE_SIZE, $this->rowsOf($picker->render()->crawler()));
        self::assertSame('Showing 20 of 26. Type a name to narrow the list.', $picker->render()->crawler()->filter('[data-person-picker-hint]')->text());

        $picker->set('search', ' KIM ');

        $page = $picker->render()->crawler();
        self::assertSame(PersonPickerSearch::PAGE_SIZE, \count($this->rowsOf($page)));
        self::assertSame(['Kim21', '1 open'], $this->rowsOf($page)[0]);
        self::assertSame(['Kim01', '0 open'], $this->rowsOf($page)[1]);
        self::assertSame('Showing 20 of 21. Type a name to narrow the list.', $page->filter('[data-person-picker-hint]')->text());

        $picker->set('search', 'kim2');

        self::assertSame([['Kim21', '1 open'], ['Kim20', '0 open']], $this->rowsOf($picker->render()->crawler()));
        self::assertCount(0, $picker->render()->crawler()->filter('[data-person-picker-hint]'));

        $picker->set('search', 'nobody');

        self::assertSame([], $this->rowsOf($picker->render()->crawler()));
        self::assertSame('No one in this team matches.', $picker->render()->crawler()->filter('.person-picker__note')->text());
    }

    #[Test]
    public function aTooLongSearchIsRefusedWithoutReadingThePeople(): void
    {
        $this->addTicket('todo', 'Omar');
        $this->client->disableReboot();
        $picker = $this->openedPickerAs($this->people['Lena']);
        $debugData = $this->forgetTheTeamPeopleAndTheQueries();

        $picker->set('search', str_repeat('é', PersonPickerSearch::SEARCH_MAX_LENGTH + 1));

        self::assertSame(200, $picker->response()->getStatusCode());
        self::assertSame('The search is too long. Use 100 characters or fewer.', $picker->render()->crawler()->filter('[role="alert"]')->text());
        self::assertCount(0, $picker->render()->crawler()->filter('.pick'));
        self::assertNoPeopleWereRead(self::queriesIn($debugData->getData()));
    }

    /**
     * 100 characters is the cap, however many bytes they take: a search of 100 "é" is taken, and only finds no one.
     */
    #[Test]
    public function aSearchOfTheMostCharactersIsTakenWhateverItsBytes(): void
    {
        $picker = $this->openedPickerAs($this->people['Lena']);

        $picker->set('search', str_repeat('é', PersonPickerSearch::SEARCH_MAX_LENGTH));

        self::assertSame(200, $picker->response()->getStatusCode());
        self::assertCount(0, $picker->render()->crawler()->filter('[role="alert"]'));
        self::assertSame(PersonPickerSearch::NO_MATCHES, $picker->render()->crawler()->filter('.person-picker__note[role="status"]')->text());
    }

    #[Test]
    public function aSearchWithANulIsRefusedWithoutReadingThePeople(): void
    {
        $this->addTicket('todo', 'Omar');
        $this->client->disableReboot();
        $picker = $this->openedPickerAs($this->people['Lena']);
        $debugData = $this->forgetTheTeamPeopleAndTheQueries();

        $picker->set('search', "Om\0ar");

        self::assertSame(200, $picker->response()->getStatusCode());
        self::assertSame(PersonPickerSearch::SEARCH_NOT_TEXT, $picker->render()->crawler()->filter('[role="alert"]')->text());
        self::assertCount(0, $picker->render()->crawler()->filter('.pick'));
        self::assertNoPeopleWereRead(self::queriesIn($debugData->getData()));
    }

    /**
     * A live request is JSON, which cannot carry invalid UTF-8, so the component is given such a search directly: it
     * refuses it and reads no one.
     */
    #[Test]
    public function aSearchOfInvalidUtf8IsRefusedWithoutReadingThePeople(): void
    {
        $factory = self::getContainer()->get('ux.twig_component.component_factory');
        $picker = $factory->get('PersonPickerSearch');
        \assert($picker instanceof PersonPickerSearch);
        $picker->team = $this->shop->toString();
        $picker->isOpened = true;
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        foreach (["Om\xffar", "\xc3", "Om\0ar"] as $search) {
            $picker->search = $search;

            self::assertSame(PersonPickerSearch::SEARCH_NOT_TEXT, $picker->searchRefusal());
            self::assertSame(['rows' => [], 'matchCount' => 0], $picker->shownPeople());
        }
        self::assertSame([], self::queriesIn($debugData->getData()));
    }

    /**
     * Eva has two open tickets in APP and Omar one in SHOP: on ?project=SHOP the page hands the picker SHOP's id, so
     * the counts are the rows the list shows filtered by each; on every project they are all of them.
     */
    #[Test]
    public function theCountsFollowTheProjectTheListShows(): void
    {
        $appProject = $this->addProject($this->shop, 'APP', 'App');
        $this->addTicket('todo', 'Omar');
        $this->addTicket('todo', 'Eva', project: $appProject);
        $this->addTicket('doing', 'Eva', project: $appProject);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        $pickerProps = json_decode((string) $page->filter('[data-live-name-value="PersonPickerSearch"]')->attr('data-live-props-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($pickerProps);
        self::assertSame($this->shopProject, $pickerProps['projectId'] ?? null);
        self::assertSame(
            [['Omar', '1 open'], ['Ana', '0 open'], ['Eva', '0 open'], ['Lena', '0 open'], ['Tom', '0 open']],
            $this->rowsOf($this->openedPickerAs($this->people['Lena'], ['projectId' => $this->shopProject])->render()->crawler()),
        );
        self::assertSame(
            [['Eva', '2 open'], ['Omar', '1 open'], ['Ana', '0 open'], ['Lena', '0 open'], ['Tom', '0 open']],
            $this->rowsOf($this->openedPickerAs($this->people['Lena'])->render()->crawler()),
        );
    }

    /**
     * The client sends the picker's fingerprint with each toolbar request, as the browser does. A search, "Show done"
     * or a chip must give back the bare placeholder: any new prop from the parent would make the browser send the
     * closed picker a request of its own.
     */
    #[Test]
    public function aChangeOfTheToolbarSendsThePickerNoRequest(): void
    {
        $toolbar = $this->toolbarAs($this->people['Lena'], ['project' => 'SHOP', 'projectId' => $this->shopProject]);
        $page = $toolbar->render()->crawler();
        $search = $page->filter('[data-live-name-value="PersonPickerSearch"]');
        $childId = (string) $search->attr('id');
        self::assertNotSame('', $childId);
        $children = [$childId => ['fingerprint' => $search->attr('data-live-fingerprint-value') ?? '', 'tag' => 'div']];
        $toolbarProps = json_decode((string) $page->filter('[data-live-name-value="TicketFilters"]')->attr('data-live-props-value'), true, flags: \JSON_THROW_ON_ERROR);
        $changes = [
            'A search' => [null, ['updated' => ['q' => 'checkout']], '[data-model="debounce(300)|q"][value="checkout"]'],
            'Show done' => ['toggleDone', ['args' => []], '[data-done-chip][aria-current="true"]'],
            'A person chip' => ['filterByPerson', ['args' => ['person' => $this->people['Eva']->id()->toString()]], 'button[data-person-picker-trigger][aria-pressed="true"]'],
        ];

        foreach ($changes as $change => [$action, $content, $changedToolbar]) {
            $this->client->request('POST', $this->liveUrlOf('TicketFilters', $action), [
                'data' => json_encode([...$content, 'props' => $toolbarProps, 'children' => $children], \JSON_THROW_ON_ERROR),
            ]);

            self::assertResponseStatusCodeSame(200);
            $response = $this->client->getCrawler();
            self::assertCount(1, $response->filter($changedToolbar), $change.' did not change the toolbar.');
            $placeholder = $response->filter('[id="'.$childId.'"]');
            self::assertCount(1, $placeholder, $change);
            self::assertNull($placeholder->attr('data-live-props-updated-from-parent-value'), $change.' sends the picker new props, so a request of its own.');
            self::assertSame('', $placeholder->html(), $change.' renders the picker again.');
        }
    }

    /**
     * Each row is a button that emits the toolbar's event and closes the dialog; the person the page filters by, handed
     * over as the dialog opens, is pressed.
     */
    #[Test]
    public function eachRowEmitsThePickAndThePersonThePageShowsIsPressed(): void
    {
        $eva = $this->people['Eva']->id()->toString();
        $picker = $this->openedPickerAs($this->people['Lena'], pressedPerson: $eva);

        $row = $picker->render()->crawler()->filter('.pick[data-person-pick="'.$eva.'"]');

        self::assertSame(
            ['button', 'button', 'true', 'live#emitUp dialog#close', TicketFilters::PERSON_PICKED_EVENT, $eva],
            [$row->nodeName(), $row->attr('type'), $row->attr('aria-pressed'), $row->attr('data-action'), $row->attr('data-live-event-param'), $row->attr('data-live-person-param')],
        );
        self::assertSame(['Eva'], $picker->render()->crawler()->filter('.pick[aria-pressed="true"] .pick__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertCount(4, $picker->render()->crawler()->filter('.pick[aria-pressed="false"]'));
        self::assertCount(0, $picker->render()->crawler()->filter('.pick[href], a.pick'));

        $picker->call('open');

        self::assertCount(0, $picker->render()->crawler()->filter('.pick[aria-pressed="true"]'), 'Opened with everyone shown, no row is pressed.');
    }

    /**
     * A pressed person that is no uuid is refused, and the picker keeps the one it had.
     */
    #[Test]
    public function anOpenWithAPressedPersonThatIsNoUuidIsRefused(): void
    {
        $eva = $this->people['Eva']->id()->toString();
        $picker = $this->openedPickerAs($this->people['Lena'], pressedPerson: $eva);
        $this->client->catchExceptions(true);

        $picker->call('open', ['pressedPerson' => strtoupper($eva)]);

        $response = $picker->response();
        self::assertSame(400, $response->getStatusCode());
        $this->assertRefusalCarriesNoNewFilters($response, PersonPickerSearch::PRESSED_PERSON_NOT_VALID);
        $picker = $this->openedPickerAs($this->people['Lena'], pressedPerson: $eva);
        self::assertSame(['Eva'], $picker->render()->crawler()->filter('.pick[aria-pressed="true"] .pick__name')->each(static fn (Crawler $name): string => $name->text()));
    }

    #[Test]
    public function pickingAPersonFiltersTheListAndTheChipShowsTheirFirstName(): void
    {
        $eva = $this->people['Eva']->id()->toString();
        $toolbar = $this->toolbarAs($this->people['Lena'], ['showsDone' => true]);
        self::assertSame(['Person ▾', 'false'], $this->pickerChipOf($toolbar));

        $toolbar->emit(TicketFilters::PERSON_PICKED_EVENT, ['person' => $eva]);

        self::assertSame(200, $toolbar->response()->getStatusCode());
        self::assertSame(['Eva', 'true'], $this->pickerChipOf($toolbar));
        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TICKETS.'?person='.$eva.'&done=1', 'addsHistoryEntry' => true]);
    }

    /**
     * Raj is in the organization but in no team; Ben is of another organization. Neither is a person of Shop, so the
     * pick is refused and the toolbar keeps showing everyone.
     */
    #[Test]
    public function aPickOfSomeoneOutsideTheTeamIsRefusedAndChangesNoFilter(): void
    {
        $bakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $bakery);
        foreach ([$this->people['Raj'], $ben] as $outsider) {
            $toolbar = $this->toolbarAs($this->people['Lena']);
            self::assertSame(['Person ▾', 'false'], $this->pickerChipOf($toolbar));
            $this->client->catchExceptions(true);

            // The picker's event runs this same listener; emit() would hydrate the component outside a request here.
            $toolbar->call('filterByPerson', ['person' => $outsider->id()->toString()]);

            $response = $toolbar->response();
            self::assertSame(404, $response->getStatusCode());
            $this->assertRefusalCarriesNoNewFilters($response, TicketFilters::NO_SUCH_PERSON);
        }
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLoginAndGetsNoPeople(): void
    {
        $picker = $this->createLiveComponent('PersonPickerSearch', [...$this->pickerProps(), 'isOpened' => true], $this->client);
        $this->client->catchExceptions(true);

        $response = $picker->response();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('pick', (string) $response->getContent());
    }

    /**
     * Ben, an admin of Beta Bakery, sends Shop's picker props: Shop is not a team he may see, so the request is
     * refused before any person is read.
     */
    #[Test]
    public function aPersonOfAnotherOrganizationIsRefusedTheTeamsPeople(): void
    {
        $this->addTicket('todo', 'Omar');
        $bakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $bakery);
        $this->setOrgRoles($ben, $bakery, '{admin}');
        $this->logIn($this->client, $ben);
        $picker = $this->createLiveComponent('PersonPickerSearch', [...$this->pickerProps(), 'isOpened' => true], $this->client);
        $this->client->catchExceptions(true);

        $response = $picker->response();
        self::assertSame(403, $response->getStatusCode());
        $this->assertRefusalCarriesNoNewFilters($response, PersonPickerSearch::NOT_OPEN);
        self::assertCount(0, new Crawler((string) $response->getContent())->filter('.pick'));
    }

    /**
     * Warm (the steps and the team's people cached), opening the picker runs 12 queries: the request's own eight (the
     * user, the credential, the organizations, the transaction, the tenant, the actor's roles and teams), the viewer's
     * roles and teams again and the triager for TicketVisibilities::of(), and the one grouped count.
     */
    #[Test]
    public function openingThePickerRunsOneCountQueryForTheWholeTeam(): void
    {
        $this->client->disableReboot();
        $this->addTicket('todo', 'Omar');
        $this->addTicket('todo', 'Eva');
        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);
        $picker = $this->createLiveComponent('PersonPickerSearch', $this->pickerProps(), $this->client);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $picker->call('open');

        self::assertSame(200, $picker->response()->getStatusCode());
        $queries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                $queries[] = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }
        self::assertSame(1, self::countMatching(self::COUNT_QUERY, $queries));
        self::assertSame(0, self::countMatching('/FROM wf_step|FROM org_team_member WHERE team_id|FROM idn_user WHERE id = ANY/', $queries), 'The steps and the team people come from their caches.');
        self::assertSame(1, self::countMatching('/FROM org_settings/', $queries));
        self::assertSame(12, \count($queries));
    }

    /**
     * Empties the team people cache, which the open filled, so a read of the people would show as queries, and the
     * query log. The client must not reboot, or its requests would log to another container's holder.
     */
    private function forgetTheTeamPeopleAndTheQueries(): DebugDataHolder
    {
        self::assertTrue(self::getContainer()->get('cache.team_people')->clear());
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        return $debugData;
    }

    /**
     * @param list<string> $queries the refused search's request, which must have run queries of its own
     */
    private static function assertNoPeopleWereRead(array $queries): void
    {
        self::assertNotSame([], $queries, 'The request logged no query, so it cannot show that none read the people.');
        self::assertSame(0, self::countMatching('/FROM org_team_member WHERE team_id|FROM idn_user WHERE id = ANY/', $queries), 'The team\'s people were read.');
        self::assertSame(0, self::countMatching(self::COUNT_QUERY, $queries));
    }

    /**
     * @param list<string> $queries
     */
    private static function countMatching(string $pattern, array $queries): int
    {
        return \count(array_filter($queries, static fn (string $sql): bool => 1 === preg_match($pattern, $sql)));
    }

    /**
     * @param array<mixed> $debugData doctrine.debug_data_holder's queries by connection
     *
     * @return list<string>
     */
    private static function queriesIn(array $debugData): array
    {
        $queries = [];
        foreach ($debugData as $queriesOfOneConnection) {
            foreach (\is_array($queriesOfOneConnection) ? $queriesOfOneConnection : [] as $query) {
                $queries[] = \is_array($query) && \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        return $queries;
    }

    private function liveUrlOf(string $component, ?string $action): string
    {
        return self::getContainer()->get('router')->generate('ux_live_component', array_filter(['_live_component' => $component, '_live_action' => $action], static fn (?string $value): bool => null !== $value));
    }

    private function assertRefusalCarriesNoNewFilters(Response $response, string $refusal): void
    {
        $errorPage = new Crawler((string) $response->getContent());
        self::assertSame($refusal, $errorPage->filter('h1.exception-message')->text());
        self::assertCount(0, $errorPage->filter('[data-live-props-value]'));
        self::assertCount(0, $errorPage->filter('[data-live-events-to-dispatch-value]'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function openedPickerAs(User $user, array $props = [], string $pressedPerson = ''): TestLiveComponent
    {
        $this->logIn($this->client, $user);
        $picker = $this->createLiveComponent('PersonPickerSearch', [...$this->pickerProps(), ...$props], $this->client);
        $picker->call('open', ['pressedPerson' => $pressedPerson]);
        self::assertSame(200, $picker->response()->getStatusCode());

        return $picker;
    }

    /**
     * @return array<string, string>
     */
    private function pickerProps(): array
    {
        return ['team' => $this->shop->toString(), 'pickedEvent' => TicketFilters::PERSON_PICKED_EVENT];
    }

    /**
     * @param array<string, mixed> $props
     */
    private function toolbarAs(User $user, array $props = []): TestLiveComponent
    {
        $this->logIn($this->client, $user);

        return $this->createLiveComponent('TicketFilters', ['team' => $this->shop->toString(), ...$props], $this->client);
    }

    /**
     * @return array{string, string|null}
     */
    private function pickerChipOf(TestLiveComponent $toolbar): array
    {
        $chip = $toolbar->render()->crawler()->filter('button[data-person-picker-trigger]');

        return [$chip->text(), $chip->attr('aria-pressed')];
    }

    /**
     * @return list<array{string, string}>
     */
    private function rowsOf(Crawler $picker): array
    {
        return $picker->filter('.pick')->each(static fn (Crawler $row): array => [$row->filter('.pick__name')->text(), $row->filter('.pick__side')->text()]);
    }

    /**
     * @param list<string> $names
     */
    private function addShopMembers(array $names): void
    {
        foreach ($names as $name) {
            $this->people[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($this->people[$name], $this->acmeFoods);
            $this->addTeamRole($this->people[$name], $this->acmeFoods, $this->shop, 'member');
        }
    }

    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach (['wait' => ['Needs input', 'input'], 'check' => ['Code review', 'review'], 'doing' => ['In progress', 'progress'], 'todo' => ['To do', 'todo'], 'done' => ['Done', 'done']] as $groupCode => [$name, $coreCode]) {
            $this->steps[$groupCode] = Uuid::v7()->toRfc4122();
            $steps[] = [$this->steps[$groupCode], $name, $groupCode, $coreCode, \count($steps)];
        }
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $steps): int {
            foreach ($steps as $step) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                    [$organizationId, ...$step],
                );
            }

            return 0;
        });
    }

    /**
     * A bug of $project (Shop's when null) assigned to the person, of $team (Shop when null) and for everyone unless
     * $visibility says.
     */
    private function addTicket(string $groupCode, string $assignee, ?TeamId $team = null, string $visibility = 'all', string $reporter = 'Lena', ?string $project = null): void
    {
        $number = $this->nextNumber++;
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $project ?? $this->shopProject, ($team ?? $this->shop)->toString(), $number, 'SHOP-'.$number,
            $this->steps[$groupCode], $this->people[$reporter]->id()->toString(), $visibility, $this->people[$assignee]->id()->toString(),
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Checkout button is hidden', '', 'bug', 'normal', 3, ?, ?, ?, ?, '{}', '{}',
                 TIMESTAMPTZ '2027-03-15 09:00:00+00', TIMESTAMPTZ '2027-03-15 10:00:00+00')",
            $parameters,
        ));
    }
}
