<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\NewTicketReport;
use App\Ticketing\Api\NewTicketReportRefused;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Application\CreateTicket\AssigneeNotInTeam;
use App\Ticketing\Application\CreateTicket\ProjectHasNoTeam;
use App\Ticketing\Application\CreateTicket\TicketDescriptionOverLimit;
use App\Ticketing\Application\CreateTicket\TicketTitleMissing;
use App\Ticketing\Application\CreateTicket\TicketTitleOverLimit;
use App\Ticketing\Application\ProjectNotFound;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The new ticket form at /tickets/new (FR-TKT-01, P03.T1): a person picks a project of one of their teams and writes the
 * ticket; only a Lead of the project's team may give it to someone in that team (personas-and-roles.md, TicketCreationVoter
 * ASSIGN). A person whose teams own no project, or who may create tickets in none of them, is denied, as is a project
 * that is not one of their choices or stops being one before the ticket is saved, and an assignee sent by someone who
 * leads none of the offered teams. A Lead who picks a project of a team they do not lead gets a field error instead.
 * A made ticket opens on its own page with the toast CREATED.
 *
 * An optional screenshot with its title, summary and pins (P05.T5) is saved by NewTicketReport as the ticket's Round 0
 * in the same transaction; a refused one is the field error "screenshot" and makes no ticket.
 *
 * Without the request transaction: a refused create has already taken the project's next number, and only rolling
 * back the whole transaction gives it back. Transactions gives each request a transaction of its own; a refusal leaves
 * it as TicketFormRefused, which carries the form page with its errors.
 */
final class NewTicketController extends AbstractController
{
    public const string ROUTE = 'ticketing_ticket_new';
    public const string CSRF_ID = 'ticketing_ticket_new';

    /** The form fields the action reads. ASSIGNEE_FIELD is empty for no one. */
    public const string CSRF_FIELD = '_csrf_token';
    public const string PROJECT_FIELD = 'project_id';
    public const string TITLE_FIELD = 'title';
    public const string DESCRIPTION_FIELD = 'description';
    public const string TYPE_FIELD = 'type';
    public const string PRIORITY_FIELD = 'priority';
    public const string ASSIGNEE_FIELD = 'assignee_id';
    /** The screenshot part, saved as the ticket's Round 0; an empty SCREENSHOT_MEDIA_FIELD is no screenshot. */
    public const string SCREENSHOT_MEDIA_FIELD = 'screenshot_media_id';
    public const string SCREENSHOT_TITLE_FIELD = 'screenshot_title';
    public const string SCREENSHOT_SUMMARY_FIELD = 'screenshot_summary';
    /** Posted as pins[n][x], pins[n][y] and pins[n][note], x and y in percent of the picture. */
    public const string PINS_FIELD = 'pins';

    /** By the value TicketCommands::create takes, in the order the form shows them. */
    public const array TYPE_LABELS = ['bug' => 'Bug', 'story' => 'Story', 'task' => 'Task'];
    public const array PRIORITY_LABELS = ['critical' => 'Critical', 'major' => 'Major', 'normal' => 'Normal', 'minor' => 'Minor'];
    public const string DEFAULT_TYPE = 'bug';
    public const string DEFAULT_PRIORITY = 'normal';

    /** In characters, as CreateTicket counts them: the title after trimming, the description as sent. */
    public const int TITLE_MAX_LENGTH = 200;
    public const int DESCRIPTION_MAX_LENGTH = 50_000;

    /** With the new ticket's key, for example "SHOP-12". */
    public const string CREATED = 'Ticket %s made.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string TITLE_MISSING = 'Give the ticket a title.';
    public const string TITLE_OVER_LIMIT = 'Keep the title to 200 characters or fewer.';
    public const string DESCRIPTION_OVER_LIMIT = 'Keep the description to 50,000 characters or fewer.';
    public const string TYPE_UNKNOWN = 'Choose Bug, Story or Task.';
    public const string PRIORITY_UNKNOWN = 'Choose Critical, Major, Normal or Minor.';
    public const string ASSIGNEE_NOT_IN_TEAM = 'Choose someone in the project’s team, or no one.';
    public const string ASSIGNEE_NOT_LED = 'Only the team’s lead can give it to someone.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly ProjectQueries $projectQueries,
        private readonly TeamQueries $teamQueries,
        private readonly UserQueries $userQueries,
        private readonly TicketCommands $ticketCommands,
        private readonly TicketKeyQueries $ticketKeyQueries,
        private readonly NewTicketReport $newTicketReport,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/tickets/new', name: self::ROUTE, methods: ['GET', 'POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function open(Request $request): Response
    {
        if (null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('GET')) {
            return $this->transactions->run(function (): Response {
                $actor = $this->requestActor();

                return $this->renderTicketForm($actor, $this->creatableChoicesOf($actor), new TicketFormInput());
            });
        }

        $input = TicketFormInput::fromRequest($request);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();

        try {
            $key = $this->transactions->run(function () use ($input, $csrfToken, $now): string {
                $actor = $this->requestActor();
                $choices = $this->creatableChoicesOf($actor);
                if (!$this->isCsrfTokenValid(self::CSRF_ID, $csrfToken)) {
                    throw new TicketFormRefused($this->renderTicketForm($actor, $choices, $input, ['form' => self::FORM_EXPIRED]));
                }
                $project = $this->chosenProject($choices, $input->projectId);
                // Second line of defence: chosenProject only returns a choice the voter already granted.
                $this->denyAccessUnlessGranted(TicketPermission::CREATE, $project->teamId());
                $errors = $this->fieldErrorsOf($input);
                if ('' !== $input->assigneeId && !$this->isGranted(TicketPermission::ASSIGN, $project->teamId())) {
                    // Without the field on the page an assignee can only be forged; with it, the actor picked a project they do not lead.
                    if ([] === $this->assignableTeamIdsOf($choices)) {
                        throw $this->createAccessDeniedException('Only the team lead may give a ticket to someone.');
                    }
                    $errors['assignee'] = self::ASSIGNEE_NOT_LED;
                }
                $screenshotRefusal = $this->newTicketReport->refusalOf($actor->organizationId(), $actor->userId(), $input->report);
                if (null !== $screenshotRefusal) {
                    $errors['screenshot'] = $screenshotRefusal;
                }
                if ([] !== $errors) {
                    throw new TicketFormRefused($this->renderTicketForm($actor, $choices, $input, $errors));
                }

                try {
                    $key = $this->ticketCommands->create(
                        $actor->organizationId(),
                        $actor->userId(),
                        $project->id(),
                        trim($input->title),
                        $input->description,
                        $input->type,
                        $input->priority,
                        '' === $input->assigneeId ? null : new UserId($input->assigneeId),
                        $now,
                    );
                    $ticketId = $this->ticketKeyQueries->idOf($key) ?? throw new \LogicException(\sprintf('The ticket %s just made is not found by its key.', $key));
                    $this->newTicketReport->attach($actor->organizationId(), $actor->userId(), $ticketId, $input->report, $now);

                    return $key;
                } catch (TicketTitleMissing) {
                    $errors = ['title' => self::TITLE_MISSING];
                } catch (TicketTitleOverLimit) {
                    $errors = ['title' => self::TITLE_OVER_LIMIT];
                } catch (TicketDescriptionOverLimit) {
                    $errors = ['description' => self::DESCRIPTION_OVER_LIMIT];
                } catch (AssigneeNotInTeam) {
                    $errors = ['assignee' => self::ASSIGNEE_NOT_IN_TEAM];
                } catch (NewTicketReportRefused $screenshotRefused) {
                    $errors = ['screenshot' => $screenshotRefused->getMessage()];
                } catch (ProjectNotFound|ProjectHasNoTeam) {
                    // The project was removed or lost its team after the choices were read: as if it had been forged.
                    throw $this->createAccessDeniedException('You may not make tickets in that project.');
                }

                throw new TicketFormRefused($this->renderTicketForm($actor, $choices, $input, $errors));
            });
        } catch (TicketFormRefused $refused) {
            return $refused->formPage();
        }

        $this->addFlash(self::TOAST, \sprintf(self::CREATED, $key));

        return $this->redirectToRoute(TicketShowController::ROUTE, ['key' => $key]);
    }

    /**
     * Inside the transaction run opens: outside one, CurrentActor finds no roles under row-level security and keeps
     * that answer for the rest of the request.
     */
    private function requestActor(): ActorContext
    {
        return $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
    }

    /**
     * The projects of the actor's teams in which the voter lets them create tickets; reads under row-level security.
     *
     * @return non-empty-list<TeamProjectChoice>
     */
    private function creatableChoicesOf(ActorContext $actor): array
    {
        $choices = array_values(array_filter(
            $this->projectQueries->projectChoicesOfTeams($actor->roles()->teamIds()),
            fn (TeamProjectChoice $choice): bool => $this->isGranted(TicketPermission::CREATE, $choice->teamId()),
        ));
        if ([] === $choices) {
            throw $this->createAccessDeniedException('None of your teams has a project you may make tickets in.');
        }

        return $choices;
    }

    /**
     * @param list<TeamProjectChoice> $choices
     */
    private function chosenProject(array $choices, string $projectId): TeamProjectChoice
    {
        foreach ($choices as $choice) {
            if ($choice->id() === $projectId) {
                return $choice;
            }
        }

        throw $this->createAccessDeniedException('You may not make tickets in that project.');
    }

    /**
     * The checks the form can make before CreateTicket, which makes the title and description ones again.
     *
     * @return array<string, string> by field: title, description, type, priority or assignee
     */
    private function fieldErrorsOf(TicketFormInput $input): array
    {
        $errors = [];
        $titleLength = mb_strlen(trim($input->title));
        if (0 === $titleLength) {
            $errors['title'] = self::TITLE_MISSING;
        } elseif ($titleLength > self::TITLE_MAX_LENGTH) {
            $errors['title'] = self::TITLE_OVER_LIMIT;
        }
        if (mb_strlen($input->description) > self::DESCRIPTION_MAX_LENGTH) {
            $errors['description'] = self::DESCRIPTION_OVER_LIMIT;
        }
        if (!\array_key_exists($input->type, self::TYPE_LABELS)) {
            $errors['type'] = self::TYPE_UNKNOWN;
        }
        if (!\array_key_exists($input->priority, self::PRIORITY_LABELS)) {
            $errors['priority'] = self::PRIORITY_UNKNOWN;
        }
        if ('' !== $input->assigneeId && !Uuid::isValid($input->assigneeId)) {
            $errors['assignee'] = self::ASSIGNEE_NOT_IN_TEAM;
        }

        return $errors;
    }

    /**
     * Needs the transaction run opens: for a Lead, it reads the people, names and team names of the teams they lead.
     *
     * @param list<TeamProjectChoice> $choices
     * @param array<string, string>   $errors  by field, with "form" for the whole form and "screenshot" for the screenshot part
     */
    private function renderTicketForm(ActorContext $actor, array $choices, TicketFormInput $input, array $errors = []): Response
    {
        $assignableTeamIds = $this->assignableTeamIdsOf($choices);

        return $this->render('@Ticketing/new.html.twig', [
            'projects' => $choices,
            'mayAssign' => [] !== $assignableTeamIds,
            'assigneeGroups' => $this->assigneeGroupsOf($actor, $assignableTeamIds),
            'typeLabels' => self::TYPE_LABELS,
            'priorityLabels' => self::PRIORITY_LABELS,
            'input' => $input,
            'report' => $input->report,
            'errors' => $errors,
            'titleMaxLength' => self::TITLE_MAX_LENGTH,
            'descriptionMaxLength' => self::DESCRIPTION_MAX_LENGTH,
            'csrfId' => self::CSRF_ID,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The form shows "Give it to…" when this is not empty.
     *
     * @param list<TeamProjectChoice> $choices
     *
     * @return list<TeamId> each team of the choices the voter lets the actor give a new ticket to someone in, once
     */
    private function assignableTeamIdsOf(array $choices): array
    {
        $assignableTeamIds = [];
        foreach ($choices as $choice) {
            $teamId = $choice->teamId();
            if (!isset($assignableTeamIds[$teamId->toString()]) && $this->isGranted(TicketPermission::ASSIGN, $teamId)) {
                $assignableTeamIds[$teamId->toString()] = $teamId;
            }
        }

        return array_values($assignableTeamIds);
    }

    /**
     * Three queries whatever the number of teams: their people, the people's names and the teams' names; none for no team.
     *
     * @param list<TeamId> $ledTeamIds
     *
     * @return list<array{teamName: string, assignees: list<UserView>}> ordered by team name
     */
    private function assigneeGroupsOf(ActorContext $actor, array $ledTeamIds): array
    {
        if ([] === $ledTeamIds) {
            return [];
        }
        $assigneeIdsByTeam = $this->teamQueries->possibleAssigneesOf($ledTeamIds);
        $assigneeIds = [];
        foreach ($assigneeIdsByTeam as $teamAssigneeIds) {
            foreach ($teamAssigneeIds as $assigneeId) {
                $assigneeIds[$assigneeId->toString()] = $assigneeId;
            }
        }
        $usersById = $this->userQueries->byIds(array_values($assigneeIds));

        $assigneeGroups = [];
        foreach ($this->teamQueries->summariesOf($actor->organizationId(), $ledTeamIds) as $team) {
            $assigneeGroups[] = [
                'teamName' => $team->name(),
                'assignees' => array_values(array_filter(array_map(
                    static fn (UserId $assigneeId): ?UserView => $usersById[$assigneeId->toString()] ?? null,
                    $assigneeIdsByTeam[$team->id()->toString()] ?? [],
                ))),
            ];
        }

        return $assigneeGroups;
    }
}
