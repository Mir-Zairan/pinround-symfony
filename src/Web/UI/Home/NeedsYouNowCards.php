<?php

declare(strict_types=1);

namespace App\Web\UI\Home;

use App\Insight\Api\NeedsYouTicket;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Web\UI\Controller\HomeController;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Api\VerbFor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The cards of Home's "Needs you now". Its queries do not grow with the cards: the askers' and assignees' names in one
 * batch (two queries, none when nobody is named) and the steps from the organization's cached steps (at most one
 * query). The task is the ticket page's verb (VerbFor), so both name the same task. Holds no state, so it is safe in
 * worker mode.
 */
final readonly class NeedsYouNowCards
{
    /** The priorities the Why line names; workflow-engine.md's example names Critical and leaves Normal out. */
    private const array URGENT_PRIORITY_LABELS = ['blocker' => 'Blocker', 'critical' => 'Critical', 'major' => 'Major'];

    private const int SECONDS_PER_DAY = 86400;

    public function __construct(
        private MemberNameQueries $memberNameQueries,
        private StepQueries $stepQueries,
        private VerbFor $verbFor,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Run it inside the request transaction, whose tenant is $organizationId.
     *
     * @param list<NeedsYouTicket> $tickets at most half MemberNameQueries::MAX_USER_IDS, as an asker and an assignee
     *                                      each take one name
     *
     * @return list<NeedsYouNowCard> in the order of $tickets
     *
     * @throws \LogicException when a ticket's step is not a step of the organization
     * @throws \ValueError     when a ticket's turn kind is not a NeedsYouKind value
     */
    public function cardsOf(OrganizationId $organizationId, UserId $viewerId, array $tickets, \DateTimeImmutable $now): array
    {
        if ([] === $tickets) {
            return [];
        }

        $userIdsToName = [];
        foreach ($tickets as $ticket) {
            foreach ([$ticket->askerId, $ticket->assigneeId] as $userId) {
                if (null !== $userId) {
                    $userIdsToName[$userId->toString()] = $userId;
                }
            }
        }
        $namesByUserId = $this->memberNameQueries->displayNamesOf($organizationId, array_values($userIdsToName));
        $stepsById = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            $stepsById[strtolower($step->id)] = $step;
        }

        return array_map(
            fn (NeedsYouTicket $ticket): NeedsYouNowCard => $this->cardOf($ticket, $viewerId, $namesByUserId, $stepsById, $now),
            $tickets,
        );
    }

    /**
     * @param array<string, string>         $namesByUserId
     * @param array<string, ListedStepView> $stepsById     by lowercase step id
     */
    private function cardOf(NeedsYouTicket $ticket, UserId $viewerId, array $namesByUserId, array $stepsById, \DateTimeImmutable $now): NeedsYouNowCard
    {
        $step = $stepsById[strtolower($ticket->stepId)]
            ?? throw new \LogicException(\sprintf('The step of the ticket %s is not a step of the organization.', $ticket->key));
        $kind = NeedsYouKind::from($ticket->kind);
        $waitDays = max(0, intdiv($now->getTimestamp() - $ticket->waitingSince->getTimestamp(), self::SECONDS_PER_DAY));
        $task = $this->verbFor->person(
            new TicketState($ticket->stepId, $step->coreCode, $step->groupCode, null, $ticket->assigneeId),
            new Turn($viewerId, null, $kind),
            NowNextContext::forNowOnly($step->name, $namesByUserId, $viewerId, $ticket->askerId),
        ) ?? throw new \LogicException(\sprintf('The ticket %s waits on the viewer but has no task.', $ticket->key));

        return new NeedsYouNowCard(
            $ticket->key,
            $ticket->title,
            $this->urlGenerator->generate(HomeController::TICKET_ROUTE, ['key' => $ticket->key]),
            $task,
            NeedsYouKind::Check === $kind ? 'Check it' : 'Open ticket',
            self::reasonsOf($ticket, $kind, $namesByUserId),
            $waitDays,
            $ticket->waitingSince,
            match ($waitDays) {
                0 => 'today',
                1 => 'waiting 1 day',
                default => 'waiting '.$waitDays.' days',
            },
        );
    }

    /**
     * @param array<string, string> $namesByUserId
     *
     * @return list<string>
     */
    private static function reasonsOf(NeedsYouTicket $ticket, NeedsYouKind $kind, array $namesByUserId): array
    {
        $reasons = [];
        if (NeedsYouKind::Answer === $kind) {
            $askerName = null === $ticket->askerId ? null : NowNextContext::firstNameFrom($namesByUserId[$ticket->askerId->toString()] ?? '');
            $reasons[] = ($askerName ?? 'Someone').' is blocked until you answer';
        }
        $priorityLabel = self::URGENT_PRIORITY_LABELS[$ticket->priority] ?? null;
        if (null !== $priorityLabel) {
            $reasons[] = $priorityLabel;
        }

        return $reasons;
    }
}
