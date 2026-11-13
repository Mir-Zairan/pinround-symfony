<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\HomeQueries;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Application\RoleTicketVisibilities;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The whose-turn view at /tickets/turn (FR-TKT-03, FR-TKT-18): the open tickets the viewer may see, one card per person
 * or label they wait on, "You" first, then the oldest wait first (HomeQueries::whoseTurn). Every person of the
 * organization may open it, as the tickets list. It keeps the list's project (TicketListContext::PROJECT_QUERY) and
 * picked team; the search and person filters are not applied yet. Each card shows its 5 oldest tickets; OPEN_QUERY,
 * a card's TurnGroup::groupKey(), shows up to 100 of that one. A project key of no project, or an OPEN_QUERY longer
 * than 100 characters, is a 404.
 *
 * "Outside the team" marks a label, and a person who is not one of the lead and members of the team RailTeamChoice
 * picks; with no such team, no person is marked. Three queries beside the shell's and the project's, whatever the
 * number of cards: the triager, the cards, then every card's names (the team's people are cached).
 */
final class WhoseTurnController extends AbstractController
{
    public const string ROUTE = 'ticketing_whose_turn';
    public const string OPEN_QUERY = 'open';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly ProjectQueries $projectQueries,
        private readonly HomeQueries $homeQueries,
        private readonly UserQueries $userQueries,
        private readonly TeamPeopleQueries $teamPeopleQueries,
        private readonly RailTeamChoice $railTeamChoice,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/tickets/turn', name: self::ROUTE, methods: ['GET'])]
    public function show(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        try {
            $listContext = TicketListContext::fromRequest($request, $actor, $this->projectQueries);
        } catch (BadRequestHttpException $searchRefusal) {
            return new Response($searchRefusal->getMessage(), Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $project = $listContext->project;
        $openedGroup = $request->query->getString(self::OPEN_QUERY);
        $openedGroup = '' === $openedGroup ? null : $openedGroup;

        $visibility = $this->ticketVisibilities->ofActor($actor);
        $viewer = new TicketViewer($visibility->organizationId(), $visibility->viewerId(), $visibility->teamIds(), $visibility->isAdmin(), $visibility->isTriager());
        try {
            $groups = $this->homeQueries->whoseTurn($viewer, $project?->id(), $openedGroup);
        } catch (\InvalidArgumentException $refusal) {
            throw new NotFoundHttpException('No such group of tickets.', $refusal);
        }

        $railTeamId = $this->railTeamChoice->teamOf($actor, $project, $listContext->pickedTeamId);
        $teamPeopleIds = null === $railTeamId ? null : array_map(
            static fn (PossibleAssignee $person): string => $person->userId->toString(),
            $this->teamPeopleQueries->namedPossibleAssigneesOf($railTeamId),
        );
        $userIds = [];
        foreach ($groups as $group) {
            if (null !== $group->turnUserId) {
                $userIds[] = $group->turnUserId;
            }
        }
        $names = $this->userQueries->byIds($userIds);
        $viewQuery = array_filter([
            TicketListContext::PROJECT_QUERY => $project?->key(),
            TicketListContext::TEAM_QUERY => $listContext->pickedTeamId?->toString(),
        ], static fn (?string $value): bool => null !== $value);

        $ticketCount = 0;
        $cards = [];
        foreach ($groups as $group) {
            $ticketCount += $group->ticketCount;
            $cards[] = $this->cardOf($group, $actor->userId(), $names, $teamPeopleIds, $openedGroup, $viewQuery);
        }

        return $this->render('@Ticketing/whose_turn.html.twig', [
            'heading' => null === $project ? TicketListController::ALL_TICKETS : $project->key().' · '.$project->name(),
            'totalLine' => WhoseTurnText::totalLineOf($ticketCount),
            'intro' => WhoseTurnText::INTRO,
            'cards' => $cards,
            'noTickets' => TicketListController::NO_TICKETS,
            'viewQuery' => $viewQuery,
            'listQuery' => $listContext->query(),
            'meterMax' => WhoseTurnText::METER_FULL_DAYS,
        ]);
    }

    /**
     * @param array<string, UserView> $names         by user id string
     * @param list<string>|null       $teamPeopleIds the rail team's lead and members; null for no team
     * @param array<string, string>   $viewQuery     the project and picked team, kept in the card's links
     *
     * @return array<string, mixed>
     */
    private function cardOf(TurnGroup $group, UserId $viewerId, array $names, ?array $teamPeopleIds, ?string $openedGroup, array $viewQuery): array
    {
        $now = $this->clock->now();
        $oldestWaitDays = WhoseTurnText::waitDaysOf($group->oldestWaitingSince, $now);
        $isViewer = null !== $group->turnUserId && $group->turnUserId->equals($viewerId);
        if (null === $group->turnUserId) {
            $name = (string) $group->turnLabel;
            $waitsOn = $name;
            $isOutsideTheTeam = true;
        } else {
            $user = $names[$group->turnUserId->toString()] ?? null;
            $name = $user->displayName ?? PersonNames::UNKNOWN_PERSON;
            $waitsOn = $isViewer ? 'you' : ($user?->firstName() ?? PersonNames::UNKNOWN_PERSON);
            $isOutsideTheTeam = null !== $teamPeopleIds && !\in_array($group->turnUserId->toString(), $teamPeopleIds, true);
        }
        $isOpened = $group->groupKey() === $openedGroup;
        $hiddenCount = $group->ticketCount - \count($group->tickets);

        $rows = [];
        foreach ($group->tickets as $ticket) {
            $waitDays = WhoseTurnText::waitDaysOf($ticket->waitingSince, $now);
            $rows[] = [
                'key' => $ticket->key,
                'title' => $ticket->title,
                'verb' => TurnKindVerbs::of($ticket->kind),
                'wait' => WhoseTurnText::waitOf($waitDays),
                'isWaitTooLong' => WhoseTurnText::isTooLong($waitDays),
            ];
        }

        return [
            'name' => $isViewer ? WhoseTurnText::YOU : $name,
            'avatarName' => $name,
            'tone' => $isViewer ? 'you' : ($isOutsideTheTeam ? 'outside' : 'team'),
            'sentence' => WhoseTurnText::sentenceOf($group->ticketCount, $waitsOn, $oldestWaitDays, $isOutsideTheTeam),
            'meterValue' => min($oldestWaitDays, WhoseTurnText::METER_FULL_DAYS),
            'meterLevel' => WhoseTurnText::meterLevelOf($oldestWaitDays),
            'meterTitle' => 'Oldest wait: '.WhoseTurnText::waitOf($oldestWaitDays),
            'rows' => $rows,
            'moreText' => $hiddenCount > 0 && !$isOpened ? 'Show '.number_format($hiddenCount).' more' : null,
            'moreUrl' => $this->generateUrl(self::ROUTE, [...$viewQuery, self::OPEN_QUERY => $group->groupKey()]),
            'fewerUrl' => $isOpened && \count($group->tickets) > HomeQueries::SHOWN_TICKETS ? $this->generateUrl(self::ROUTE, $viewQuery) : null,
        ];
    }
}
