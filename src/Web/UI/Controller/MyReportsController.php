<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Insight\Api\GroupedReports;
use App\Insight\Api\HomeQueries;
use App\Insight\Api\MyReport;
use App\Insight\Api\ReportGroup;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketVisibilities;
use App\Web\UI\MyReports\MyReportsCursorParameter;
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
 * newest first; "Show more" opens the next page by its keyset cursor (MyReportsCursorParameter). One read of the
 * reports, however many there are.
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

        $visibility = $this->ticketVisibilities->of($actor->organizationId(), $actor->userId());
        $page = $this->homeQueries->myReports(TicketViewers::of($visibility), $cursor);
        $grouped = GroupedReports::of($page->reports, $this->clock->now());

        $sections = [];
        foreach (self::HEADED_GROUPS as $group) {
            $reports = $grouped->reportsIn($group);
            if ([] !== $reports) {
                $sections[] = [
                    'group' => $group->value,
                    'count' => \count($reports),
                    'shown' => array_map($this->rowOf(...), $grouped->shownIn($group)),
                    'folded' => array_map($this->rowOf(...), $grouped->foldedIn($group)),
                ];
            }
        }

        return $this->render('@Web/my_reports/show.html.twig', [
            'isFirstPage' => null === $cursor,
            'hasReports' => [] !== $page->reports,
            'sections' => $sections,
            'doneRows' => array_map($this->rowOf(...), $grouped->foldedIn(ReportGroup::Done)),
            'nextPageUrl' => null === $page->nextCursor ? null : $this->generateUrl(self::ROUTE, [
                MyReportsCursorParameter::AFTER_QUERY => MyReportsCursorParameter::valueOf($page->nextCursor),
            ]),
            'firstPageUrl' => $this->generateUrl(self::ROUTE),
        ]);
    }

    /**
     * @return array{key: string, title: string, url: string}
     */
    private function rowOf(MyReport $report): array
    {
        return [
            'key' => $report->key,
            'title' => $report->title,
            'url' => $this->generateUrl(HomeController::TICKET_ROUTE, ['key' => $report->key]),
        ];
    }
}
