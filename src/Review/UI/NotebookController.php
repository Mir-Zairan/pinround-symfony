<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Identity\Api\CurrentTimeZone;
use App\Review\Api\NotebookCursor;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebookSummary;
use App\Review\Api\NotebookView;
use App\Review\UI\Controller\RefusalAnswers;
use App\Review\UI\Controller\RefusedWithToast;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\SharedKernel\UI\RelativeTime;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "My notebooks" (ADR-0046, P13.T6 step 3): the notebooks a person's agent or browser extension filled, which only that
 * person sees. The list, last used first; one notebook's page with its pictures, pins and scenarios by title; Rename and
 * Delete. Every read and write goes through Notebooks, which asks NotebookVoter, so a notebook that is not the person's,
 * is another organization's, or is unknown or malformed is the one 404 NotebookRefused::NOT_FOUND, before the form is
 * read.
 *
 * The pages read in the request transaction. Rename and Delete run without it, each in a transaction of its own, so a
 * refusal leaves nothing and the toast is set only once the change is committed.
 */
final class NotebookController extends AbstractController
{
    public const string LIST_ROUTE = 'review_notebooks';
    public const string PAGE_ROUTE = 'review_notebook';
    public const string RENAME_ROUTE = 'review_notebook_rename';
    public const string DELETE_ROUTE = 'review_notebook_delete';
    public const string CSRF_FIELD = '_csrf_token';
    public const string TITLE_FIELD = 'title';
    public const string RENAMED = 'Notebook renamed.';
    public const string DELETED = 'Notebook deleted.';
    /** The list's query parameter of the next page's cursor, as afterOf writes it. */
    public const string AFTER_QUERY = 'after';
    public const string LIST_PAGE_NOT_FOUND = 'That page of notebooks does not exist.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'review_notebook:';
    /** "<last_used_at in Unix seconds>.<its microseconds>.<notebook id>", as afterOf writes it; the id as NotebookCursor takes it. */
    private const string AFTER = '/^(\d{1,11})\.(\d{6})\.([0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\z/';

    public function __construct(
        private readonly Notebooks $notebooks,
        private readonly TenantContext $tenantContext,
        private readonly Transactions $transactions,
        private readonly ScreenshotWithPinsViews $screenshotViews,
        private readonly RefusalAnswers $refusalAnswers,
        private readonly CurrentTimeZone $currentTimeZone,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function csrfIdOf(string $notebookId): string
    {
        return self::CSRF_ID_PREFIX.strtolower($notebookId);
    }

    #[Route('/notebooks', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->refuseWithoutTenant();
        $after = $this->cursorOf($request);
        $page = $this->notebooks->forOwner($after);
        $now = $this->clock->now();
        $viewerTimeZone = $this->currentTimeZone->zone();

        return $this->render('@Review/notebooks.html.twig', [
            'notebooks' => array_map(static fn (NotebookSummary $notebook): array => [
                'id' => $notebook->id,
                'title' => $notebook->title,
                'screenshotCount' => $notebook->screenshotCount,
                'scenarioCount' => $notebook->scenarioCount,
                'lastUsed' => RelativeTime::sinceOf($notebook->lastUsedAt, $now, $viewerTimeZone),
                'lastUsedAt' => $notebook->lastUsedAt->format(\DateTimeInterface::ATOM),
            ], $page->notebooks),
            'isLaterPage' => null !== $after,
            'nextAfter' => null === $page->nextCursor ? null : self::afterOf($page->nextCursor),
        ]);
    }

    #[Route('/notebooks/{id}', name: self::PAGE_ROUTE, methods: ['GET'])]
    public function page(string $id): Response
    {
        $this->refuseWithoutTenant();
        $notebook = $this->ownNotebook($id);

        return $this->renderPage($notebook, null, $notebook->title, Response::HTTP_OK);
    }

    #[Route('/notebooks/{id}/rename', name: self::RENAME_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function rename(string $id, Request $request): Response
    {
        $this->refuseWithoutTenant();
        $title = $request->request->getString(self::TITLE_FIELD);
        $now = $this->clock->now();
        try {
            $this->transactions->run(function () use ($id, $request, $title, $now): void {
                $notebook = $this->ownNotebook($id);
                if (!$this->isFormTokenValid($notebook->id, $request)) {
                    throw NotebookRefused::because(RefusedWithToast::FORM_EXPIRED);
                }
                $this->notebooks->rename($notebook->id, $title, $now);
            });
        } catch (NotebookRefused $refused) {
            return $this->renderRefusedRename($id, $title, $refused);
        }
        $this->addFlash(self::TOAST, self::RENAMED);

        return $this->redirectToRoute(self::PAGE_ROUTE, ['id' => strtolower($id)], Response::HTTP_SEE_OTHER);
    }

    #[Route('/notebooks/{id}/delete', name: self::DELETE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function delete(string $id, Request $request): Response
    {
        $this->refuseWithoutTenant();
        try {
            $this->transactions->run(function () use ($id, $request): void {
                $notebook = $this->ownNotebook($id);
                if (!$this->isFormTokenValid($notebook->id, $request)) {
                    throw RefusedWithToast::formExpired();
                }
                try {
                    $this->notebooks->delete($notebook->id);
                } catch (NotebookRefused $refused) {
                    throw new RefusedWithToast($refused->getMessage());
                }
            });
        } catch (RefusedWithToast $refused) {
            return $this->refusalAnswers->answer($request, $refused, function (string $toast) use ($id): Response {
                $this->addFlash(self::TOAST, $toast);

                return $this->redirectToRoute(self::PAGE_ROUTE, ['id' => strtolower($id)], Response::HTTP_SEE_OTHER);
            });
        }
        $this->addFlash(self::TOAST, self::DELETED);

        return $this->redirectToRoute(self::LIST_ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Notebooks needs the person's organization as tenant; a request without one goes to the login page.
     */
    private function refuseWithoutTenant(): void
    {
        if (null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for a notebook that is not the person's,
     *                                                                       unknown, malformed or denied alike
     */
    private function ownNotebook(string $id): NotebookView
    {
        return $this->notebooks->get($id) ?? throw $this->createNotFoundException(NotebookRefused::NOT_FOUND);
    }

    /**
     * The page again with the refusal over the rename form, after the refused transaction rolled back. The form keeps
     * the typed title unless it holds a NUL character or invalid UTF-8, which the page cannot show as typed.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException when the notebook is gone meanwhile
     */
    private function renderRefusedRename(string $id, string $postedTitle, NotebookRefused $refused): Response
    {
        return $this->transactions->run(function () use ($id, $postedTitle, $refused): Response {
            $notebook = $this->ownNotebook($id);
            $isPostedTitleShowable = mb_check_encoding($postedTitle, 'UTF-8') && !str_contains($postedTitle, "\0");

            return $this->renderPage($notebook, $refused->getMessage(), $isPostedTitleShowable ? $postedTitle : $notebook->title, Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for an AFTER_QUERY value afterOf does not
     *                                                                       write
     */
    private function cursorOf(Request $request): ?NotebookCursor
    {
        if (!$request->query->has(self::AFTER_QUERY)) {
            return null;
        }
        $after = $request->query->all()[self::AFTER_QUERY];
        if (!\is_string($after) || 1 !== preg_match(self::AFTER, $after, $parts)) {
            throw $this->createNotFoundException(self::LIST_PAGE_NOT_FOUND);
        }

        return new NotebookCursor(new \DateTimeImmutable('@'.$parts[1].'.'.$parts[2]), $parts[3]);
    }

    private static function afterOf(NotebookCursor $cursor): string
    {
        return $cursor->lastUsedAt->format('U.u').'.'.$cursor->notebookId;
    }

    private function isFormTokenValid(string $notebookId, Request $request): bool
    {
        return $this->isCsrfTokenValid(self::csrfIdOf($notebookId), $request->request->getString(self::CSRF_FIELD));
    }

    private function renderPage(NotebookView $notebook, ?string $error, string $title, int $status): Response
    {
        return $this->render('@Review/notebook.html.twig', [
            'notebook' => $notebook,
            'screenshots' => $this->screenshotViews->viewsOf($notebook->organizationId, $notebook->screenshots, 'notebook-shot', 'Screenshot %d of the notebook'),
            'error' => $error,
            'title' => $title,
            'csrfId' => self::csrfIdOf($notebook->id),
        ], new Response(null, $status));
    }
}
