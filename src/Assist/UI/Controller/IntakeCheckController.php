<?php

declare(strict_types=1);

namespace App\Assist\UI\Controller;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeAssist;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckRefused;
use App\Assist\Api\IntakeCheckView;
use App\Assist\UI\Component\IntakeCheck;
use App\Assist\UI\FormExpired;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilities;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Check with AI" on the New ticket form (P08.T7): POST /tickets/new/check reads the form's project, title,
 * description and pin notes, queues a check (IntakeAssist::suggest) and answers IntakeCheck's frame in its running
 * state with the check's id; GET /tickets/new/check/{checkId} answers the frame with the check as its asker sees it
 * (IntakeAssist::checkOf), which the browser polls. Both answer only the frame, so the form keeps what the person typed.
 *
 * The POST asks TicketPermission::CREATE on the team of the project, as the form does (TicketCreationVoter), so an
 * unknown or malformed project, another organization's and one the person may not report in all answer 404 with
 * nothing queued. It then checks its own form token (422) and the title (422), and answers a refusal of the check with
 * its line: 404 for a malformed project, 422 for a draft too long or unreadable, 429 for the AI call limit and the open
 * checks cap, 403 for the policy's refusals and, failing closed, any code it does not name. The GET answers 404 for a
 * malformed id and for every check checkOf does not give the person. A request that is not Turbo's frame request is
 * sent to the form with 303 and queues nothing, as its answer could not keep the typed text.
 */
final class IntakeCheckController extends AbstractController
{
    public const string REQUEST_ROUTE = 'assist_intake_check_request';
    public const string FRAME_ROUTE = 'assist_intake_check_frame';

    public const string CSRF_ID = 'assist_intake_check';
    /** Not the form's own _csrf_token, which the New ticket form posts in the same request. */
    public const string CSRF_FIELD = '_intake_check_token';

    /** The New ticket form's fields (NewTicketController), which Assist's UI may not import (deptrac). */
    public const string PROJECT_FIELD = 'project_id';
    public const string TITLE_FIELD = 'title';
    public const string DESCRIPTION_FIELD = 'description';
    /** Posted as pins[n][note], in pin order. */
    public const string PINS_FIELD = 'pins';
    public const string NEW_TICKET_ROUTE = 'ticketing_ticket_new';

    public const string FORM_EXPIRED = FormExpired::LINE;
    public const string TITLE_MISSING = 'Write a title first.';
    public const string PROJECT_NOT_FOUND = 'No project you may report in has that id.';
    public const string CHECK_NOT_FOUND = 'No AI check of yours has that id.';

    /** The header Turbo sends when it loads a frame, with the frame's id. */
    private const string TURBO_FRAME_HEADER = 'Turbo-Frame';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly ProjectQueries $projectQueries,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly IntakeCandidateQueries $intakeCandidateQueries,
        private readonly IntakeAssist $intakeAssist,
    ) {
    }

    #[Route('/tickets/new/check', name: self::REQUEST_ROUTE, methods: ['POST'])]
    public function requestCheck(Request $request): Response
    {
        $actor = $this->requestActor();
        $project = $this->reportableProjectOf($actor, strtolower(self::postedText($request, self::PROJECT_FIELD)));
        if (!self::isFrameRequest($request)) {
            return $this->redirectToRoute(self::NEW_TICKET_ROUTE, status: Response::HTTP_SEE_OTHER);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, self::postedText($request, self::CSRF_FIELD))) {
            return $this->renderFrame(status: Response::HTTP_UNPROCESSABLE_ENTITY, notice: self::FORM_EXPIRED);
        }
        $title = self::postedText($request, self::TITLE_FIELD);
        if ('' === trim($title)) {
            return $this->renderFrame(status: Response::HTTP_UNPROCESSABLE_ENTITY, notice: self::TITLE_MISSING);
        }

        try {
            $checkId = $this->intakeAssist->suggest(
                new DraftReport($project->id(), $title, self::postedText($request, self::DESCRIPTION_FIELD), self::postedPinNotes($request)),
                $actor->userId(),
                $actor->organizationId(),
            );
        } catch (IntakeCheckRefused $refused) {
            return match ($refused->refusalCode) {
                IntakeCheckRefused::UNKNOWN_PROJECT => throw $this->createNotFoundException(self::PROJECT_NOT_FOUND),
                IntakeCheckRefused::DRAFT_TOO_LONG, IntakeCheckRefused::UNREADABLE_DRAFT => $this->renderFrame(status: Response::HTTP_UNPROCESSABLE_ENTITY, notice: $refused->getMessage()),
                IntakeCheckRefused::TOO_MANY_AI_REQUESTS, IntakeCheckRefused::TOO_MANY_OPEN_CHECKS => $this->renderFrame(status: Response::HTTP_TOO_MANY_REQUESTS, notice: $refused->getMessage()),
                // The person may take the security words out and ask again, so the button stays.
                IntakeCheckRefused::SECURITY_SENSITIVE_DRAFT_TO_CLOUD => $this->renderFrame(status: Response::HTTP_FORBIDDEN, notice: $refused->getMessage()),
                default => $this->renderFrame(status: Response::HTTP_FORBIDDEN, refusal: $refused->getMessage()),
            };
        }

        return $this->renderFrame($checkId, IntakeCheckView::queued());
    }

    /**
     * The suggested duplicates' titles cost one query for the viewer's visibility and one for the tickets, whatever
     * their number.
     */
    #[Route('/tickets/new/check/{checkId}', name: self::FRAME_ROUTE, methods: ['GET'])]
    public function frame(string $checkId, Request $request): Response
    {
        $actor = $this->requestActor();
        try {
            $intakeCheckId = new IntakeCheckId($checkId);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException(self::CHECK_NOT_FOUND);
        }
        $check = $this->intakeAssist->checkOf($intakeCheckId, $actor->userId(), $actor->organizationId())
            ?? throw $this->createNotFoundException(self::CHECK_NOT_FOUND);
        if (!self::isFrameRequest($request)) {
            return $this->redirectToRoute(self::NEW_TICKET_ROUTE, status: Response::HTTP_SEE_OTHER);
        }

        return $this->renderFrame($intakeCheckId, $check, $this->visibleDuplicatesOf($check, $actor));
    }

    /**
     * The person must be logged in to the tenant; the firewall sends a visitor's AccessDeniedException to the login
     * page. Runs in the request transaction, as the roles are read under row-level security.
     */
    private function requestActor(): ActorContext
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());

        return $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
    }

    /**
     * The project among those the form offers the person: one of their teams' projects, in whose team the voter lets
     * them make tickets.
     */
    private function reportableProjectOf(ActorContext $actor, string $projectId): TeamProjectChoice
    {
        foreach ($this->projectQueries->projectChoicesOfTeams($actor->roles()->teamIds()) as $choice) {
            if ($choice->id() === $projectId && $this->isGranted(TicketPermission::CREATE, $choice->teamId())) {
                return $choice;
            }
        }

        throw $this->createNotFoundException(self::PROJECT_NOT_FOUND);
    }

    /**
     * @return list<IntakeCandidate> the suggested duplicates the viewer may see now, in the AI's order; a key of a
     *                               ticket closed or hidden from them since the check is dropped
     */
    private function visibleDuplicatesOf(IntakeCheckView $check, ActorContext $actor): array
    {
        $duplicateKeys = $check->suggestion->duplicateKeys ?? [];
        if ([] === $duplicateKeys) {
            return [];
        }
        $viewer = $this->ticketVisibilities->of($actor->organizationId(), $actor->userId());

        return $this->intakeCandidateQueries->openCandidatesByKeys($viewer, \array_slice($duplicateKeys, 0, IntakeCandidateQueries::CANDIDATE_LIMIT));
    }

    /**
     * @param list<IntakeCandidate> $duplicates
     * @param string|null           $notice     a line shown with the button kept
     * @param string|null           $refusal    a line shown with the button hidden
     */
    private function renderFrame(
        ?IntakeCheckId $checkId = null,
        ?IntakeCheckView $check = null,
        array $duplicates = [],
        int $status = Response::HTTP_OK,
        ?string $notice = null,
        ?string $refusal = null,
    ): Response {
        $response = $this->render(
            '@Assist/intake_check_frame.html.twig',
            ['checkId' => $checkId, 'check' => $check, 'duplicates' => $duplicates, 'notice' => $notice, 'refusal' => $refusal],
            new Response(null, $status),
        );
        $response->setVary(self::TURBO_FRAME_HEADER);

        return $response;
    }

    /**
     * A field posted as text; an array or a missing field reads as empty, so it is refused like an empty one.
     */
    private static function postedText(Request $request, string $field): string
    {
        $value = $request->request->all()[$field] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * @return list<string> the note of each posted pin that has one as text, in pin order
     */
    private static function postedPinNotes(Request $request): array
    {
        $pins = $request->request->all()[self::PINS_FIELD] ?? [];
        $notes = [];
        foreach (\is_array($pins) ? $pins : [] as $pin) {
            if (\is_array($pin) && \is_string($pin['note'] ?? null)) {
                $notes[] = $pin['note'];
            }
        }

        return $notes;
    }

    private static function isFrameRequest(Request $request): bool
    {
        return IntakeCheck::FRAME_ID === $request->headers->get(self::TURBO_FRAME_HEADER);
    }
}
