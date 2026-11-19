<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Identity\Api\UserQueries;
use App\Insight\Api\SavedTurn;
use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPageTurns;
use App\Ticketing\Api\TicketPageTurnView;
use App\Ticketing\Api\TicketPageView;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNext;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\Turn;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnRules;

/**
 * The one place that reads a ticket's turn as its page shows it (ticket-page.md, Header and People): pageTurnOf reads
 * it once, for the "Now" and "Next" lines, the "Your next step" box and the People section, and linesOf words the
 * lines. The sentences are Workflow's ({@see NowNext}); the turn is the one Insight saved for Home and Whose turn, so
 * the page names the same person, and only a ticket with no saved turn (a done one) gets it from {@see TurnRules}. The
 * ticket, its team and the names come with the page's own read; this adds one query on ins_ticket_turn (the saved turn
 * and the reviews and tests waiting), and none for the rules' step policy, which the request already holds. Needs an
 * open transaction under the organization as tenant. The page names people by first name and passes no viewer, so it
 * never says "you"; the lists keep "you" for the viewer.
 */
final readonly class SavedTicketPageTurns implements TicketPageTurns
{
    public function __construct(
        private TeamLoadQueries $teamLoadQueries,
        private UserQueries $userQueries,
        private RuleQueries $ruleQueries,
        private TurnRules $turnRules,
        private NowNext $nowNext,
        private TicketPeopleRoles $ticketPeopleRoles,
    ) {
    }

    public function of(TicketPageView $page, array $otherPeopleIds = []): TicketPageTurnView
    {
        $pageTurn = $this->pageTurnOf($page, $otherPeopleIds);
        if (null === $pageTurn) {
            $snapshot = $page->turnSnapshot;

            return new TicketPageTurnView(
                null,
                null,
                $this->ticketPeopleRoles->of(TurnInputs::ticketStateOf($snapshot), null),
                $this->displayNamesWith($page->displayNames, array_values(array_filter([$snapshot->askedUserId, ...$otherPeopleIds]))),
            );
        }
        $lines = $this->linesOf($pageTurn);

        return new TicketPageTurnView(
            $lines['now'],
            $lines['next'],
            $this->ticketPeopleRoles->of($pageTurn->ticket, $pageTurn->team),
            $pageTurn->context->firstNames,
        );
    }

    /**
     * @param list<UserId> $otherPeopleIds people the page names besides the ticket's own and its team, such as the
     *                                     movers of What happened; their names come in the one query of the turn
     *                                     person and of the asker and the asked person of the question that pauses
     *                                     the ticket
     *
     * @return PageTurn|null null when the ticket has no team: no turn is chosen for it, so the page shows no lines and
     *                       no next step
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function pageTurnOf(TicketPageView $page, array $otherPeopleIds = []): ?PageTurn
    {
        $snapshot = $page->turnSnapshot;
        if (null === $snapshot->teamId || null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return null;
        }

        $ticket = TurnInputs::ticketStateOf($snapshot);
        $loads = $this->teamLoadQueries->loadsAndSavedTurnOf([...$snapshot->developerIds, ...$snapshot->testerIds], $snapshot->ticketId);
        $team = TurnInputs::teamFacts($snapshot->leadId, $snapshot->leadDisplayName, $snapshot->developerIds, $snapshot->testerIds, $loads->waitingTurns);
        $turn = null === $loads->savedTurn ? $this->turnRules->turnFor($ticket, $team) : self::turnOfSaved($loads->savedTurn);
        $rules = $this->ruleQueries->rulesFor($snapshot->teamId);
        $context = new NowNextContext(
            $page->stepName,
            $this->displayNamesWith($page->displayNames, array_values(array_filter([$turn->userId, $snapshot->askerId, $snapshot->askedUserId, ...$otherPeopleIds]))),
            $team,
            $rules->reviewBeforeTest,
            askerId: $snapshot->askerId,
        );

        return new PageTurn($snapshot->teamId, $ticket, $team, $turn, $context, $rules);
    }

    /**
     * @return array{now: string, next: string|null} next is null when nothing comes next, as in Done
     */
    public function linesOf(PageTurn $pageTurn): array
    {
        return [
            'now' => $this->nowNext->now($pageTurn->ticket, $pageTurn->turn, $pageTurn->context),
            'next' => $this->nowNext->next($pageTurn->ticket, $pageTurn->turn, $pageTurn->context),
        ];
    }

    /**
     * @throws \ValueError when the saved kind is not one of NeedsYouKind's
     */
    private static function turnOfSaved(SavedTurn $savedTurn): Turn
    {
        return new Turn($savedTurn->userId, $savedTurn->label, NeedsYouKind::from($savedTurn->kind));
    }

    /**
     * @param array<string, string> $displayNames
     * @param list<UserId>          $userIds
     *
     * @return array<string, string> the page's names, and those of the people the page does not hold: one more query
     *                               when any is missing (a turn that went to someone outside the ticket's people and
     *                               its team, a mover who left the team), none otherwise; a person Identity does not
     *                               know stays out
     */
    public function displayNamesWith(array $displayNames, array $userIds): array
    {
        $missingIds = [];
        foreach ($userIds as $userId) {
            if (!isset($displayNames[$userId->toString()])) {
                $missingIds[$userId->toString()] = $userId;
            }
        }
        if ([] === $missingIds) {
            return $displayNames;
        }
        foreach ($this->userQueries->byIds(array_values($missingIds)) as $userKey => $user) {
            $displayNames[$userKey] = $user->displayName;
        }

        return $displayNames;
    }
}
