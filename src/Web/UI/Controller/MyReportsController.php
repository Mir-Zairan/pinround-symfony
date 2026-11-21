<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Insight\Api\GroupedReports;
use App\Insight\Api\HomeQueries;
use App\Insight\Api\MyReport;
use App\Insight\Api\MyReportsCounts;
use App\Insight\Api\ReportGroup;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketVisibilities;
use App\Web\UI\MyReports\MyReportsCursorParameter;
use App\Web\UI\MyReports\ReportCard;
use App\Web\UI\MyReports\ReportCards;
use App\Web\UI\MyReports\ReportStage;
use App\Web\UI\TicketViewers;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * My reports (P07.T6, built as P14.T15): one page of the tickets the person reported, sorted into Needs you, Stuck and
 * Moving, with Done folded below them. The groups sort the page they are on, HomeQueries::MY_REPORTS_PER_PAGE reports
 * newest first; "Show more" opens the next page by its keyset cursor (MyReportsCursorParameter). Each report is a card
 * (ReportCards). Its queries do not grow with the page: one read of the reports, one count for the intro line over all
 * pages (MyReportsCounts::totalsOf, none for an empty page), and the cards' batches of open questions, names and pin
 * progress, and cached steps.
 */
final class MyReportsController extends AbstractController
{
    public const string ROUTE = 'my_reports';

    /** The groups with a heading, in the page's order; Done is folded below them. */
    private const array HEADED_GROUPS = [ReportGroup::NeedsYou, ReportGroup::Stuck, ReportGroup::Moving];

    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly HomeQueries $homeQueries,
        private readonly MyReportsCounts $myReportsCounts,
        private readonly ReportCards $reportCards,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/my-reports', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $after = $request->query->getString(MyReportsCursorParameter::AFTER_QUERY);
        $cursor = '' === $after ? null : (MyReportsCursorParameter::cursorOf($after) ?? throw new NotFoundHttpException('No such page of my reports.'));

        $viewer = TicketViewers::of($this->ticketVisibilities->of($actor->organizationId(), $actor->userId()));
        $page = $this->homeQueries->myReports($viewer, $cursor);
        // One time for the groups and the cards, so a card's side text never contradicts the group it sits in.
        $now = $this->clock->now();
        $grouped = GroupedReports::of($page->reports, $now);
        $cardsByTicketId = $this->reportCards->cardsOf($actor->organizationId(), $actor->userId(), $page->reports, $now);

        $sections = [];
        foreach (self::HEADED_GROUPS as $group) {
            $reports = $grouped->reportsIn($group);
            if ([] !== $reports) {
                $sections[] = [
                    'group' => $group->value,
                    'count' => \count($reports),
                    'shown' => self::cardsIn($grouped->shownIn($group), $cardsByTicketId),
                    'folded' => self::cardsIn($grouped->foldedIn($group), $cardsByTicketId),
                ];
            }
        }

        return $this->render('@Web/my_reports/show.html.twig', [
            'isFirstPage' => null === $cursor,
            'hasReports' => [] !== $page->reports,
            'totals' => [] === $page->reports ? null : $this->myReportsCounts->totalsOf($viewer),
            'stageLabels' => ReportStage::labels(),
            'sections' => $sections,
            'doneCards' => self::cardsIn($grouped->foldedIn(ReportGroup::Done), $cardsByTicketId),
            'nextPageUrl' => null === $page->nextCursor ? null : $this->generateUrl(self::ROUTE, [
                MyReportsCursorParameter::AFTER_QUERY => MyReportsCursorParameter::valueOf($page->nextCursor),
            ]),
            'firstPageUrl' => $this->generateUrl(self::ROUTE),
        ]);
    }

    /**
     * @param list<MyReport>            $reports
     * @param array<string, ReportCard> $cardsByTicketId
     *
     * @return list<ReportCard> in the order of $reports
     */
    private static function cardsIn(array $reports, array $cardsByTicketId): array
    {
        return array_map(
            static fn (MyReport $report): ReportCard => $cardsByTicketId[$report->ticketId->toString()]
                ?? throw new \LogicException(\sprintf('The report %s has no card.', $report->key)),
            $reports,
        );
    }
}
