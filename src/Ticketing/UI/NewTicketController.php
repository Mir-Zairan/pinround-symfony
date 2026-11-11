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
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Application\CreateTicket\AssigneeNotInTeam;
use App\Ticketing\Application\CreateTicket\TicketDescriptionOverLimit;
use App\Ticketing\Application\CreateTicket\TicketTitleMissing;
use App\Ticketing\Application\CreateTicket\TicketTitleOverLimit;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The new ticket form at /tickets/new (FR-TKT-01, P03.T1): a person picks a project of one of their teams, writes the
 * ticket and may give it to someone in that team. A person whose teams own no project, or who may create tickets in
 * none of them, is denied, as is a project that is not one of their choices or stops being one before the ticket is saved.
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

    /** By the value TicketCommands::create takes, in the order the form shows them. */
    public const array TYPE_LABELS = ['bug' => 'Bug', 'story' => 'Story', 'task' => 'Task'];
    public const array PRIORITY_LABELS = ['critical' => 'Critical', 'major' => 'Major', 'normal' => 'Normal', 'minor' => 'Minor'];
    public const string DEFAULT_TYPE = 'bug';
    public const string DEFAULT_PRIORITY = 'normal';

    /** In characters after trimming, as CreateTicket counts them. */
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

    /** The route of the ticket list, which takes the project key as PROJECT_QUERY. */
    public const string TICKET_LIST_ROUTE = 'ticketing_tickets';
    public const string PROJECT_QUERY = 'project';

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
            return $this->transactions->run(fn (): Response => $this->renderForm($this->creatableChoicesOf($this->requestActor()), new TicketFormInput()));
        }

        $input = TicketFormInput::fromRequest($request);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();

        try {
            $key = $this->transactions->run(function () use ($input, $csrfToken, $now): string {
                $actor = $this->requestActor();
                $choices = $this->creatableChoicesOf($actor);
                if (!$this->isCsrfTokenValid(self::CSRF_ID, $csrfToken)) {
                    throw new TicketFormRefused($this->renderForm($choices, $input, ['form' => self::FORM_EXPIRED]));
                }
                $project = $this->chosenProject($choices, $input->projectId);
                $this->denyAccessUnlessGranted(TicketPermission::CREATE, $project->teamId());
                $errors = $this->fieldErrorsOf($input);
                if ([] !== $errors) {
                    throw new TicketFormRefused($this->renderForm($choices, $input, $errors));
                }

                try {
                    return $this->ticketCommands->create(
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
                } catch (TicketTitleMissing) {
                    $errors = ['title' => self::TITLE_MISSING];
                } catch (TicketTitleOverLimit) {
                    $errors = ['title' => self::TITLE_OVER_LIMIT];
                } catch (TicketDescriptionOverLimit) {
                    $errors = ['description' => self::DESCRIPTION_OVER_LIMIT];
                } catch (AssigneeNotInTeam) {
                    $errors = ['assignee' => self::ASSIGNEE_NOT_IN_TEAM];
                } catch (\DomainException) {
                    // The project was removed or lost its team after the choices were read: as if it had been forged.
                    throw $this->createAccessDeniedException('You may not make tickets in that project.');
                }

                throw new TicketFormRefused($this->renderForm($choices, $input, $errors));
            });
        } catch (TicketFormRefused $refused) {
            return $refused->formPage();
        }

        $this->addFlash(self::TOAST, \sprintf(self::CREATED, $key));

        return $this->redirectToRoute(self::TICKET_LIST_ROUTE, [self::PROJECT_QUERY => explode('-', $key, 2)[0]]);
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
     * One query for the teams' people and one for their names, so it needs the transaction run opens.
     *
     * @param list<TeamProjectChoice> $choices
     * @param array<string, string>   $errors  by field, with "form" for the whole form
     */
    private function renderForm(array $choices, TicketFormInput $input, array $errors = []): Response
    {
        $teamIds = [];
        foreach ($choices as $choice) {
            $teamIds[$choice->teamId()->toString()] = $choice->teamId();
        }
        $assigneeIdsByTeam = $this->teamQueries->possibleAssigneesOf(array_values($teamIds));
        $assigneeIds = [];
        foreach ($assigneeIdsByTeam as $teamAssigneeIds) {
            foreach ($teamAssigneeIds as $assigneeId) {
                $assigneeIds[$assigneeId->toString()] = $assigneeId;
            }
        }
        $usersById = $this->userQueries->byIds(array_values($assigneeIds));

        $assigneeGroups = [];
        foreach ($choices as $choice) {
            $assigneeGroups[] = [
                'projectKey' => $choice->key(),
                'assignees' => array_values(array_filter(array_map(
                    static fn (UserId $assigneeId): ?UserView => $usersById[$assigneeId->toString()] ?? null,
                    $assigneeIdsByTeam[$choice->teamId()->toString()] ?? [],
                ))),
            ];
        }

        return $this->render('@Ticketing/new.html.twig', [
            'projects' => $choices,
            'assigneeGroups' => $assigneeGroups,
            'typeLabels' => self::TYPE_LABELS,
            'priorityLabels' => self::PRIORITY_LABELS,
            'input' => $input,
            'errors' => $errors,
            'titleMaxLength' => self::TITLE_MAX_LENGTH,
            'descriptionMaxLength' => self::DESCRIPTION_MAX_LENGTH,
            'csrfId' => self::CSRF_ID,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
