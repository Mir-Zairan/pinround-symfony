<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Api\CurrentUser;
use App\Identity\Api\Event\UserRenamed;
use App\Identity\Domain\User;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\MembershipQueries;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Job;
use App\Organization\Domain\TeamRole;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\Organization\Infrastructure\Messenger\MessengerOrganizationEvents;
use App\Organization\Infrastructure\Security\RequestScopedCurrentActor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\UI\Component\TicketFilters;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tickets list's toolbar, TicketFilters, through the real firewall and database: the page renders it, and its
 * live requests change the filters and send the list's new URL. Lena leads Shop with Omar as member and Mia as viewer.
 */
final class TicketFiltersTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use InteractsWithLiveComponents;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RunsTheWorkerUntilIdle;

    private const string TICKETS = '/tickets';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->people = $this->createAcmeFoodsPeople();
    }

    protected function tearDown(): void
    {
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theToolbarHasTheSearchFieldThePeopleChipsAndShowDone(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        $field = $page->filter('.ticket-filters form[method="get"] input[name="q"]');
        self::assertSame(['Search by key or words in the title', 'debounce(300)|q', ''], [$field->attr('placeholder'), $field->attr('data-model'), $field->attr('value')]);
        self::assertSame('Assigned to', $page->filter('.ticket-filters__label')->text());
        self::assertSame('Show done', $page->filter('[data-done-chip]')->text());
        self::assertSame('/tickets?done=1', $page->filter('[data-done-chip]')->attr('href'));
        self::assertSame(['Everyone'], $page->filter('.ticket-filters a.chip[aria-current="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    /**
     * Shop has four people who can be assignees (Mia, its viewer, is not one), so each other one is a chip, in id
     * order, and the viewer is "Me".
     */
    #[Test]
    public function aTeamOfFourShowsEachOtherPersonByFirstName(): void
    {
        $this->addShopMembers(['Tom', 'Eva']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me', 'Omar', 'Tom', 'Eva'], $this->peopleChipLabelsOf($page));
        self::assertSame(self::TICKETS.'?person='.$this->people['Tom']->id()->toString(), $page->filter('[data-person-chip="'.$this->people['Tom']->id()->toString().'"]')->attr('href'));
        self::assertCount(0, $page->filter('[data-person-picker-trigger]'));
    }

    /**
     * The second load reads Shop's people from the cache; Tom joining through SetTeamRole clears it when it commits.
     */
    #[Test]
    public function aPersonWhoJoinsTheTeamIsOnTheNextLoad(): void
    {
        $this->client->disableReboot();
        $this->people['Tom'] = $this->createUserWithoutOrganization('Tom');
        $this->addMembership($this->people['Tom'], $this->acmeFoods);
        $this->logIn($this->client, $this->people['Lena']);
        self::assertSame(['Everyone', 'Me', 'Omar'], $this->peopleChipLabelsOnTheTicketsPage());
        self::assertSame(['Everyone', 'Me', 'Omar'], $this->peopleChipLabelsOnTheTicketsPage());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $setTeamRole = new SetTeamRole(new DoctrineTeams($entityManager), new DoctrineMemberships($entityManager), self::getContainer()->get(MessengerOrganizationEvents::class));
        new TenantTransactions($entityManager, self::getContainer()->get(TenantContext::class))->committedAs(
            $this->acmeFoods->toString(),
            fn () => $setTeamRole($this->shop, $this->people['Tom']->id(), TeamRole::Member, Job::Developer, $this->people['Lena']->id(), new \DateTimeImmutable('2026-03-04 10:00:00 UTC')),
        );

        self::assertSame(['Everyone', 'Me', 'Omar', 'Tom'], $this->peopleChipLabelsOnTheTicketsPage());
    }

    /**
     * Omar saves a new name on his profile page, which has no organization; Lena's cached Shop shows it once the relay has
     * moved UserRenamed from the outbox and worker-notify has handled it.
     */
    #[Test]
    public function aNewNameIsOnTheFirstLoadAfterTheRelayedRenameIsHandled(): void
    {
        $this->client->disableReboot();
        $this->logIn($this->client, $this->people['Lena']);
        self::assertSame(['Everyone', 'Me', 'Omar'], $this->peopleChipLabelsOnTheTicketsPage());
        self::assertSame(['Everyone', 'Me', 'Omar'], $this->peopleChipLabelsOnTheTicketsPage());

        // Only the rename is relayed and handled below, not the events of setting up the teams.
        self::getContainer()->get('doctrine.dbal.default_connection')->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('GET', '/me/profile');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Save', ['name' => 'Oskar Lind', 'time_zone' => 'Europe/Berlin', 'theme' => 'system']);
        self::assertResponseStatusCodeSame(302);

        $this->logIn($this->client, $this->people['Lena']);
        self::assertSame(['Everyone', 'Me', 'Omar'], $this->peopleChipLabelsOnTheTicketsPage(), 'The old name shows until the relayed event is handled.');

        self::runTheWorkerUntilIdle('outbox');
        $asyncNotify = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $asyncNotify);
        self::assertSame([UserRenamed::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $asyncNotify->getSent()));
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(['Everyone', 'Me', 'Oskar'], $this->peopleChipLabelsOnTheTicketsPage());
    }

    #[Test]
    public function aTeamOfFiveShowsThePersonChipInsteadOfNames(): void
    {
        $this->addShopMembers(['Tom', 'Eva', 'Ana']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me', 'Person ▾'], $this->peopleChipLabelsOf($page));
        self::assertSame('false', $page->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
    }

    #[Test]
    public function aTeamOfFiveShowsThePickedPersonOnThePersonChip(): void
    {
        $this->addShopMembers(['Tom', 'Eva', 'Ana']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?person='.$this->people['Eva']->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me', 'Eva'], $this->peopleChipLabelsOf($page));
        self::assertSame('true', $page->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
        self::assertNull($page->filter('[data-person-chip="everyone"]')->attr('aria-current'));
    }

    /**
     * Raj, a manager of no team, is in the organization but not in Shop: his name is never looked up from the URL.
     */
    #[Test]
    public function aTeamOfFiveNamesNoOneOutsideTheTeamOnThePersonChip(): void
    {
        $this->addShopMembers(['Tom', 'Eva', 'Ana']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?person='.$this->people['Raj']->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me', 'Person ▾'], $this->peopleChipLabelsOf($page));
        self::assertSame('true', $page->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
        self::assertSame([], $page->filter('.ticket-filters a.chip[aria-current="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    #[Test]
    public function aTeamOfFivePressesMeNotThePersonChipForTheViewer(): void
    {
        $this->addShopMembers(['Tom', 'Eva', 'Ana']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?person='.$this->people['Lena']->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Me'], $page->filter('.ticket-filters a.chip[aria-current="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame('false', $page->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
    }

    /**
     * Raj is in no team, so neither Lena's chips nor his own toolbar name anyone: the filter still shows as pressed.
     */
    #[Test]
    public function aPersonNoChipNamesShowsAsAPressedPersonChip(): void
    {
        $raj = $this->people['Raj']->id()->toString();
        $this->logIn($this->client, $this->people['Lena']);

        $lenasPage = $this->client->request('GET', self::TICKETS.'?person='.$raj);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me', 'Omar', 'Person ▾'], $this->peopleChipLabelsOf($lenasPage));
        self::assertSame('true', $lenasPage->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
        self::assertNull($lenasPage->filter('[data-person-chip="everyone"]')->attr('aria-current'));

        $this->logIn($this->client, $this->people['Raj']);
        $rajsPage = $this->client->request('GET', self::TICKETS.'?person='.$this->people['Omar']->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Person ▾'], $this->peopleChipLabelsOf($rajsPage));
        self::assertSame('true', $rajsPage->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
    }

    #[Test]
    public function meIsShownOnlyToALeadOrMemberOfTheTeam(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $omarsPage = $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Me', 'Lena'], $this->peopleChipLabelsOf($omarsPage));

        $this->logIn($this->client, $this->people['Mia']);
        $miasPage = $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone', 'Lena', 'Omar'], $this->peopleChipLabelsOf($miasPage));

        $this->logIn($this->client, $this->people['Raj']);
        $rajsPage = $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Everyone'], $this->peopleChipLabelsOf($rajsPage));
    }

    #[Test]
    public function aChipChangesThePersonAndSendsTheListUrlForANewHistoryEntry(): void
    {
        $omar = $this->people['Omar']->id()->toString();
        $toolbar = $this->toolbarAs($this->people['Lena'], ['showsDone' => true, 'q' => ' checkout ']);
        self::assertSame(['Everyone'], $toolbar->render()->crawler()->filter('.ticket-filters__people a.chip[aria-current="true"]')->each(static fn (Crawler $chip): string => $chip->text()));

        $toolbar->call('filterByPerson', ['person' => $omar]);

        self::assertSame(200, $toolbar->response()->getStatusCode());
        self::assertSame(['Omar'], $toolbar->render()->crawler()->filter('.ticket-filters__people a.chip[aria-current="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TICKETS.'?person='.$omar.'&done=1&q=checkout', 'addsHistoryEntry' => true]);

        $toolbar->call('filterByPerson', ['person' => '']);

        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TICKETS.'?done=1&q=checkout', 'addsHistoryEntry' => true]);
    }

    #[Test]
    public function showDoneTogglesDone(): void
    {
        $toolbar = $this->toolbarAs($this->people['Lena']);

        $toolbar->call('toggleDone');

        self::assertSame('true', $toolbar->render()->crawler()->filter('[data-done-chip]')->attr('aria-current'));
        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TICKETS.'?done=1', 'addsHistoryEntry' => true]);

        $toolbar->call('toggleDone');

        self::assertNull($toolbar->render()->crawler()->filter('[data-done-chip]')->attr('aria-current'));
        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TICKETS, 'addsHistoryEntry' => true]);
    }

    #[Test]
    public function typingASearchSendsTheTrimmedSearchAndReplacesTheHistoryEntry(): void
    {
        $toolbar = $this->toolbarAs($this->people['Lena'], ['person' => $this->people['Omar']->id()->toString()]);

        $toolbar->set('q', '  gift note ');

        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TICKETS.'?person='.$this->people['Omar']->id()->toString().'&q=gift%20note', 'addsHistoryEntry' => false]);
        $form = $toolbar->render()->crawler()->filter('form[method="get"]');
        self::assertSame(['person' => $this->people['Omar']->id()->toString()], array_combine(
            $form->filter('input[type="hidden"]')->each(static fn (Crawler $input): string => (string) $input->attr('name')),
            $form->filter('input[type="hidden"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')),
        ));
    }

    #[Test]
    public function aTooLongSearchIsShownAsRefusedAndTheListIsNotReloaded(): void
    {
        $toolbar = $this->toolbarAs($this->people['Lena']);

        $toolbar->set('q', str_repeat('é', 201));

        self::assertSame(200, $toolbar->response()->getStatusCode());
        $this->assertComponentNotDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT);
        self::assertSame('The search is too long. Use 200 characters or fewer.', $toolbar->render()->crawler()->filter('[role="alert"]')->text());
    }

    #[Test]
    public function aMalformedPersonInAChipActionIsABadRequest(): void
    {
        $toolbar = $this->toolbarAs($this->people['Lena']);
        self::assertSame('', $this->livePropsOf($toolbar->render()->crawler())['person'] ?? null);
        $this->client->catchExceptions(true);

        $toolbar->call('filterByPerson', ['person' => 'nobody']);

        $response = $toolbar->response();
        self::assertSame(400, $response->getStatusCode());
        $this->assertRefusalCarriesNoNewFilters($response, 'That person is not valid.');
    }

    /**
     * A refused live request answers with the error page alone: no props, so the page keeps the filters it had, and no
     * browser event, so the list is not reloaded.
     */
    private function assertRefusalCarriesNoNewFilters(Response $response, string $refusal): void
    {
        $errorPage = new Crawler((string) $response->getContent());
        self::assertSame($refusal, $errorPage->filter('h1.exception-message')->text());
        self::assertCount(0, $errorPage->filter('[data-live-props-value]'));
        self::assertCount(0, $errorPage->filter('[data-live-events-to-dispatch-value]'));
    }

    /**
     * @return array<mixed>
     */
    private function livePropsOf(Crawler $component): array
    {
        $props = json_decode((string) $component->filter('[data-live-props-value]')->attr('data-live-props-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);

        return $props;
    }

    /**
     * The test helper mounts the component in the test process and sends its props as an anonymous visitor would.
     */
    #[Test]
    public function anAnonymousVisitorIsSentToLoginAndGetsNoToolbar(): void
    {
        $toolbar = $this->createLiveComponent('TicketFilters', ['q' => 'checkout'], $this->client);
        $this->client->catchExceptions(true);

        $response = $toolbar->response();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('ticket-filters', (string) $response->getContent());
    }

    /**
     * Zoe has an account but no organization: she is sent to pick one before the toolbar renders anything.
     */
    #[Test]
    public function aPersonOfNoOrganizationGetsNoToolbar(): void
    {
        $toolbar = $this->toolbarAs($this->createUserWithoutOrganization('Zoe'));
        $this->client->catchExceptions(true);

        $response = $toolbar->response();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringNotContainsString('ticket-filters', (string) $response->getContent());
    }

    /**
     * After the toolbar renders, Lena's actor turns out to be of another organization than the request's, so
     * OrganizationVoter refuses her VIEW: the action is refused before it runs, with no toolbar and no new list URL.
     */
    #[Test]
    public function aLiveActionWithoutTheListPermissionIsRefusedBeforeItRuns(): void
    {
        $this->client->disableReboot();
        $realActors = new RequestScopedCurrentActor(
            self::getContainer()->get(CurrentUser::class),
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(MembershipQueries::class),
        );
        $actors = new class($realActors) implements CurrentActor, ResetInterface {
            public bool $isOfAnotherOrganization = false;

            public function __construct(private readonly RequestScopedCurrentActor $realActors)
            {
            }

            public function reset(): void
            {
                $this->realActors->reset();
            }

            public function actorFor(mixed $tokenUser): ?ActorContext
            {
                $actor = $this->realActors->actorFor($tokenUser);
                if (null === $actor || !$this->isOfAnotherOrganization) {
                    return $actor;
                }

                return new ActorContext($actor->userId(), new OrganizationId('0197a3c0-0000-7000-8fff-000000000001'), $actor->roles());
            }
        };
        self::getContainer()->set(CurrentActor::class, $actors);
        $toolbar = $this->toolbarAs($this->people['Lena']);
        self::assertNull($toolbar->render()->crawler()->filter('[data-done-chip]')->attr('aria-current'));
        $actors->isOfAnotherOrganization = true;
        $this->client->catchExceptions(true);

        $toolbar->call('toggleDone');

        $response = $toolbar->response();
        self::assertSame(403, $response->getStatusCode());
        $this->assertRefusalCarriesNoNewFilters($response, 'The tickets list is not open to this person here.');
    }

    /**
     * The request channel's "Matched route" record carries the request URI with the search; RedactionProcessor, last
     * on every channel, drops it, so no line written for a list request holds the search words (ADR-0027, ADR-0034).
     */
    #[Test]
    public function aLoggedListRequestHoldsNoSearchWords(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $capturedLogRecords->clear();
        $searchWords = 'secret-'.bin2hex(random_bytes(6));

        $this->client->request('GET', self::TICKETS.'?q='.$searchWords);

        self::assertResponseStatusCodeSame(200);
        $records = $capturedLogRecords->all();
        $uris = array_filter(array_map(static fn (LogRecord $record): mixed => $record->context['request_uri'] ?? null, $records), is_string(...));
        self::assertNotSame([], array_filter($uris, static fn (string $uri): bool => str_contains($uri, $searchWords)));
        $redaction = new RedactionProcessor();
        $formatter = new JsonFormatter();
        foreach ($records as $record) {
            self::assertStringNotContainsString($searchWords, $formatter->format($redaction($record)));
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    private function toolbarAs(User $user, array $props = []): TestLiveComponent
    {
        $this->logIn($this->client, $user);

        return $this->createLiveComponent('TicketFilters', $props, $this->client);
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

    /**
     * @return list<string>
     */
    private function peopleChipLabelsOnTheTicketsPage(): array
    {
        $page = $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);

        return $this->peopleChipLabelsOf($page);
    }

    /**
     * @return list<string>
     */
    private function peopleChipLabelsOf(Crawler $page): array
    {
        return $page->filter('.ticket-filters__people .chip')->each(static fn (Crawler $chip): string => $chip->text());
    }
}
