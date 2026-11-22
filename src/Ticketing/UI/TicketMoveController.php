<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\TurnUserQueries;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\SharedKernel\UI\PingsClearedToast;
use App\SharedKernel\UI\RoundStillOpenReason;
use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\DraftDecisionNeeded;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\UndecidedDraft;
use App\Ticketing\Api\WaitingOnRefused;
use App\Ticketing\Application\MoveTicket\MoveTicket;
use App\Ticketing\Application\RoleTicketVisibilities;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\TransitionPolicy;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The moves of the ticket page (FR-TKT-10, P03.T7, ticket-page.md, Your next step box): a verb button, the lead's
 * "Move to another step…", and the lead's "Give it to…" (P03.T8). Each answers with a toast from {@see MoveToasts}: a Turbo form in a Turbo Stream that also
 * re-renders the ticket's state and its Your next step box, any other form with a redirect to the ticket page. A move
 * that cleared the actor's pings on the ticket says how many ({@see PingsClearedToast}).
 *
 * Without the request transaction, as NewTicketController: a move can be refused after its conditional UPDATE, and only
 * rolling back the whole transaction leaves nothing of it. Transactions gives each request a transaction of its own,
 * which also covers the reads and the access checks, so the toast is set only once the move is committed. In that
 * order: a ticket the person may not see is not found, as on its page; someone who may not move tickets of its team
 * (a Viewer, or a Manager or Admin with no team role) is denied; a form token that is not valid, or a version that is
 * no number, changes nothing. Who may make which move is then the team's transition policy, whose refusal is a toast.
 * A verb move or move to a step the policy allows, by the owner of an undecided draft round with a screenshot, moves
 * nothing until the form says share or keep (ADR-0017, hand-off): until then it answers 409 with what the Share draft
 * dialog asks. A give does not ask. A share chosen while another shared round of the ticket stays open (ADR-0048), which
 * the dialog does not offer then, is refused with a toast that names who gives the verdict, and nothing is moved.
 */
final class TicketMoveController extends AbstractController
{
    public const string MOVE_ROUTE = 'ticket_move';
    public const string MOVE_TO_ROUTE = 'ticket_move_to';
    public const string ASSIGN_ROUTE = 'ticket_assign';
    public const string UNDO_ROUTE = 'ticket_undo_move';

    /**
     * The form fields the actions read. The waiting-on fields are move-to's, and empty for none; the assignee is
     * assign's, a user id.
     */
    public const string CSRF_FIELD = '_csrf_token';
    public const string VERSION_FIELD = 'version';
    public const string WAITING_ON_USER_FIELD = 'waiting_on_user_id';
    public const string WAITING_ON_LABEL_FIELD = 'waiting_on_label';
    public const string ASSIGNEE_FIELD = 'assignee';
    /** A verb move's or move-to's answer to "Share your draft round?": a DraftChoice value, share or keep. */
    public const string DRAFT_CHOICE_FIELD = 'draft_choice';

    /** In characters after trimming, as TicketCommands::moveTo counts them. */
    public const int WAITING_ON_LABEL_MAX_LENGTH = 100;

    /**
     * The verbs of MoveToasts::VERB_TOASTS, as a route requirement must be a constant string: any other verb is not
     * found. TicketMoveControllerTest keeps the two the same.
     */
    public const string VERB_REQUIREMENT = 'plan_it|start_work|send_to_code_review|send_to_testing|approve_code|ask_for_changes|pass_it|needs_changes|input_given|go_on|test_again|reopen|put_on_hold|move_on';
    public const string STEP_ID_REQUIREMENT = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'ticketing_ticket_move:';
    /** A version as the form sends it: a whole number with no sign, spaces or leading zero, that fits an int. */
    private const string VERSION_PATTERN = '/\A(?:0|[1-9][0-9]{0,17})\z/';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly TicketQueries $ticketQueries,
        private readonly TicketCommands $ticketCommands,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly TurnUserQueries $turnUserQueries,
        private readonly StepQueries $stepQueries,
        private readonly UserQueries $userQueries,
        private readonly ClockInterface $clock,
        private readonly TicketPageParts $ticketPageParts,
    ) {
    }

    /**
     * The CSRF token id of both moves of one ticket, for csrf_token() in the template.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    #[Route('/t/{key}/move/{verb}', name: self::MOVE_ROUTE, requirements: ['verb' => self::VERB_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function move(string $key, string $verb, Request $request): Response
    {
        $this->requestTenant();
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $expectedVersion = self::versionOf($request);
        // Absent or unknown asks first, so only a posted share or keep decides about the draft.
        $draftChoice = DraftChoice::tryFrom($request->request->getString(self::DRAFT_CHOICE_FIELD));
        $now = $this->clock->now();

        try {
            /** @var array{string, bool} $made */
            $made = $this->transactions->run(function () use ($key, $verb, $csrfToken, $expectedVersion, $draftChoice, $now): array {
                [$actor, $ticket] = $this->movableTicketOf($key);
                $this->refuseAnUnusableForm($key, $csrfToken, $expectedVersion);
                try {
                    $moveResult = $this->ticketCommands->move($actor->organizationId(), $actor->userId(), $key, $verb, $expectedVersion, $now, $draftChoice);
                } catch (MoveRefused $refused) {
                    throw $this->refusalOutOfTheMove($refused, $ticket);
                }
                if (null !== $moveResult->draftOutcome) {
                    return [PingsClearedToast::withClearedPings(MoveToasts::movedToWithDraft($this->stepNameAfterMoveOf($ticket), $moveResult), $moveResult->clearedPingCount), false];
                }

                return [PingsClearedToast::withClearedPings(MoveToasts::ofVerb(
                    $verb,
                    MoveToasts::namesPerson($verb) ? $this->turnFirstNameOf($ticket) : null,
                    MoveToasts::namesStep($verb) ? $this->stepNameAfterMoveOf($ticket) : null,
                ), $moveResult->clearedPingCount), true];
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        } catch (TicketChangedMeanwhile) {
            return $this->answerWithToast($request, $key, MoveToasts::CHANGED_MEANWHILE, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MoveRefusedWithToast $refused) {
            return $this->answerWithToast($request, $key, $refused->toast(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MoveRefused $roundStillOpen) {
            return $this->answerWithToast($request, $key, $this->roundStillOpenReasonAfterTheRollback($key, $roundStillOpen), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DraftDecisionNeeded $needed) {
            return $this->answerWithDraftQuestion($request, $key, $needed->draft, $this->generateUrl(self::MOVE_ROUTE, ['key' => $key, 'verb' => $verb]), fn (TicketPageView $ticket, ActorContext $actor): ?string => $this->verbTargetStepNameOf($verb, $ticket, $actor));
        }
        [$toast, $offersUndo] = $made;

        return $this->answerWithToast($request, $key, $toast, Response::HTTP_OK, $offersUndo ? $this->undoActionOf($key, $expectedVersion) : null);
    }

    /**
     * The policy lets only the lead make this move; a team member gets its refusal as a toast, not a 403, as the voter
     * lets every lead and member move the team's tickets.
     */
    #[Route('/t/{key}/move-to/{stepId}', name: self::MOVE_TO_ROUTE, requirements: ['stepId' => self::STEP_ID_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function moveTo(string $key, string $stepId, Request $request): Response
    {
        $this->requestTenant();
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $expectedVersion = self::versionOf($request);
        $waitingOnUserId = $request->request->getString(self::WAITING_ON_USER_FIELD);
        $waitingOnLabel = $request->request->getString(self::WAITING_ON_LABEL_FIELD);
        $draftChoice = DraftChoice::tryFrom($request->request->getString(self::DRAFT_CHOICE_FIELD));
        $now = $this->clock->now();

        try {
            /** @var array{string, bool} $made */
            $made = $this->transactions->run(function () use ($key, $stepId, $csrfToken, $expectedVersion, $waitingOnUserId, $waitingOnLabel, $draftChoice, $now): array {
                [$actor, $ticket] = $this->movableTicketOf($key);
                $this->refuseAnUnusableForm($key, $csrfToken, $expectedVersion);
                if (self::isWaitingOnMalformed($waitingOnUserId, $waitingOnLabel)) {
                    throw new MoveRefusedWithToast(MoveToasts::WAITING_ON_REFUSED);
                }
                try {
                    $moveResult = $this->ticketCommands->moveTo(
                        $actor->organizationId(),
                        $actor->userId(),
                        $key,
                        $stepId,
                        $expectedVersion,
                        '' === $waitingOnUserId ? null : new UserId(strtolower($waitingOnUserId)),
                        '' === $waitingOnLabel ? null : $waitingOnLabel,
                        $now,
                        $draftChoice,
                    );
                } catch (MoveRefused $refused) {
                    throw $this->refusalOutOfTheMove($refused, $ticket);
                } catch (WaitingOnRefused) {
                    throw new MoveRefusedWithToast(MoveToasts::WAITING_ON_REFUSED);
                }

                $stepName = $this->listedStepNameOf($stepId);

                return [PingsClearedToast::withClearedPings(
                    null === $moveResult->draftOutcome ? MoveToasts::movedTo($stepName) : MoveToasts::movedToWithDraft($stepName, $moveResult),
                    $moveResult->clearedPingCount,
                ), null === $moveResult->draftOutcome];
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        } catch (TicketChangedMeanwhile) {
            return $this->answerWithToast($request, $key, MoveToasts::CHANGED_MEANWHILE, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MoveRefusedWithToast $refused) {
            return $this->answerWithToast($request, $key, $refused->toast(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MoveRefused $roundStillOpen) {
            return $this->answerWithToast($request, $key, $this->roundStillOpenReasonAfterTheRollback($key, $roundStillOpen), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DraftDecisionNeeded $needed) {
            return $this->answerWithDraftQuestion($request, $key, $needed->draft, $this->generateUrl(self::MOVE_TO_ROUTE, ['key' => $key, 'stepId' => $stepId]), fn (): string => $this->listedStepNameOf($stepId));
        }
        [$toast, $offersUndo] = $made;

        return $this->answerWithToast($request, $key, $toast, Response::HTTP_OK, $offersUndo ? $this->undoActionOf($key, $expectedVersion) : null);
    }

    /**
     * Undo in a move's toast (gap list 1.10, beyond the prototype): takes the ticket's last move back, for the person who
     * made it, within Workflow's TransitionPolicy::UNDO_SECONDS by the server's clock, while that move is its last
     * change; the form posts the version the move left. A refusal is a toast, and changes nothing.
     */
    #[Route('/t/{key}/undo-move', name: self::UNDO_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function undoMove(string $key, Request $request): Response
    {
        $this->requestTenant();
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $expectedVersion = self::versionOf($request);
        $now = $this->clock->now();

        try {
            $toast = $this->transactions->run(function () use ($key, $csrfToken, $expectedVersion, $now): string {
                [$actor, $ticket] = $this->movableTicketOf($key);
                $this->refuseAnUnusableForm($key, $csrfToken, $expectedVersion);
                try {
                    $moveResult = $this->ticketCommands->undoMove($actor->organizationId(), $actor->userId(), $key, $expectedVersion, $now);
                } catch (MoveRefused $refused) {
                    throw new MoveRefusedWithToast($this->refusalToastOf($refused, $ticket));
                }

                return PingsClearedToast::withClearedPings(MoveToasts::undone($this->stepNameAfterMoveOf($ticket)), $moveResult->clearedPingCount);
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        } catch (TicketChangedMeanwhile) {
            return $this->answerWithToast($request, $key, MoveToasts::UNDO_CHANGED_SINCE, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MoveRefusedWithToast $refused) {
            return $this->answerWithToast($request, $key, $refused->toast(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->answerWithToast($request, $key, $toast, Response::HTTP_OK);
    }

    /**
     * The toast's Undo for a move made from $versionBefore: one save raises the version by one, so the form posts the
     * version the move left, and any save since refuses the Undo.
     *
     * @return array{label: string, url: string, csrfId: string, fields: array<string, string>, shownForMs: int}|null
     */
    private function undoActionOf(string $key, ?int $versionBefore): ?array
    {
        if (null === $versionBefore) {
            return null;
        }

        return [
            'label' => MoveToasts::UNDO,
            'url' => $this->generateUrl(self::UNDO_ROUTE, ['key' => $key]),
            'csrfId' => self::csrfIdOf($key),
            'fields' => [self::VERSION_FIELD => (string) ($versionBefore + 1)],
            'shownForMs' => TransitionPolicy::UNDO_SECONDS * 1000,
        ];
    }

    /**
     * "Give it to…" and "Give it to someone else…": only the team's lead may give a ticket (TicketPermission::ASSIGN),
     * so anyone else who may move it is denied. Whom it may go to, and from which step, is the use case's and the
     * policy's, whose refusal is a toast.
     */
    #[Route('/t/{key}/assign', name: self::ASSIGN_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function assign(string $key, Request $request): Response
    {
        $this->requestTenant();
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $expectedVersion = self::versionOf($request);
        $assigneeId = $request->request->getString(self::ASSIGNEE_FIELD);
        $now = $this->clock->now();

        try {
            $toast = $this->transactions->run(function () use ($key, $csrfToken, $expectedVersion, $assigneeId, $now): string {
                [$actor, $ticket] = $this->movableTicketOf($key);
                $this->denyAccessUnlessGranted(TicketPermission::ASSIGN, $ticket->visibilityFacts);
                $this->refuseAnUnusableForm($key, $csrfToken, $expectedVersion);
                if (!Uuid::isValid($assigneeId)) {
                    throw new MoveRefusedWithToast(MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE);
                }
                $assignee = new UserId(strtolower($assigneeId));
                try {
                    $clearedPingCount = $this->ticketCommands->giveTo($actor->organizationId(), $actor->userId(), $key, $assignee, $expectedVersion, $now);
                } catch (MoveRefused $refused) {
                    throw new MoveRefusedWithToast($this->refusalToastOf($refused, $ticket));
                }

                return PingsClearedToast::withClearedPings(MoveToasts::givenTo($this->firstNameOf($assignee, $ticket)), $clearedPingCount);
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        } catch (TicketChangedMeanwhile) {
            return $this->answerWithToast($request, $key, MoveToasts::CHANGED_MEANWHILE, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MoveRefusedWithToast $refused) {
            return $this->answerWithToast($request, $key, $refused->toast(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->answerWithToast($request, $key, $toast, Response::HTTP_OK);
    }

    /**
     * "Share your draft round?" in place of the move, which nothing made: the Share draft dialog as a Turbo Stream for a
     * Turbo form, whose buttons send the same form again with DRAFT_CHOICE_FIELD; for any other form a page with the
     * same question and buttons, which needs no script. Both answer 409, and both repeat the form's own fields. While
     * another shared round stays open (ADR-0048) both offer only "Keep private and move", with the reason.
     *
     * @param string                                                $moveUrl        where the buttons send the form again
     * @param \Closure(TicketPageView, ActorContext): (string|null) $targetStepName the step the move leads to, read in a transaction of its own
     */
    private function answerWithDraftQuestion(Request $request, string $key, UndecidedDraft $draft, string $moveUrl, \Closure $targetStepName): Response
    {
        $fields = [
            self::CSRF_FIELD => $request->request->getString(self::CSRF_FIELD),
            self::VERSION_FIELD => $request->request->getString(self::VERSION_FIELD),
            self::WAITING_ON_USER_FIELD => $request->request->getString(self::WAITING_ON_USER_FIELD),
            self::WAITING_ON_LABEL_FIELD => $request->request->getString(self::WAITING_ON_LABEL_FIELD),
        ];
        $isStream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();

        // Rendered inside the read's transaction, as answerWithToast renders: the page's layout counts My reports under
        // the tenant, which fails outside a transaction.
        return $this->transactions->run(function () use ($key, $targetStepName, $draft, $moveUrl, $fields, $isStream): Response {
            [$actor, $ticket] = $this->movableTicketOf($key);
            $openRoundNumber = $draft->openRoundNumber;
            $parameters = [
                'ticketKey' => $key,
                'moveUrl' => $moveUrl,
                'draft' => $draft,
                'stepName' => $targetStepName($ticket, $actor),
                'shareBlockedReason' => null === $openRoundNumber ? null : $this->roundStillOpenReasonOf($openRoundNumber, $draft->openRoundReadyForUserId, $draft->openRoundNotReadyForUserId, $ticket, $actor),
                'fields' => $fields,
                'choiceField' => self::DRAFT_CHOICE_FIELD,
                'failedToast' => MoveToasts::MOVE_FAILED,
            ];
            if ($isStream) {
                return $this->render('@Ticketing/draft_question.stream.html.twig', $parameters, new Response(null, Response::HTTP_CONFLICT, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]));
            }

            return $this->render('@Ticketing/draft_question.html.twig', $parameters, new Response(null, Response::HTTP_CONFLICT));
        });
    }

    /**
     * The name of the step the verb leads to for this actor, from the same transitions the Your next step box offers.
     *
     * @return string|null null when the verb is not among them, so the dialog's button names no step
     */
    private function verbTargetStepNameOf(string $verb, TicketPageView $ticket, ActorContext $actor): ?string
    {
        $transitions = $this->ticketPageParts->of($ticket, $actor)['nextStep']->transitions ?? [];
        foreach ($transitions as $transition) {
            if ($transition->verb !== $verb) {
                continue;
            }
            $target = $transition->moveTarget;
            if (null !== $target->stepId) {
                return $this->listedStepNameOf($target->stepId);
            }
            if (null !== $ticket->visibilityFacts->teamId) {
                foreach ($this->stepQueries->stepsFor($ticket->visibilityFacts->teamId) as $step) {
                    if ($step->coreCode() === $target->coreCode) {
                        return $step->name();
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return int|null null when the form sent no version, or one that is no whole number
     */
    private static function versionOf(Request $request): ?int
    {
        $version = $request->request->getString(self::VERSION_FIELD);

        return 1 === preg_match(self::VERSION_PATTERN, $version) ? (int) $version : null;
    }

    /**
     * What the form alone shows wrong: both a person and a label, a person who is no uuid, or a label too long to count.
     * moveTo refuses a blank label or one of more than one line with WaitingOnRefused.
     */
    private static function isWaitingOnMalformed(string $waitingOnUserId, string $waitingOnLabel): bool
    {
        if ('' !== $waitingOnUserId && ('' !== $waitingOnLabel || !Uuid::isValid($waitingOnUserId))) {
            return true;
        }

        return mb_strlen(trim($waitingOnLabel)) > self::WAITING_ON_LABEL_MAX_LENGTH;
    }

    /**
     * Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
     */
    private function requestTenant(): void
    {
        if (null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Inside the transaction run opens: outside one, CurrentActor finds no roles under row-level security and keeps
     * that answer for the rest of the request. The same 404 as the ticket's page for a ticket the person may not see.
     *
     * @return array{ActorContext, TicketPageView}
     */
    private function movableTicketOf(string $key): array
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $ticket = $this->ticketQueries->pageOf($key, $this->ticketVisibilities->ofActor($actor));
        if (null === $ticket || !$this->isGranted(TicketPermission::VIEW, $ticket->visibilityFacts)) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(TicketPermission::MOVE, $ticket->visibilityFacts);

        return [$actor, $ticket];
    }

    /**
     * The refusal to throw out of the move's transaction. A share refused while another round stays open leaves as it
     * is: the move refreshed the turn row before the share, so whose turn it is can be read only after the rollback.
     */
    private function refusalOutOfTheMove(MoveRefused $refused, TicketPageView $ticket): MoveRefused|MoveRefusedWithToast
    {
        if (null !== $refused->openRoundNumber()) {
            return $refused;
        }

        return new MoveRefusedWithToast($this->refusalToastOf($refused, $ticket));
    }

    /**
     * @throws \LogicException for a refusal that names no open round, which refusalOutOfTheMove never lets through
     */
    private function roundStillOpenReasonAfterTheRollback(string $key, MoveRefused $refused): string
    {
        $openRoundNumber = $refused->openRoundNumber() ?? throw new \LogicException('Only a share refused for an open round leaves the move as MoveRefused.', 0, $refused);

        return $this->transactions->run(function () use ($key, $openRoundNumber, $refused): string {
            [$actor, $ticket] = $this->movableTicketOf($key);

            return $this->roundStillOpenReasonOf($openRoundNumber, $refused->openRoundReadyForUserId(), $refused->openRoundNotReadyForUserId(), $ticket, $actor);
        });
    }

    /**
     * Names who gives the verdict on the open round: the person Review named, else whoever's turn it is now, unless
     * Review said their own move leaves the round open; RoundReadyFor's reading of Review's answer, which Ticketing's UI
     * may not call (deptrac).
     *
     * @param UserId|null $notReadyForUserId whose turn names nobody, as Review said (the owner of a round shared with
     *                                       "Share now")
     */
    private function roundStillOpenReasonOf(int $openRoundNumber, ?UserId $readyForUserId, ?UserId $notReadyForUserId, TicketPageView $ticket, ActorContext $reader): string
    {
        $verdictGiverId = $readyForUserId ?? $this->turnVerdictGiverOf($ticket, $notReadyForUserId);
        if (null === $verdictGiverId) {
            return RoundStillOpenReason::of($openRoundNumber, null);
        }
        if ($verdictGiverId->equals($reader->userId())) {
            return RoundStillOpenReason::forTheVerdictGiver($openRoundNumber);
        }

        return RoundStillOpenReason::of($openRoundNumber, $this->firstNameOf($verdictGiverId, $ticket));
    }

    /**
     * Whoever's turn it is now, unless it is $notReadyForUserId's. One query on ins_ticket_turn.
     */
    private function turnVerdictGiverOf(TicketPageView $ticket, ?UserId $notReadyForUserId): ?UserId
    {
        $turnUserId = $this->turnUserIdOf($ticket);

        return null !== $turnUserId && null !== $notReadyForUserId && $turnUserId->equals($notReadyForUserId) ? null : $turnUserId;
    }

    private function refusalToastOf(MoveRefused $refused, TicketPageView $ticket): string
    {
        if (null === $refused->ruleNoteTemplate) {
            return MoveToasts::MOVE_REFUSED;
        }

        return new RuleNote($refused->ruleNoteTemplate, $refused->ruleNotePeople)->render(
            fn (UserId $userId): string => $this->firstNameOf($userId, $ticket) ?? PersonNames::UNKNOWN_PERSON,
        );
    }

    /**
     * One query on ins_ticket_turn, which the move has just refreshed.
     *
     * @return string|null null when the turn is a label's, or its person is unknown to Identity
     */
    private function turnFirstNameOf(TicketPageView $ticket): ?string
    {
        $turnUserId = $this->turnUserIdOf($ticket);

        return null === $turnUserId ? null : $this->firstNameOf($turnUserId, $ticket);
    }

    /**
     * One query on ins_ticket_turn.
     *
     * @return UserId|null null when the turn is a label's or the ticket has no turn row
     */
    private function turnUserIdOf(TicketPageView $ticket): ?UserId
    {
        return $this->turnUserQueries->turnUsersOf([$ticket->id])[$ticket->id->toString()] ?? null;
    }

    /**
     * From the names the page read before the move, which hold the ticket's people and its team's; one query for
     * anyone else.
     */
    private function firstNameOf(UserId $userId, TicketPageView $ticket): ?string
    {
        $displayName = $ticket->displayNames[$userId->toString()] ?? $this->userQueries->byIds([$userId])[$userId->toString()]->displayName ?? null;

        return null === $displayName ? null : new UserView($userId, $displayName)->firstName();
    }

    /**
     * @throws \LogicException when the moved ticket or its step is gone, which the move's own transaction rules out
     */
    private function stepNameAfterMoveOf(TicketPageView $ticket): string
    {
        $facts = $this->ticketTurnQueries->factsOf($ticket->id) ?? throw new \LogicException('The moved ticket is not found.');

        return $this->listedStepNameOf($facts->stepId);
    }

    /**
     * @throws \LogicException when the organization has no such step, which the move ruled out
     */
    private function listedStepNameOf(string $stepId): string
    {
        foreach ($this->stepQueries->listedSteps() as $step) {
            if ($step->id === $stepId) {
                return $step->name;
            }
        }

        throw new \LogicException('The step the ticket was moved to is not listed.');
    }

    /**
     * @phpstan-assert !null $expectedVersion
     *
     * @throws MoveRefusedWithToast for a form token that is not valid, or a version that is no number
     */
    private function refuseAnUnusableForm(string $key, string $csrfToken, ?int $expectedVersion): void
    {
        if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
            throw new MoveRefusedWithToast(MoveToasts::FORM_EXPIRED);
        }
        if (null === $expectedVersion) {
            throw new MoveRefusedWithToast(MoveToasts::CHANGED_MEANWHILE);
        }
    }

    /**
     * A Turbo form gets a Turbo Stream that shows the toast and replaces the header's state and the Your next step box
     * with the ticket as it is now, so a refused or stale page is updated too; their form carries the new version and
     * token. The ticket is read again, and the stream rendered, in a transaction of its own, after the move's commit or
     * rollback, with the same 404 and 403 as the move. Any other form gets a redirect to the ticket page with the toast
     * flashed, whatever the move's outcome.
     *
     * @param int                                                                                                    $streamStatus 200 for a move made, 422 for one refused
     * @param array{label: string, url: string, csrfId: string, fields: array<string, string>, shownForMs: int}|null $toastAction  the toast's Undo; only a Turbo Stream shows it
     */
    private function answerWithToast(Request $request, string $key, string $toast, int $streamStatus, ?array $toastAction = null): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            $this->addFlash(self::TOAST, $toast);

            return $this->redirectToRoute(TicketShowController::ROUTE, ['key' => $key], Response::HTTP_SEE_OTHER);
        }

        // Rendered inside the re-read's transaction, as the ticket page renders inside the request's: People's ping
        // sheets read the ping limit under the tenant, and outside a transaction they would open one of their own.
        return $this->transactions->run(function () use ($key, $toast, $streamStatus, $toastAction): Response {
            [$actor, $ticket] = $this->movableTicketOf($key);

            return $this->render(
                '@Ticketing/move.stream.html.twig',
                [...$this->ticketPageParts->of($ticket, $actor), 'toast' => $toast, 'toastAction' => $toastAction],
                new Response(null, $streamStatus, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
            );
        });
    }
}
