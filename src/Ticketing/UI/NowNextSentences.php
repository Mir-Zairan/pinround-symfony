<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Insight\Api\SavedTurn;
use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPageView;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNext;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\Turn;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnRules;

/**
 * The "Now" and "Next" lines under a ticket's title (ticket-page.md, Header). The sentences are Workflow's
 * ({@see NowNext}); the turn is the one Insight saved for Home and Whose turn, so the page names the same person, and
 * only a ticket with no saved turn (a done one) gets it from {@see TurnRules}. The ticket, its team and the names come
 * with the page's own read; this adds one query on ins_ticket_turn (the saved turn and the reviews and tests waiting),
 * and none for the rules' step policy, which the request already holds. Needs an open transaction under the organization as
 * tenant. The page names people by first name and passes no viewer, so it never says "you"; the lists keep "you" for the viewer.
 */
final readonly class NowNextSentences
{
    public function __construct(
        private TeamLoadQueries $teamLoadQueries,
        private UserQueries $userQueries,
        private RuleQueries $ruleQueries,
        private TurnRules $turnRules,
        private NowNext $nowNext,
    ) {
    }

    /**
     * @return array{now: string, next: string|null}|null null when the ticket has no team: no turn is chosen for it, so
     *                                                    the page shows no lines; next is null when nothing comes next,
     *                                                    as in Done
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function of(TicketPageView $page): ?array
    {
        $snapshot = $page->turnSnapshot;
        if (null === $snapshot->teamId || null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return null;
        }

        $ticket = TurnInputs::ticketStateOf($snapshot);
        $loads = $this->teamLoadQueries->loadsAndSavedTurnOf([...$snapshot->developerIds, ...$snapshot->testerIds], $snapshot->ticketId);
        $team = TurnInputs::teamFacts($snapshot->leadId, $snapshot->leadDisplayName, $snapshot->developerIds, $snapshot->testerIds, $loads->waitingTurns);
        $turn = null === $loads->savedTurn ? $this->turnRules->turnFor($ticket, $team) : self::turnOf($loads->savedTurn);
        $context = new NowNextContext(
            $page->stepName,
            $this->displayNamesWith($page->displayNames, $turn->userId),
            $team,
            $this->ruleQueries->rulesFor($snapshot->teamId)->reviewBeforeTest,
        );

        return [
            'now' => $this->nowNext->now($ticket, $turn, $context),
            'next' => $this->nowNext->next($ticket, $turn, $context),
        ];
    }

    /**
     * @throws \ValueError when the saved kind is not one of NeedsYouKind's
     */
    private static function turnOf(SavedTurn $savedTurn): Turn
    {
        return new Turn($savedTurn->userId, $savedTurn->label, NeedsYouKind::from($savedTurn->kind));
    }

    /**
     * @param array<string, string> $displayNames
     *
     * @return array<string, string> the page's names, and the turn user's when the page does not hold it: one more
     *                               query, for a turn that went to someone outside the ticket's people and its team
     */
    private function displayNamesWith(array $displayNames, ?UserId $turnUserId): array
    {
        if (null === $turnUserId || isset($displayNames[$turnUserId->toString()])) {
            return $displayNames;
        }
        foreach ($this->userQueries->byIds([$turnUserId]) as $userKey => $user) {
            $displayNames[$userKey] = $user->displayName;
        }

        return $displayNames;
    }
}
