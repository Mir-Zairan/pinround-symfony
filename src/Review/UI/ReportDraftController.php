<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftCursor;
use App\Review\Api\ReportDraftPermission;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Api\ReportDraftView;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ReportGiveTo;
use App\Ticketing\Api\ReportGiveToQueries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Report drafts" (ADR-0039, ADR-0040, P06.T1 step 5): the drafts a person's agent or extension prepared, which only
 * that person sees. The list, one draft's page to edit its text and pictures, Send and Delete. A draft that is not the
 * person's, or does not exist, is the one 404 ReportDraftRefused::NOT_FOUND, before the form is read.
 *
 * The pages read in the request transaction. Save, Send and Delete run without it, each in a transaction of its own, so
 * a refusal leaves nothing and the toast is set only once the change is committed. Send needs ReportDraftPermission
 * SEND, which a personal access token never has. The page offers Report a problem's "Give it to" for the project's
 * team (P14.T9); ReportDrafts::send refuses a person it does not offer.
 */
final class ReportDraftController extends AbstractController
{
    public const string LIST_ROUTE = 'review_report_drafts';
    public const string PAGE_ROUTE = 'review_report_draft';
    public const string SAVE_ROUTE = 'review_report_draft_save';
    public const string SEND_ROUTE = 'review_report_draft_send';
    public const string DELETE_ROUTE = 'review_report_draft_delete';
    public const string CSRF_FIELD = '_csrf_token';
    public const string VERSION_FIELD = 'version';
    /** "Give it to": a person's id, or empty for the team, as Report a problem posts it. */
    public const string ASSIGNEE_FIELD = 'assignee_id';
    public const string SAVED = 'Report draft saved.';
    /** With the new ticket's key. */
    public const string SENT = 'Sent. It is ticket %s now.';
    public const string DELETED = 'Report draft deleted.';
    public const string FORM_EXPIRED = 'This form expired. Reload the page and try again.';
    public const string SEND_NEEDS_A_PERSON = 'Only you can send this report, from your browser.';
    /** The list's query parameter of the next page's cursor, as afterOf writes it. */
    public const string AFTER_QUERY = 'after';
    public const string LIST_PAGE_NOT_FOUND = 'That page of report drafts does not exist.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'review_report_draft:';
    /** "<updated_at in Unix seconds>.<its microseconds>.<draft id>", as afterOf writes it. */
    private const string AFTER = '/^(\d{1,11})\.(\d{6})\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/';

    public function __construct(
        private readonly ReportDrafts $reportDrafts,
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly Transactions $transactions,
        private readonly ScreenshotWithPinsViews $screenshotViews,
        private readonly ClockInterface $clock,
        private readonly ProjectQueries $projectQueries,
        private readonly ReportGiveToQueries $reportGiveToQueries,
    ) {
    }

    public static function csrfIdOf(string $draftId): string
    {
        return self::CSRF_ID_PREFIX.strtolower($draftId);
    }

    #[Route('/report-drafts', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $actor = $this->actor();
        $after = $this->cursorOf($request);
        $page = $this->reportDrafts->forOwner($actor->userId(), $after);

        return $this->render('@Review/report_drafts.html.twig', [
            'drafts' => $page->drafts,
            'isLaterPage' => null !== $after,
            'nextAfter' => null === $page->nextCursor ? null : self::afterOf($page->nextCursor),
        ]);
    }

    #[Route('/report-drafts/{id}', name: self::PAGE_ROUTE, methods: ['GET'])]
    public function page(string $id): Response
    {
        return $this->renderPage($this->ownDraft($id, ReportDraftPermission::VIEW), null, Response::HTTP_OK);
    }

    #[Route('/report-drafts/{id}', name: self::SAVE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function save(string $id, Request $request): Response
    {
        $this->actor();
        $now = $this->clock->now();
        try {
            $this->transactions->run(function () use ($id, $request, $now): void {
                $draft = $this->ownDraft($id, ReportDraftPermission::EDIT);
                $this->refuseAnExpiredForm($draft->id, $request);
                $this->reportDrafts->update($draft->id, $draft->ownerId, $request->request->getInt(self::VERSION_FIELD), self::postedContentOf($draft, $request), $now);
            });
        } catch (ReportDraftRefused $refused) {
            return $this->renderRefusedForm($id, $request, $refused);
        }
        $this->addFlash(self::TOAST, self::SAVED);

        return $this->redirectToRoute(self::PAGE_ROUTE, ['id' => strtolower($id)], Response::HTTP_SEE_OTHER);
    }

    /**
     * Send is the edit form's second button: it saves the posted fields at the posted version, then sends that saved
     * draft, in one transaction, so a refusal of either keeps the draft as it was and shows the form as posted.
     */
    #[Route('/report-drafts/{id}/send', name: self::SEND_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function send(string $id, Request $request): Response
    {
        $this->actor();
        $now = $this->clock->now();
        try {
            $key = $this->transactions->run(function () use ($id, $request, $now): string {
                $draft = $this->ownDraft($id, ReportDraftPermission::EDIT);
                if (!$this->isGranted(ReportDraftPermission::SEND, $draft)) {
                    throw ReportDraftRefused::because(self::SEND_NEEDS_A_PERSON);
                }
                $this->refuseAnExpiredForm($draft->id, $request);
                $postedVersion = $request->request->getInt(self::VERSION_FIELD);
                $this->reportDrafts->update($draft->id, $draft->ownerId, $postedVersion, self::postedContentOf($draft, $request), $now);
                $assigneeId = $request->request->getString(self::ASSIGNEE_FIELD);

                return $this->reportDrafts->send($draft->id, $draft->ownerId, $postedVersion + 1, '' === $assigneeId ? null : $assigneeId, $now);
            });
        } catch (ReportDraftRefused $refused) {
            return $this->renderRefusedForm($id, $request, $refused);
        }
        $this->addFlash(self::TOAST, \sprintf(self::SENT, $key));

        return $this->redirectToRoute(RoundsController::TICKET_ROUTE, ['key' => $key], Response::HTTP_SEE_OTHER);
    }

    #[Route('/report-drafts/{id}/delete', name: self::DELETE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function delete(string $id, Request $request): Response
    {
        $this->actor();
        try {
            $this->transactions->run(function () use ($id, $request): void {
                $draft = $this->ownDraft($id, ReportDraftPermission::EDIT);
                $this->refuseAnExpiredForm($draft->id, $request);
                $this->reportDrafts->delete($draft->id, $draft->ownerId);
            });
        } catch (ReportDraftRefused $refused) {
            if ($refused->isNotFound()) {
                throw $this->createNotFoundException(ReportDraftRefused::NOT_FOUND);
            }
            $this->addFlash(self::TOAST, $refused->getMessage());

            return $this->redirectToRoute(self::PAGE_ROUTE, ['id' => strtolower($id)], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash(self::TOAST, self::DELETED);

        return $this->redirectToRoute(self::LIST_ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The logged-in person under their organization as tenant; anyone else goes to the login page.
     */
    private function actor(): ActorContext
    {
        $actor = $this->currentActor->actorFor($this->getUser());
        if (null === $actor || null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }

        return $actor;
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for a draft that is not the person's,
     *                                                                       unknown, malformed or denied alike
     */
    private function ownDraft(string $id, string $attribute): ReportDraftView
    {
        $draft = $this->reportDrafts->ofOwner($id, $this->actor()->userId());
        if (null === $draft || !$this->isGranted($attribute, $draft)) {
            throw $this->createNotFoundException(ReportDraftRefused::NOT_FOUND);
        }

        return $draft;
    }

    /**
     * The page again with the refusal over the form, after the refused transaction rolled back.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for ReportDraftRefused::NOT_FOUND
     */
    private function renderRefusedForm(string $id, Request $request, ReportDraftRefused $refused): Response
    {
        if ($refused->isNotFound()) {
            throw $this->createNotFoundException(ReportDraftRefused::NOT_FOUND);
        }
        // The posted fields match pictures by position and carry the old version's text; shown over the fresh
        // draft, a second Save would overwrite the other tab's change.
        $postedForm = ReportDraftRefused::DRAFT_CHANGED === $refused->getMessage() ? null : $request;

        return $this->transactions->run(fn (): Response => $this->renderPage($this->ownDraft($id, ReportDraftPermission::VIEW), $refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $postedForm));
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for an AFTER_QUERY value afterOf does not
     *                                                                       write
     */
    private function cursorOf(Request $request): ?ReportDraftCursor
    {
        if (!$request->query->has(self::AFTER_QUERY)) {
            return null;
        }
        $after = $request->query->all()[self::AFTER_QUERY];
        if (!\is_string($after) || 1 !== preg_match(self::AFTER, $after, $parts)) {
            throw $this->createNotFoundException(self::LIST_PAGE_NOT_FOUND);
        }

        return new ReportDraftCursor(new \DateTimeImmutable('@'.$parts[1].'.'.$parts[2]), $parts[3]);
    }

    private static function afterOf(ReportDraftCursor $cursor): string
    {
        return $cursor->updatedAt->format('U.u').'.'.$cursor->draftId;
    }

    /**
     * @throws ReportDraftRefused FORM_EXPIRED for a form token that is not valid
     */
    private function refuseAnExpiredForm(string $draftId, Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::csrfIdOf($draftId), $request->request->getString(self::CSRF_FIELD))) {
            throw ReportDraftRefused::because(self::FORM_EXPIRED);
        }
    }

    /**
     * The draft as the form changed it: the title, the detail, and for each saved picture, in its order, its title,
     * summary and pin notes, unless "Remove" is ticked. Pins keep their spots; a picture not in the draft is never
     * added by the form. Missing fields keep the saved value.
     */
    private static function postedContentOf(ReportDraftView $draft, Request $request): ReportDraftContent
    {
        $posted = $request->request->all('screenshots');
        $screenshots = [];
        foreach ($draft->screenshots as $index => $screenshot) {
            $fields = $posted[$index] ?? [];
            if (!\is_array($fields)) {
                $fields = [];
            }
            if ('1' === ($fields['remove'] ?? null)) {
                continue;
            }
            $notes = \is_array($fields['pins'] ?? null) ? $fields['pins'] : [];
            $pins = [];
            foreach ($screenshot->pins as $number => $pin) {
                $note = $notes[$number] ?? $pin->note;
                $pins[] = new PlacedPin($pin->xPercent, $pin->yPercent, \is_string($note) ? $note : $pin->note);
            }
            $title = $fields['title'] ?? $screenshot->title;
            $summary = $fields['summary'] ?? $screenshot->summary;
            $screenshots[] = new ScreenshotWithPins($screenshot->mediaId, \is_string($title) ? $title : null, \is_string($summary) ? $summary : null, $pins);
        }

        return new ReportDraftContent($request->request->getString('title'), $request->request->getString('detail'), $screenshots);
    }

    private function renderPage(ReportDraftView $draft, ?string $error, int $status, ?Request $postedForm = null): Response
    {
        $views = $this->screenshotViews->viewsOf($this->tenantContext->require(), $draft->screenshots, 'draft-shot', 'Screenshot %d of the report');
        $pictures = [];
        foreach ($draft->screenshots as $index => $screenshot) {
            $pictures[] = ['index' => $index, 'screenshot' => $screenshot, 'view' => $views[$index]];
        }

        return $this->render('@Review/report_draft.html.twig', [
            'draft' => $draft,
            'pictures' => $pictures,
            'error' => $error,
            'title' => $postedForm?->request->getString('title') ?? $draft->title,
            'detail' => $postedForm?->request->getString('detail') ?? $draft->detail,
            'postedScreenshots' => $postedForm?->request->all('screenshots'),
            'giveTo' => $this->giveToOf($draft),
            'assigneeId' => $postedForm?->request->getString(self::ASSIGNEE_FIELD) ?? '',
            'csrfId' => self::csrfIdOf($draft->id),
        ], new Response(null, $status));
    }

    /**
     * The "Give it to" Report a problem shows for the project's team, or null when the person has no role in it or the
     * project lost its team, so Send gives the report to the team.
     */
    private function giveToOf(ReportDraftView $draft): ?ReportGiveTo
    {
        $teamId = $this->projectQueries->projectByKey($draft->projectKey)?->teamId();
        if (null === $teamId) {
            return null;
        }
        $actor = $this->actor();

        return $this->reportGiveToQueries->giveToOf($actor->organizationId(), $actor->userId(), $teamId);
    }
}
