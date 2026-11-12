<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\Component\TicketFilters;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
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
        self::assertSame('false', $page->filter('[data-person-picker-trigger]')->attr('aria-pressed'));
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
        $toolbar->render();

        $this->expectException(BadRequestHttpException::class);

        $toolbar->call('filterByPerson', ['person' => 'nobody']);
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
     * The hook every live request runs before an action: with no tenant, or a tenant but no one logged in, the list's
     * permission is refused, and the filters stay as they were.
     */
    #[Test]
    public function aLiveRequestWithoutTheListPermissionIsRefusedBeforeAnyAction(): void
    {
        $toolbar = self::getContainer()->get(TicketFilters::class);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();

        foreach ([null, $this->acmeFoods] as $organizationId) {
            if (null !== $organizationId) {
                $tenantContext->set($organizationId);
            }
            try {
                $toolbar->denyUnlessListIsOpen();
                self::fail('The toolbar answered without the list permission.');
            } catch (AccessDeniedException $refusal) {
                self::assertSame('The tickets list is not open to this person here.', $refusal->getMessage());
            }
        }
        self::assertSame(['', false], [$toolbar->person, $toolbar->showsDone]);
        $tenantContext->reset();
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
    private function peopleChipLabelsOf(Crawler $page): array
    {
        return $page->filter('.ticket-filters__people .chip')->each(static fn (Crawler $chip): string => $chip->text());
    }
}
