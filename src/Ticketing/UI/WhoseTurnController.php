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
 * organization may open it, as the tickets list. It has the list's project rail and toolbar (TicketFilters without the
 * people, whose filter does not apply here): the project (TicketListContext::PROJECT_QUERY) and the search
 * (SEARCH_QUERY, its best 1,000 matches, "1,000+" past them) narrow the cards; "Show done" (DONE_QUERY) only changes the
 * rail's counts, as done tickets wait on no one. Every page and card link keeps the project, picked team, done and
 * search. A page holds the viewer's card and 20 others; "Next page" replaces it with the next page by keyset
 * (WhoseTurnCursorParameter), and "Back to the start" goes back to the first, which holds the viewer's card. Each card
 * shows its 5 oldest tickets; OPEN_QUERY, a card's TurnGroup::groupKey(), shows up to 100 of that one. A project key of
 * no project, an OPEN_QUERY longer than 100 characters, or a malformed cursor is a 404; a search the list refuses is a
 * 400.
 *
 * "Outside the team" marks a label, and a person who is not one of the lead and members of the team RailTeamChoice
 * picks; with no such team, no person is marked. Six queries beside the shell's and the project's, whatever the number
 * of cards, with a search or without: the triager, the cards, every card's names (the team's people are cached) and
 * the rail's three. As on the list, a request from the cards' Turbo frame runs only the first three, and one from the
 * rail's frame only the rail's.
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
        private readonly ProjectRail $projectRail,
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
        $searchText = $listContext->searchText;
        $showsDone = $listContext->showsDone;
        $pickedTeamId = $listContext->pickedTeamId;
        $openedGroup = $request->query->getString(self::OPEN_QUERY);
        $openedGroup = '' === $openedGroup ? null : $openedGroup;
        $after = $request->query->getString(WhoseTurnCursorParameter::AFTER_QUERY);
        $cursor = '' === $after ? null : (WhoseTurnCursorParameter::cursorOf($after) ?? throw new NotFoundHttpException('No such page of whose turn.'));
        $railTeamId = $this->railTeamChoice->teamOf($actor, $project, $pickedTeamId);
        $isProjectsTeamOnRail = null !== $railTeamId && null !== $project?->teamId() && $railTeamId->equals($project->teamId());
        // The list's filters but the person, which does not apply here; "Show done" only changes the rail's counts.
        $railQuery = array_filter(
            [TicketListContext::DONE_QUERY => $showsDone ? '1' : null, TicketListContext::SEARCH_QUERY => $searchText],
            static fn (?string $value): bool => null !== $value,
        );
        if (TicketListController::RAIL_FRAME_ID === $request->headers->get(TicketListController::TURBO_FRAME_HEADER)) {
            $response = $this->render('@Ticketing/_project_rail.html.twig', [
                'rail' => $this->projectRail->viewOf($actor, $railTeamId, $project, $showsDone, $railQuery, self::ROUTE),
            ]);
            $response->setVary(TicketListController::TURBO_FRAME_HEADER, false);

            return $response;
        }

        $visibility = $this->ticketVisibilities->ofActor($actor);
        $viewer = new TicketViewer($visibility->organizationId(), $visibility->viewerId(), $visibility->teamIds(), $visibility->isAdmin(), $visibility->isTriager());
        try {
            $page = $this->homeQueries->whoseTurn($viewer, $project?->id(), $openedGroup, $cursor, $searchText);
        } catch (\InvalidArgumentException $refusal) {
            throw new NotFoundHttpException('No such group or page of tickets.', $refusal);
        }
        $groups = $page->groups;

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
            TicketListContext::TEAM_QUERY => $pickedTeamId?->toString(),
        ], static fn (?string $value): bool => null !== $value);
        $keptQuery = [...$viewQuery, ...$railQuery];
        $pageQuery = null === $cursor ? $keptQuery : [...$keptQuery, WhoseTurnCursorParameter::AFTER_QUERY => WhoseTurnCursorParameter::valueOf($cursor)];

        $cards = [];
        foreach ($groups as $group) {
            $cards[] = $this->cardOf($group, $actor->userId(), $names, $teamPeopleIds, $openedGroup, $pageQuery, $page->isTicketCountCapped);
        }

        // A filter change reloads only the cards' frame, so it gets that frame alone: no layout, toolbar or rail.
        $isFrameRequest = $request->headers->has(TicketListController::TURBO_FRAME_HEADER);
        $response = $this->render($isFrameRequest ? '@Ticketing/_whose_turn_frame.html.twig' : '@Ticketing/whose_turn.html.twig', [
            'rail' => $isFrameRequest ? null : $this->projectRail->viewOf($actor, $railTeamId, $project, $showsDone, $railQuery, self::ROUTE),
            'heading' => null === $project ? TicketListController::ALL_TICKETS : $project->key().' · '.$project->name(),
            'totalLine' => WhoseTurnText::totalLineOf($page->ticketCount, $page->isTicketCountCapped),
            'intro' => WhoseTurnText::INTRO,
            'cards' => $cards,
            'nextPageText' => WhoseTurnText::NEXT_PAGE,
            'nextPageUrl' => null === $page->nextCursor ? null : $this->generateUrl(self::ROUTE, [
                ...$keptQuery,
                WhoseTurnCursorParameter::AFTER_QUERY => WhoseTurnCursorParameter::valueOf($page->nextCursor),
            ]),
            'backToTheStartText' => WhoseTurnText::BACK_TO_THE_START,
            'backToTheStartUrl' => null === $cursor ? null : $this->generateUrl(self::ROUTE, $keptQuery),
            'noTickets' => null === $searchText ? TicketListController::NO_TICKETS : WhoseTurnText::NO_MATCHES,
            'viewQuery' => $viewQuery,
            'listQuery' => $listContext->query(),
            'meterMax' => WhoseTurnText::METER_FULL_DAYS,
            'filters' => [
                'project' => $project?->key() ?? '',
                'projectId' => $project?->id() ?? '',
                'showsDone' => $showsDone,
                'q' => $searchText ?? '',
                'team' => $railTeamId?->toString() ?? '',
                // As the list's: when the project's own team is on the rail, the project decides it.
                'pickedTeam' => $isProjectsTeamOnRail ? '' : ($pickedTeamId?->toString() ?? ''),
            ],
        ]);
        $response->setVary(TicketListController::TURBO_FRAME_HEADER, false);

        return $response;
    }

    /**
     * @param array<string, UserView> $names         by user id string
     * @param list<string>|null       $teamPeopleIds the rail team's lead and members; null for no team
     * @param array<string, string>   $pageQuery     the project, picked team, search and page, kept in the card's links
     *
     * @return array<string, mixed>
     */
    private function cardOf(TurnGroup $group, UserId $viewerId, array $names, ?array $teamPeopleIds, ?string $openedGroup, array $pageQuery, bool $isTicketCountCapped): array
    {
        $cardId = self::cardIdOf($group);
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
            'id' => $cardId,
            'name' => $isViewer ? WhoseTurnText::YOU : $name,
            'avatarName' => $name,
            'tone' => $isViewer ? 'you' : ($isOutsideTheTeam ? 'outside' : 'team'),
            'sentence' => WhoseTurnText::sentenceOf($group->ticketCount, $waitsOn, $oldestWaitDays, $isOutsideTheTeam, $isTicketCountCapped),
            'meterValue' => min($oldestWaitDays, WhoseTurnText::METER_FULL_DAYS),
            'meterLevel' => WhoseTurnText::meterLevelOf($oldestWaitDays),
            'meterTitle' => 'Oldest wait: '.WhoseTurnText::waitOf($oldestWaitDays),
            'rows' => $rows,
            'moreText' => $hiddenCount > 0 && !$isOpened ? 'Show '.number_format($hiddenCount).' more' : null,
            'moreUrl' => $this->generateUrl(self::ROUTE, [...$pageQuery, self::OPEN_QUERY => $group->groupKey(), '_fragment' => $cardId]),
            'fewerUrl' => $isOpened && \count($group->tickets) > HomeQueries::SHOWN_TICKETS
                ? $this->generateUrl(self::ROUTE, [...$pageQuery, '_fragment' => $cardId])
                : null,
        ];
    }

    /**
     * The card's HTML id, the same on every read, so "Show N more" and "Show fewer" land on it: "turn-<user id>", or for a
     * label "turn-label-" and its ASCII slug, with a hash of the label that keeps two labels of one slug apart.
     */
    private static function cardIdOf(TurnGroup $group): string
    {
        if (null !== $group->turnUserId) {
            return 'turn-'.$group->turnUserId->toString();
        }
        $label = (string) $group->turnLabel;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($label, 0, 40))), '-');

        return 'turn-label-'.('' === $slug ? '' : $slug.'-').substr(hash('sha256', $label), 0, 8);
    }
}
