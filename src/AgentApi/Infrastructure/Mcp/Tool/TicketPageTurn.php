<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\Identity\Api\UserQueries;
use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPageView;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNext;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnPeopleChoice;
use App\Workflow\Api\TurnRules;

/**
 * The parts of get_ticket's answer that come from the ticket's turn, read as the ticket page reads them (Ticketing's
 * NowNextSentences and TicketPeople, which this module may not use): the turn Insight saved, or TurnRules' for a
 * ticket with none; Workflow's Now and Next sentences with first names and no viewer; and the People section's roles,
 * where a reviewer or tester not stored yet is the one the team would pick. One query for the saved turn and the
 * waiting turns, the team's rules (cached), and one for the names the page does not hold, only when one is missing.
 * Holds no state (ADR-0008).
 */
final readonly class TicketPageTurn
{
    public const string ROLE_ASSIGNEE = 'assignee';
    public const string ROLE_REPORTER = 'reporter';
    public const string ROLE_REVIEWER = 'reviewer';
    public const string ROLE_TESTER = 'tester';

    /** Core codes of the steps where the People section lists the code reviewer. */
    private const array REVIEWER_CORE_CODES = ['progress', 'review'];

    /** Workflow's group code of the closed steps, where the People section lists no tester. */
    private const string DONE_GROUP = 'done';

    public function __construct(
        private TeamLoadQueries $teamLoadQueries,
        private UserQueries $userQueries,
        private RuleQueries $ruleQueries,
        private TurnRules $turnRules,
        private TurnPeopleChoice $turnPeopleChoice,
        private NowNext $nowNext,
    ) {
    }

    /**
     * now and next are null for a ticket no team owns, as the page shows no lines for it; next is null too when nothing
     * comes next. people are in the order of each person's first role (assignee, reporter, reviewer, tester).
     * display_names holds the names by user id string of everyone listed, the turn person, the asker and the asked
     * person, and the other people, but for a person Identity does not know.
     *
     * @param list<UserId> $otherPeopleIds people the caller names besides the ticket's own, such as the people of its
     *                                     open questions; their names come in the same one query
     *
     * @return array{now: string|null, next: string|null, people: list<array{user_id: string, roles: non-empty-list<string>}>, display_names: array<string, string>}
     *
     * @throws \LogicException           when no transaction is open or no tenant is set
     * @throws \ValueError               when the saved turn's kind is not one of NeedsYouKind's
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function answerOf(TicketPageView $page, array $otherPeopleIds = []): array
    {
        $snapshot = $page->turnSnapshot;
        $ticket = TurnInputs::ticketStateOf($snapshot);
        if (null === $snapshot->teamId || null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            $rolesByUser = $this->rolesByUserOf($ticket, null);
            $displayNames = $this->displayNamesWith($page->displayNames, [...self::userIdsOf($rolesByUser), ...$otherPeopleIds]);

            return ['now' => null, 'next' => null, 'people' => self::peopleOf($rolesByUser), 'display_names' => $displayNames];
        }

        $loads = $this->teamLoadQueries->loadsAndSavedTurnOf([...$snapshot->developerIds, ...$snapshot->testerIds], $snapshot->ticketId);
        $team = TurnInputs::teamFacts($snapshot->leadId, $snapshot->leadDisplayName, $snapshot->developerIds, $snapshot->testerIds, $loads->waitingTurns);
        $turn = null === $loads->savedTurn
            ? $this->turnRules->turnFor($ticket, $team)
            : new Turn($loads->savedTurn->userId, $loads->savedTurn->label, NeedsYouKind::from($loads->savedTurn->kind));
        $rolesByUser = $this->rolesByUserOf($ticket, $team);
        $displayNames = $this->displayNamesWith($page->displayNames, array_values(array_filter([
            $turn->userId,
            $snapshot->askerId,
            $snapshot->askedUserId,
            ...self::userIdsOf($rolesByUser),
            ...$otherPeopleIds,
        ])));
        $context = new NowNextContext(
            $page->stepName,
            $displayNames,
            $team,
            $this->ruleQueries->rulesFor($snapshot->teamId)->reviewBeforeTest,
            askerId: $snapshot->askerId,
        );

        return [
            'now' => $this->nowNext->now($ticket, $turn, $context),
            'next' => $this->nowNext->next($ticket, $turn, $context),
            'people' => self::peopleOf($rolesByUser),
            'display_names' => $displayNames,
        ];
    }

    /**
     * The People section's rule (FR-TKT-11): the assignee, the reporter, the code reviewer while the ticket is In
     * progress or in Code review, and the tester while it is open. For a ticket no team owns, only its stored people.
     *
     * @return array<string, non-empty-list<string>> roles by user id string, each person once, in the order of their
     *                                               first role
     */
    private function rolesByUserOf(TicketState $ticket, ?TeamFacts $team): array
    {
        $reviewerId = null;
        if (\in_array($ticket->coreCode, self::REVIEWER_CORE_CODES, true)) {
            $reviewerId = $ticket->reviewerId
                ?? (null === $team ? null : ($this->turnPeopleChoice->reviewerToStore($ticket->assigneeId, $team) ?? $team->leadId));
        }
        $testerId = null;
        if (self::DONE_GROUP !== $ticket->groupCode) {
            $testerId = $ticket->testerId ?? (null === $team ? null : $this->turnPeopleChoice->testerToStore($team));
        }

        $rolesByUser = [];
        foreach ([
            self::ROLE_ASSIGNEE => $ticket->assigneeId,
            self::ROLE_REPORTER => $ticket->reporterId,
            self::ROLE_REVIEWER => $reviewerId,
            self::ROLE_TESTER => $testerId,
        ] as $role => $userId) {
            if (null !== $userId) {
                $rolesByUser[$userId->toString()][] = $role;
            }
        }

        return $rolesByUser;
    }

    /**
     * @param array<string, non-empty-list<string>> $rolesByUser
     *
     * @return list<array{user_id: string, roles: non-empty-list<string>}>
     */
    private static function peopleOf(array $rolesByUser): array
    {
        $people = [];
        foreach ($rolesByUser as $userKey => $roles) {
            $people[] = ['user_id' => $userKey, 'roles' => $roles];
        }

        return $people;
    }

    /**
     * @param array<string, non-empty-list<string>> $rolesByUser
     *
     * @return list<UserId>
     */
    private static function userIdsOf(array $rolesByUser): array
    {
        return array_map(static fn (string $userKey): UserId => new UserId($userKey), array_keys($rolesByUser));
    }

    /**
     * @param array<string, string> $displayNames
     * @param list<UserId>          $userIds
     *
     * @return array<string, string> the page's names, and in one query those of the people it does not hold; none
     *                               when every one is there
     */
    private function displayNamesWith(array $displayNames, array $userIds): array
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
