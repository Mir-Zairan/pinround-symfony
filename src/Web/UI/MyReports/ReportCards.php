<?php

declare(strict_types=1);

namespace App\Web\UI\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Api\ReportGroup;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\OpenQuestion;
use App\Ticketing\Api\OpenQuestionQueries;
use App\Ticketing\Api\PinProgress;
use App\Ticketing\Api\PinProgressQueries;
use App\Web\UI\Controller\HomeController;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\StepQueries;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The cards of one page of My reports. Its queries do not grow with the page: the open questions to the viewer of the
 * Needs you reports in one query (none when there is no such report), the turn people's and askers' names in one batch
 * (two queries, none when there is nobody to name), the pin progress of the page in one query, and the steps' groups
 * from the organization's cached steps (at most one query). Holds no state, so it is safe in worker mode.
 */
final readonly class ReportCards
{
    public function __construct(
        private MemberNameQueries $memberNameQueries,
        private StepQueries $stepQueries,
        private OpenQuestionQueries $openQuestionQueries,
        private PinProgressQueries $pinProgressQueries,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Run it inside the request transaction, whose tenant is $organizationId.
     *
     * @param list<MyReport>     $reports at most half MemberNameQueries::MAX_USER_IDS of them, as a turn person and an
     *                                    asker each take one name (a page holds 50)
     * @param \DateTimeImmutable $now     the time GroupedReports sorted the page by
     *
     * @return array<string, ReportCard> by ticket id string
     *
     * @throws \LogicException when a report's step is not a step of the organization
     */
    public function cardsOf(OrganizationId $organizationId, UserId $viewerId, array $reports, \DateTimeImmutable $now): array
    {
        if ([] === $reports) {
            return [];
        }

        $groupsByTicketId = [];
        $needsYouTicketIds = [];
        $userIdsToName = [];
        foreach ($reports as $report) {
            $group = ReportGroup::of($report, $now);
            $groupsByTicketId[$report->ticketId->toString()] = $group;
            if (ReportGroup::NeedsYou === $group) {
                $needsYouTicketIds[] = $report->ticketId;
            }
            if (null !== $report->turnUserId) {
                $userIdsToName[$report->turnUserId->toString()] = $report->turnUserId;
            }
        }
        $questionsByTicketId = $this->openQuestionQueries->openQuestionsTo($viewerId, $needsYouTicketIds);
        foreach ($questionsByTicketId as $question) {
            $userIdsToName[$question->askerId->toString()] = $question->askerId;
        }
        $namesByUserId = $this->memberNameQueries->displayNamesOf($organizationId, array_values($userIdsToName));
        $pinProgressByTicketId = $this->pinProgressQueries->pinProgressOf(array_map(static fn (MyReport $report): TicketId => $report->ticketId, $reports));
        $groupCodesByStepId = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            $groupCodesByStepId[strtolower($step->id)] = $step->groupCode;
        }

        $cards = [];
        foreach ($reports as $report) {
            $ticketId = $report->ticketId->toString();
            $cards[$ticketId] = $this->cardOf(
                $report,
                $groupsByTicketId[$ticketId],
                $viewerId,
                $namesByUserId,
                $groupCodesByStepId,
                $questionsByTicketId[$ticketId] ?? null,
                $pinProgressByTicketId[$ticketId] ?? null,
                $now,
            );
        }

        return $cards;
    }

    /**
     * @param array<string, string> $namesByUserId
     * @param array<string, string> $groupCodesByStepId ListedStepView::$groupCode by lowercase step id
     * @param OpenQuestion|null     $question           the open question to the viewer, read for a Needs you report only
     */
    private function cardOf(MyReport $report, ReportGroup $group, UserId $viewerId, array $namesByUserId, array $groupCodesByStepId, ?OpenQuestion $question, ?PinProgress $pinProgress, \DateTimeImmutable $now): ReportCard
    {
        $stepGroup = $groupCodesByStepId[strtolower($report->stepId)]
            ?? throw new \LogicException(\sprintf('The step of the report %s is not a step of the organization.', $report->key));
        $isDone = ReportGroup::Done === $group;
        $turnPersonName = $isDone || null === $report->turnUserId ? null : ($namesByUserId[$report->turnUserId->toString()] ?? null);
        $askerName = null === $question ? null : ($namesByUserId[$question->askerId->toString()] ?? null);
        $questionLine = null === $question ? null : ReportCardText::questionLineOf(null === $askerName ? null : NowNextContext::firstNameFrom($askerName), $question->body);
        // The quoted question is the asker's, so the avatar beside it is too (my-reports.md, Card).
        $avatarName = null === $questionLine ? $turnPersonName : $askerName;

        return new ReportCard(
            $report->key,
            $report->title,
            $this->urlGenerator->generate(HomeController::TICKET_ROUTE, ['key' => $report->key]),
            ReportCardText::sideTextOf($report, $group, $now),
            ReportGroup::Stuck === $group,
            $isDone ? ReportStage::Done : ReportStage::ofStepGroup($stepGroup),
            !$isDone && ('wait' === $stepGroup || NeedsYouKind::Answer->value === $report->turnKind),
            $avatarName,
            ReportCardText::nowTextOf($report, $group, $viewerId, null === $turnPersonName ? null : NowNextContext::firstNameFrom($turnPersonName), $questionLine),
            ReportGroup::NeedsYou === $group,
            null === $pinProgress ? null : ReportCardText::pinProgressTextOf($pinProgress),
        );
    }
}
