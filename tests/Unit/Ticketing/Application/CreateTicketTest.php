<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Application\CreateTicket\AssigneeNotInTeam;
use App\Ticketing\Application\CreateTicket\CreateTicket;
use App\Ticketing\Application\CreateTicket\ProjectHasNoTeam;
use App\Ticketing\Application\CreateTicket\TicketDescriptionOverLimit;
use App\Ticketing\Application\CreateTicket\TicketTitleMissing;
use App\Ticketing\Application\CreateTicket\TicketTitleOverLimit;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Domain\BlankTicketTitle;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\TicketDescriptionTooLong;
use App\Ticketing\Domain\TicketTitleTooLong;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
use App\Workflow\Api\StepView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateTicketTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000031a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000031b1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000031c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000031d1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000031e1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000031f1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000031f2';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000031f3';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000031f4';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000031f5';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000031c2';
    private const string BACKLOG_STEP = '0197a3c0-0000-7000-8000-000000003101';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-000000003102';
    private const string TEAM_TRIAGE_STEP = '0197a3c0-0000-7000-8000-000000003103';
    private const string REPORTED_AT = '2027-03-15 10:00';

    private RecordedTicketWrites $writes;
    private FixedNextTicketNumber $nextTicketNumber;

    protected function setUp(): void
    {
        $this->writes = new RecordedTicketWrites(self::TICKET);
        $this->nextTicketNumber = new FixedNextTicketNumber(12);
    }

    #[Test]
    public function aTicketWithAnAssigneeStartsInToDoAndIsSavedRecordedProjectedAndPublishedInThatOrder(): void
    {
        $key = $this->createTicket(self::shopProject(), new UserId(self::OMAR));

        self::assertSame('SHOP-12', $key);
        self::assertSame(
            [
                'ticket '.self::TICKET,
                \sprintf('created %s by %s in %s at %s', self::TICKET, self::LENA, self::TO_DO_STEP, self::REPORTED_AT),
                'turn '.self::TICKET,
                'event '.TicketCreated::class,
            ],
            $this->writes->writes,
        );
        $ticket = $this->writes->addedTickets[0];
        self::assertSame(
            [self::TO_DO_STEP, self::OMAR, self::SHOP_TEAM, self::LENA, TicketType::Story, Priority::Major, Visibility::All, 'Checkout button is hidden'],
            [$ticket->stepId(), $ticket->assignee()?->toString(), $ticket->team()?->toString(), $ticket->reporter()->toString(), $ticket->type(), $ticket->priority(), $ticket->visibility(), $ticket->title()],
        );
        self::assertSame([self::SHOP_PROJECT], $this->nextTicketNumber->takenFor);
    }

    #[Test]
    public function aTicketWithoutAnAssigneeStartsInBacklog(): void
    {
        $this->createTicket(self::shopProject(), null);

        self::assertSame(self::BACKLOG_STEP, $this->writes->addedTickets[0]->stepId());
        self::assertNull($this->writes->addedTickets[0]->assignee());
    }

    #[Test]
    public function ticketCreatedCarriesTheIdsOfTheTicketAndNoText(): void
    {
        $this->createTicket(self::shopProject(), new UserId(self::OMAR));

        self::assertEquals(
            [new TicketCreated(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::SHOP_TEAM, self::LENA, self::OMAR)],
            $this->writes->publishedEvents,
        );
        self::assertSame(
            ['organizationId', 'ticketId', 'projectId', 'teamId', 'reporterId', 'assigneeId'],
            array_keys(get_object_vars($this->writes->publishedEvents[0])),
        );
    }

    #[Test]
    public function aProjectWithoutATeamIsRefusedBeforeANumberIsTaken(): void
    {
        $shop = self::shopProject();
        $shop->unlink();

        try {
            $this->createTicket($shop, new UserId(self::OMAR));
            self::fail('A project with no team took a ticket.');
        } catch (ProjectHasNoTeam $noTeam) {
            self::assertSame('That project has no team, so it takes no tickets.', $noTeam->getMessage());
        }

        self::assertSame([], $this->nextTicketNumber->takenFor);
        self::assertSame([], $this->writes->writes);
    }

    #[Test]
    public function aProjectOfAnotherOrganizationIsNotFound(): void
    {
        $betaProject = new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::BETA_BAKERY), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');

        $this->expectException(ProjectNotFound::class);
        try {
            $this->createTicket($betaProject, null);
        } finally {
            self::assertSame([], $this->nextTicketNumber->takenFor);
            self::assertSame([], $this->writes->writes);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assigneesOutsideTheShopTeam(): iterable
    {
        yield 'a user of another organization, who has no roles in this one' => [self::BEN];
        yield 'a member of another team only' => [self::MIA];
        yield 'a viewer of the team' => [self::RAJ];
    }

    #[Test]
    #[DataProvider('assigneesOutsideTheShopTeam')]
    public function anAssigneeOutsideTheProjectsTeamIsRefusedBeforeANumberIsTaken(string $assigneeId): void
    {
        try {
            $this->createTicket(self::shopProject(), new UserId($assigneeId));
            self::fail('A ticket was given to someone outside the project\'s team.');
        } catch (AssigneeNotInTeam $refused) {
            self::assertSame('That person is not a lead or member of the project\'s team, so they cannot take the ticket.', $refused->getMessage());
        }

        self::assertSame([], $this->nextTicketNumber->takenFor);
        self::assertSame([], $this->writes->writes);
    }

    #[Test]
    public function theTeamsLeadMayBeTheAssignee(): void
    {
        $this->createTicket(self::shopProject(), new UserId(self::LENA));

        self::assertSame(self::LENA, $this->writes->addedTickets[0]->assignee()?->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownTypesAndPriorities(): iterable
    {
        yield 'an unknown type' => ['epic', 'major'];
        yield 'an unknown priority' => ['story', 'urgent'];
    }

    #[Test]
    #[DataProvider('unknownTypesAndPriorities')]
    public function anUnknownTypeOrPriorityIsRefusedBeforeANumberIsTaken(string $type, string $priority): void
    {
        try {
            $this->createTicket(self::shopProject(), null, $type, $priority);
            self::fail('A ticket was created with an unknown type or priority.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->nextTicketNumber->takenFor);
        self::assertSame([], $this->writes->writes);
    }

    /**
     * @return iterable<string, array{string, string, class-string<\InvalidArgumentException>, class-string<\InvalidArgumentException>, string}>
     */
    public static function refusedTitlesAndDescriptions(): iterable
    {
        yield 'a blank title' => ['   ', 'Steps: open the cart on a phone.', TicketTitleMissing::class, BlankTicketTitle::class, 'A ticket needs a title.'];
        yield 'a title of 201 characters' => [str_repeat('a', 201), 'Steps: open the cart on a phone.', TicketTitleOverLimit::class, TicketTitleTooLong::class, 'A ticket title has at most 200 characters.'];
        yield 'a description of 50,001 characters' => ['Checkout button is hidden', str_repeat('a', 50_001), TicketDescriptionOverLimit::class, TicketDescriptionTooLong::class, 'A ticket description has at most 50,000 characters.'];
    }

    /**
     * @param class-string<\InvalidArgumentException> $applicationException
     * @param class-string<\InvalidArgumentException> $domainException
     */
    #[Test]
    #[DataProvider('refusedTitlesAndDescriptions')]
    public function aRefusedTitleOrDescriptionIsRethrownAsTheApplicationsExceptionAndSavesNothing(
        string $title,
        string $description,
        string $applicationException,
        string $domainException,
        string $message,
    ): void {
        try {
            $this->createTicket(self::shopProject(), null, title: $title, description: $description);
            self::fail('A refused title or description was saved.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame(
                [$applicationException, $message, $domainException],
                [$refused::class, $refused->getMessage(), null === $refused->getPrevious() ? null : $refused->getPrevious()::class],
            );
        }

        self::assertSame([], $this->writes->writes);
    }

    #[Test]
    public function stepsWithoutAToDoStepAreAFaultNotAChoice(): void
    {
        $this->expectExceptionObject(new \LogicException('The organization has no To do step.'));

        $this->createTicket(self::shopProject(), new UserId(self::OMAR), steps: [self::step(self::BACKLOG_STEP, 'todo', 'backlog')]);
    }

    #[Test]
    public function stepsWithoutABacklogStepAreAFaultNotAChoice(): void
    {
        $this->expectExceptionObject(new \LogicException('The organization has no Backlog step.'));

        $this->createTicket(self::shopProject(), null, steps: [self::step(self::TO_DO_STEP, 'todo', 'todo')]);
    }

    /**
     * @param list<StepView>|null $steps the team's steps; null for Backlog, a team step and To do, all in To do
     */
    private function createTicket(
        Project $project,
        ?UserId $assignee,
        string $type = 'story',
        string $priority = 'major',
        string $title = 'Checkout button is hidden',
        ?array $steps = null,
        string $description = 'Steps: open the cart on a phone.',
    ): string {
        $createTicket = new CreateTicket(
            new OrganizationProjects(new InMemoryProjects(false, $project)),
            self::shopTeamRoles(),
            new FixedStepQueries($steps ?? [
                self::step(self::BACKLOG_STEP, 'todo', 'backlog'),
                self::step(self::TEAM_TRIAGE_STEP, 'todo', null),
                self::step(self::TO_DO_STEP, 'todo', 'todo'),
            ]),
            $this->nextTicketNumber,
            $this->writes,
            $this->writes,
            $this->writes,
            $this->writes,
        );

        return $createTicket->create(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::LENA),
            self::SHOP_PROJECT,
            $title,
            $description,
            $type,
            $priority,
            $assignee,
            new \DateTimeImmutable(self::REPORTED_AT.' UTC'),
        );
    }

    private static function shopProject(): Project
    {
        return new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');
    }

    /**
     * Lena leads the Shop team, Omar is a member, Raj a viewer and Mia a member of another team, all in Acme Foods;
     * anyone else, or anyone asked about another organization, has no roles.
     */
    private static function shopTeamRoles(): MembershipQueries
    {
        return new readonly class(self::ACME_FOODS, self::SHOP_TEAM, self::OTHER_TEAM, self::LENA, self::OMAR, self::RAJ, self::MIA) implements MembershipQueries {
            public function __construct(
                private string $organizationId,
                private string $shopTeamId,
                private string $otherTeamId,
                private string $leadId,
                private string $memberId,
                private string $viewerId,
                private string $otherTeamMemberId,
            ) {
            }

            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('CreateTicket does not ask for organizations.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                $shop = [new TeamId($this->shopTeamId)];
                $other = [new TeamId($this->otherTeamId)];

                return match (true) {
                    $organizationId->toString() !== $this->organizationId => Roles::none(),
                    $userId->toString() === $this->leadId => new Roles(false, false, $shop, [], []),
                    $userId->toString() === $this->memberId => new Roles(false, false, [], $shop, []),
                    $userId->toString() === $this->viewerId => new Roles(false, false, [], [], $shop),
                    $userId->toString() === $this->otherTeamMemberId => new Roles(false, false, [], $other, []),
                    default => Roles::none(),
                };
            }
        };
    }

    private static function step(string $id, string $groupCode, ?string $coreCode): StepView
    {
        return new StepView($id, 'Step', $groupCode, 'To do', 0, null === $coreCode ? 'team' : 'core', $coreCode);
    }
}
