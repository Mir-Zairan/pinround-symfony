<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\ReadsSentSql;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateTicket\ProjectHasNoTeam;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\UseCaseTicketCommands;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\NewTicketController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The new ticket form at /tickets/new on Acme Foods' team Shop through the real firewall, voters, session and database.
 * Test organizations have no core steps, so each test inserts Backlog and To do.
 */
final class NewTicketTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use ReadsSentSql;

    private const string NEW_TICKET = '/tickets/new';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        // The requests follow the wall clock for the firewall's idle limit, and a created ticket's history row needs
        // this month's partition, which the migrations made only through 2027-12.
        new AddTicketEventPartitions(self::getContainer()->get('doctrine.dbal.migrator_connection'), new NativeClock())();
    }

    protected function tearDown(): void
    {
        $this->removeCommittedTickets();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aMemberCreatesATicketAndLandsOnItsPageWithAToast(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Omar']);

        $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Make ticket', [
            'project_id' => $projectId,
            'title' => '  Checkout button does nothing ',
            'description' => 'Tapping Pay on the cart page shows no answer.',
            'type' => 'bug',
            'priority' => 'major',
        ]);

        self::assertResponseRedirects('/t/SHOP-1', 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Ticket SHOP-1 made.', $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame('Checkout button does nothing', $page->filter('h1.ticket__title')->text());
        self::assertSame([[
            'key' => 'SHOP-1',
            'title' => 'Checkout button does nothing',
            'type' => 'bug',
            'priority' => 'major',
            'assignee_id' => null,
            'reporter_id' => $people['Omar']->id()->toString(),
        ]], $this->committedTickets());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function creatorsWhoMayNotGiveTickets(): iterable
    {
        yield 'Omar, a member of the team' => ['Omar'];
        yield 'Mia, a viewer of the team' => ['Mia'];
    }

    #[Test]
    #[DataProvider('creatorsWhoMayNotGiveTickets')]
    public function aCreatorWhoIsNotTheLeadSeesNoGiveItToAndAForgedAssigneeIsDenied(string $person): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people[$person]);

        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $form->filter('select[name="assignee_id"]'));
        self::assertStringNotContainsString('Give it to', $form->filter('form')->text());

        $this->postTicket($projectId, ['assignee_id' => $people['Omar']->id()->toString()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->committedTickets());

        $this->postTicket($projectId);
        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['SHOP-1'], array_column($this->committedTickets(), 'key'));
    }

    #[Test]
    public function bugAndNormalArePreselectedAndATicketWithNoOneStartsInBacklogAtTheTimeOfTheRequest(): void
    {
        $this->client->disableReboot();
        // An hour back, not a fixed date: the firewall ends a session that seems idle for longer than its limit.
        $requestTime = time() - 3600;
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable('@'.$requestTime)));
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Omar']);

        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['bug'], $form->filter('input[name="type"][checked]')->extract(['value']));
        self::assertSame(['normal'], $form->filter('input[name="priority"][checked]')->extract(['value']));
        $this->client->submitForm('Make ticket', ['title' => 'Search shows no results']);

        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame([[
            'key' => 'SHOP-1',
            'title' => 'Search shows no results',
            'type' => 'bug',
            'priority' => 'normal',
            'assignee_id' => null,
            'reporter_id' => $people['Omar']->id()->toString(),
        ]], $this->committedTickets());
        self::assertSame(['SHOP-1' => ['backlog', $requestTime]], $this->coreStepsAndCreationTimes());
    }

    #[Test]
    public function theLeadIsOfferedOnlyThePeopleOfTheTeamsSheLeadsAndATicketSheGivesToOmarStartsInToDo(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->addProject($this->mobile, 'APP', 'Mobile app');
        $stock = $this->createTeam($this->acmeFoods, 'Stock', $people['Omar']);
        $this->addTeamRole($people['Lena'], $this->acmeFoods, $stock, 'member');
        $this->addTeamRole($people['Raj'], $this->acmeFoods, $stock, 'member');
        $stockProjectId = $this->addProject($stock, 'STOCK', 'Stock');
        $this->logIn($this->client, $people['Lena']);

        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        self::assertContains($stockProjectId, $form->filter('select[name="project_id"] option')->extract(['value']));
        self::assertSame(['Mobile', 'Shop'], $form->filter('select[name="assignee_id"] optgroup')->extract(['label']));
        $offeredAssigneeIds = $form->filter('select[name="assignee_id"] optgroup option')->extract(['value']);
        self::assertContains($people['Omar']->id()->toString(), $offeredAssigneeIds);
        self::assertNotContains($people['Raj']->id()->toString(), $offeredAssigneeIds);
        $this->client->submitForm('Make ticket', ['project_id' => $projectId, 'title' => 'Search shows no results', 'assignee_id' => $people['Omar']->id()->toString()]);

        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame($people['Omar']->id()->toString(), $this->committedTickets()[0]['assignee_id']);
        $stepsAndTimes = $this->coreStepsAndCreationTimes();
        self::assertSame(['SHOP-1'], array_keys($stepsAndTimes));
        self::assertSame('todo', $stepsAndTimes['SHOP-1'][0]);
    }

    #[Test]
    public function aLeadWhoGivesATicketInATeamSheOnlyBelongsToGetsAFieldErrorAndKeepsHerInput(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $this->addProject($this->shop, 'SHOP', 'Shop web');
        $stock = $this->createTeam($this->acmeFoods, 'Stock', $people['Omar']);
        $this->addTeamRole($people['Lena'], $this->acmeFoods, $stock, 'member');
        $stockProjectId = $this->addProject($stock, 'STOCK', 'Stock');
        $this->logIn($this->client, $people['Lena']);

        $page = $this->postTicket($stockProjectId, ['title' => 'Stock count is off by one', 'assignee_id' => $people['Omar']->id()->toString()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(NewTicketController::ASSIGNEE_NOT_LED, $page->filter('#ticket-assignee-error')->text());
        self::assertSame('Stock count is off by one', $page->filter('input[name="title"]')->attr('value'));
        self::assertSame([], $this->committedTickets());
    }

    #[Test]
    public function aViewerOfTheTeamOpensTheFormAndCreatesATicket(): void
    {
        $people = $this->createAcmeFoodsPeople('viewer');
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Mia']);

        $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Make ticket', ['project_id' => $projectId, 'title' => 'Prices show no currency']);

        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['SHOP-1'], array_column($this->committedTickets(), 'key'));
        self::assertSame($people['Mia']->id()->toString(), $this->committedTickets()[0]['reporter_id']);
    }

    #[Test]
    public function aProjectWithNoTeamIsNotOfferedAndAPostWithItsIdIsDenied(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $shopProjectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $teamlessProjectId = $this->addProject(null, 'STOCK', 'Stock');
        $this->logIn($this->client, $people['Omar']);

        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$shopProjectId], $form->filter('select[name="project_id"] option')->extract(['value']));

        $this->postTicket($teamlessProjectId);
        self::assertResponseStatusCodeSame(403);
        $this->postTicket(Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->committedTickets());
    }

    /**
     * @return iterable<string, array{\DomainException, int}>
     */
    public static function refusalsAfterTheChoicesWereRead(): iterable
    {
        yield 'the project lost its team' => [new ProjectHasNoTeam('That project has no team, so it takes no tickets.'), 403];
        yield 'the project was removed' => [new ProjectNotFound('That project does not exist.'), 403];
        yield 'any other refusal is not taken for a denial' => [new \DomainException('Something else went wrong.'), 500];
    }

    #[Test]
    #[DataProvider('refusalsAfterTheChoicesWereRead')]
    public function aProjectThatLosesItsTeamOrIsRemovedBeforeTheTicketIsSavedIsDenied(\DomainException $refusal, int $statusCode): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(UseCaseTicketCommands::class, new readonly class($refusal) implements TicketCommands {
            public function __construct(private \DomainException $refusal)
            {
            }

            public function create(OrganizationId $organizationId, UserId $reporter, string $projectId, string $title, string $description, string $type, string $priority, ?UserId $assignee, \DateTimeImmutable $reportedAt): string
            {
                throw $this->refusal;
            }

            public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at): void
            {
                throw new \LogicException('The new ticket page moves no ticket.');
            }

            public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): void
            {
                throw new \LogicException('The new ticket page gives no ticket.');
            }

            public function moveTo(OrganizationId $organizationId, UserId $actor, string $key, string $stepId, int $expectedVersion, ?UserId $waitingOnUserId, ?string $waitingOnLabel, \DateTimeImmutable $at): void
            {
                throw new \LogicException('The new ticket page moves no ticket.');
            }
        });
        $people = $this->createAcmeFoodsPeople();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Omar']);

        $this->postTicket($projectId);

        self::assertResponseStatusCodeSame($statusCode);
        self::assertSame([], $this->committedTickets());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayNotTakeTheTicket(): iterable
    {
        yield 'Mia, a viewer of the team' => ['Mia'];
        yield 'Raj, a member of another team' => ['Raj'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotTakeTheTicket')]
    public function aTicketGivenToSomeoneWhoMayNotTakeItIsRefusedAndKeepsItsNumber(string $person): void
    {
        $people = $this->createAcmeFoodsPeople('viewer');
        $this->addTeamRole($people['Raj'], $this->acmeFoods, $this->mobile, 'member');
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Lena']);

        $page = $this->postTicket($projectId, ['assignee_id' => $people[$person]->id()->toString()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(NewTicketController::ASSIGNEE_NOT_IN_TEAM, $page->filter('#ticket-assignee-error')->text());
        self::assertSame([], $this->committedTickets());

        $this->postTicket($projectId);
        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['SHOP-1'], array_column($this->committedTickets(), 'key'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string, string}>
     */
    public static function refusedFields(): iterable
    {
        yield 'a blank title' => [['title' => '   '], '#ticket-title-error', NewTicketController::TITLE_MISSING, 'Omar'];
        yield 'a title of 201 characters' => [['title' => str_repeat('é', 201)], '#ticket-title-error', NewTicketController::TITLE_OVER_LIMIT, 'Omar'];
        yield 'a description of 50,001 characters' => [['description' => str_repeat('é', 50_001)], '#ticket-description-error', NewTicketController::DESCRIPTION_OVER_LIMIT, 'Omar'];
        yield 'the type epic' => [['type' => 'epic'], '#ticket-type-error', NewTicketController::TYPE_UNKNOWN, 'Omar'];
        yield 'the priority urgent' => [['priority' => 'urgent'], '#ticket-priority-error', NewTicketController::PRIORITY_UNKNOWN, 'Omar'];
        yield 'an assignee that is no id, from the lead' => [['assignee_id' => 'not-a-uuid'], '#ticket-assignee-error', NewTicketController::ASSIGNEE_NOT_IN_TEAM, 'Lena'];
    }

    /**
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('refusedFields')]
    public function aRefusedFieldShowsItsErrorAndUsesUpNoNumber(array $fields, string $errorSelector, string $error, string $person): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people[$person]);

        $page = $this->postTicket($projectId, $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($error, $page->filter($errorSelector)->text());
        self::assertSame([], $this->committedTickets());

        $this->postTicket($projectId);
        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['SHOP-1'], array_column($this->committedTickets(), 'key'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function refusedCsrfTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'a wrong token' => ['not-the-token'];
    }

    #[Test]
    #[DataProvider('refusedCsrfTokens')]
    public function aPostWithoutAValidCsrfTokenIsRefused(?string $csrfToken): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Omar']);

        $fields = $this->ticketFields($projectId);
        unset($fields[NewTicketController::CSRF_FIELD]);
        if (null !== $csrfToken) {
            $fields[NewTicketController::CSRF_FIELD] = $csrfToken;
        }
        $page = $this->client->request('POST', self::NEW_TICKET, $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(NewTicketController::FORM_EXPIRED, $page->filter('[role="alert"]')->text());
        self::assertSame([], $this->committedTickets());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleOfNoTeam(): iterable
    {
        yield 'Raj, a Manager of no team' => ['Raj'];
        yield 'Sara, an Admin of no team' => ['Sara'];
        yield 'a person of another organization' => ['other organization'];
    }

    #[Test]
    #[DataProvider('peopleOfNoTeam')]
    public function aPersonOfNoTeamWithAProjectIsDeniedTheFormAndTheCreate(string $person): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people[$person] ?? $this->personOfBetaBakery()[0]);

        $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(403);
        $this->postTicket($projectId);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->committedTickets());
    }

    #[Test]
    public function aMemberOfATeamInAnotherOrganizationIsOfferedOnlyTheirProjectsAndDeniedOurs(): void
    {
        $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $shopProjectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        [$betaPerson, $betaBakery] = $this->personOfBetaBakery();
        $ovens = $this->createTeam($betaBakery, 'Ovens', $betaPerson);
        $bakeProjectId = $this->addProject($ovens, 'BAKE', 'Bakery web', $betaBakery);
        $this->logIn($this->client, $betaPerson);

        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$bakeProjectId], $form->filter('select[name="project_id"] option')->extract(['value']));
        $this->postTicket($shopProjectId);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->committedTickets());
    }

    #[Test]
    public function theFormReadsAsManyQueriesForThreeProjectsAsForOne(): void
    {
        $this->client->disableReboot();
        $people = $this->createAcmeFoodsPeople();
        $this->addTeamRole($people['Raj'], $this->acmeFoods, $this->mobile, 'member');
        $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Lena']);
        $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);

        $queriesForOne = $this->queryCountOfTheForm();
        $this->addProject($this->mobile, 'APP', 'Mobile app');
        $this->addProject($this->shop, 'CART', 'Checkout');
        $queriesForThree = $this->queryCountOfTheForm();

        self::assertSame($queriesForOne, $queriesForThree);
    }

    private function queryCountOfTheForm(): int
    {
        $debugData = $this->debugData();
        $debugData->reset();
        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        self::assertGreaterThan(0, $form->filter('select[name="assignee_id"] optgroup option')->count());

        return self::queryCountOf($debugData);
    }

    /**
     * Posts the form with a valid CSRF token, Bug, Normal, a title and no one to give it to, unless $fields says else.
     *
     * @param array<string, string> $fields
     */
    private function postTicket(string $projectId, array $fields = []): Crawler
    {
        return $this->client->request('POST', self::NEW_TICKET, [...$this->ticketFields($projectId), ...$fields]);
    }

    /**
     * @return array<string, string>
     */
    private function ticketFields(string $projectId): array
    {
        return [
            NewTicketController::CSRF_FIELD => $this->csrfTokenFor($this->client, NewTicketController::CSRF_ID),
            NewTicketController::PROJECT_FIELD => $projectId,
            NewTicketController::TITLE_FIELD => 'Search shows no results',
            NewTicketController::DESCRIPTION_FIELD => '',
            NewTicketController::TYPE_FIELD => 'bug',
            NewTicketController::PRIORITY_FIELD => 'normal',
            NewTicketController::ASSIGNEE_FIELD => '',
        ];
    }

    /**
     * Omar of Beta Bakery, an organization of its own, with no team there yet.
     *
     * @return array{User, OrganizationId} the person and Beta Bakery
     */
    private function personOfBetaBakery(): array
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $person = $this->createUserWithoutOrganization('Omar');
        $this->addMembership($person, $betaBakery);

        return [$person, $betaBakery];
    }

    /**
     * Backlog and To do as core steps of Acme Foods, which CreateTicket needs.
     */
    private function addCoreSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId): int {
            foreach ([['Backlog', 'backlog', 0], ['To do', 'todo', 1]] as [$name, $coreCode, $position]) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)",
                    [$organizationId, Uuid::v7()->toRfc4122(), $name, $coreCode, $position],
                );
            }

            return 0;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function committedTickets(): array
    {
        $tickets = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$tickets): int {
            $tickets = $migrator->fetchAllAssociative(
                'SELECT key, title, type, priority, assignee_id, reporter_id FROM tkt_ticket WHERE organization_id = ? ORDER BY number',
                [$organizationId],
            );

            return 0;
        });

        return $tickets;
    }

    /**
     * @return array<string, array{string, int}> the core code of each ticket's step and its creation time in Unix seconds, by key
     */
    private function coreStepsAndCreationTimes(): array
    {
        $rows = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociative(
                'SELECT t.key, s.core_code, EXTRACT(EPOCH FROM t.created_at)::BIGINT AS created_at FROM tkt_ticket t'
                .' JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id WHERE t.organization_id = ? ORDER BY t.number',
                [$organizationId],
            );

            return 0;
        });

        $stepsAndTimes = [];
        foreach ($rows as ['key' => $key, 'core_code' => $coreCode, 'created_at' => $createdAt]) {
            self::assertIsString($key);
            self::assertIsString($coreCode);
            self::assertIsInt($createdAt);
            $stepsAndTimes[$key] = [$coreCode, $createdAt];
        }

        return $stepsAndTimes;
    }

    /**
     * Through the migrator, as the app may not delete tickets, their history or steps; the ticket's turn row goes with it.
     */
    private function removeCommittedTickets(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM tkt_ticket_event WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
    }
}
