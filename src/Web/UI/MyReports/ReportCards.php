<?php

declare(strict_types=1);

namespace App\Web\UI\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Api\ReportGroup;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Web\UI\Controller\HomeController;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\StepQueries;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The cards of one page of My reports. Its queries do not grow with the page: the turn people's names in one batch
 * (two queries, none when no report has a turn person) and the steps' groups from the organization's cached steps (at
 * most one query). Holds no state, so it is safe in worker mode.
 */
final readonly class ReportCards
{
    public function __construct(
        private MemberNameQueries $memberNameQueries,
        private StepQueries $stepQueries,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Run it inside the request transaction, whose tenant is $organizationId.
     *
     * @param list<MyReport>     $reports at most MemberNameQueries::MAX_USER_IDS of them (a page holds 50)
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

        $turnUserIds = [];
        foreach ($reports as $report) {
            if (null !== $report->turnUserId) {
                $turnUserIds[$report->turnUserId->toString()] = $report->turnUserId;
            }
        }
        $namesByUserId = $this->memberNameQueries->displayNamesOf($organizationId, array_values($turnUserIds));
        $groupCodesByStepId = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            $groupCodesByStepId[strtolower($step->id)] = $step->groupCode;
        }

        $cards = [];
        foreach ($reports as $report) {
            $cards[$report->ticketId->toString()] = $this->cardOf($report, ReportGroup::of($report, $now), $viewerId, $namesByUserId, $groupCodesByStepId, $now);
        }

        return $cards;
    }

    /**
     * @param array<string, string> $namesByUserId
     * @param array<string, string> $groupCodesByStepId ListedStepView::$groupCode by lowercase step id
     */
    private function cardOf(MyReport $report, ReportGroup $group, UserId $viewerId, array $namesByUserId, array $groupCodesByStepId, \DateTimeImmutable $now): ReportCard
    {
        $stepGroup = $groupCodesByStepId[strtolower($report->stepId)]
            ?? throw new \LogicException(\sprintf('The step of the report %s is not a step of the organization.', $report->key));
        $isDone = ReportGroup::Done === $group;
        $turnPersonName = $isDone || null === $report->turnUserId ? null : ($namesByUserId[$report->turnUserId->toString()] ?? null);

        return new ReportCard(
            $report->key,
            $report->title,
            $this->urlGenerator->generate(HomeController::TICKET_ROUTE, ['key' => $report->key]),
            ReportCardText::sideTextOf($report, $group, $now),
            ReportGroup::Stuck === $group,
            $isDone ? ReportStage::Done : ReportStage::ofStepGroup($stepGroup),
            !$isDone && ('wait' === $stepGroup || NeedsYouKind::Answer->value === $report->turnKind),
            $turnPersonName,
            ReportCardText::nowTextOf($report, $group, $viewerId, null === $turnPersonName ? null : NowNextContext::firstNameFrom($turnPersonName)),
            ReportGroup::NeedsYou === $group,
        );
    }
}
