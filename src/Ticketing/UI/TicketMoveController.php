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
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\WaitingOnRefused;
use App\Ticketing\Application\RoleTicketVisibilities;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\StepQueries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The moves of the ticket page (FR-TKT-10, P03.T7, ticket-page.md, Your next step box): a verb button, and the lead's
 * "Move to another step…". Each answers with a redirect to the ticket page and a toast from {@see MoveToasts}.
 *
 * Without the request transaction, as NewTicketController: a move can be refused after its conditional UPDATE, and only
 * rolling back the whole transaction leaves nothing of it. Transactions gives each request a transaction of its own,
 * which also covers the reads and the access checks, so the toast is set only once the move is committed. In that
 * order: a ticket the person may not see is not found, as on its page; someone who may not move tickets of its team
 * (a Viewer, or a Manager or Admin with no team role) is denied; a form token that is not valid, or a version that is
 * no number, changes nothing. Who may make which move is then the team's transition policy, whose refusal is a toast.
 */
final class TicketMoveController extends AbstractController
{
    public const string MOVE_ROUTE = 'ticket_move';
    public const string MOVE_TO_ROUTE = 'ticket_move_to';

    /** The form fields the actions read. The waiting-on fields are move-to's, and empty for none. */
    public const string CSRF_FIELD = '_csrf_token';
    public const string VERSION_FIELD = 'version';
    public const string WAITING_ON_USER_FIELD = 'waiting_on_user_id';
    public const string WAITING_ON_LABEL_FIELD = 'waiting_on_label';

    /** In characters after trimming, as TicketCommands::moveTo counts them. */
    public const int WAITING_ON_LABEL_MAX_LENGTH = 100;

    /**
     * The verbs of MoveToasts::VERB_TOASTS, as a route requirement must be a constant string: any other verb is not
     * found. TicketMoveControllerTest keeps the two the same.
     */
    public const string VERB_REQUIREMENT = 'plan_it|start_work|send_to_code_review|send_to_testing|approve_code|ask_for_changes|pass_it|needs_changes|input_given|go_on|reopen|put_on_hold|move_on';
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
        $now = $this->clock->now();

        try {
            $toast = $this->transactions->run(function () use ($key, $verb, $csrfToken, $expectedVersion, $now): string {
                [$actor, $ticket] = $this->movableTicketOf($key);
                if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
                    return MoveToasts::FORM_EXPIRED;
                }
                if (null === $expectedVersion) {
                    return MoveToasts::CHANGED_MEANWHILE;
                }
                try {
                    $this->ticketCommands->move($actor->organizationId(), $actor->userId(), $key, $verb, $expectedVersion, $now);
                } catch (MoveRefused $refused) {
                    throw new MoveRefusedWithToast($this->refusalToastOf($refused, $ticket));
                }

                return MoveToasts::ofVerb(
                    $verb,
                    MoveToasts::namesPerson($verb) ? $this->turnFirstNameOf($ticket) : null,
                    MoveToasts::namesStep($verb) ? $this->stepNameAfterMoveOf($ticket) : null,
                );
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        } catch (TicketChangedMeanwhile) {
            $toast = MoveToasts::CHANGED_MEANWHILE;
        } catch (MoveRefusedWithToast $refused) {
            $toast = $refused->toast();
        }

        return $this->redirectWithToast($key, $toast);
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
        $now = $this->clock->now();

        try {
            $toast = $this->transactions->run(function () use ($key, $stepId, $csrfToken, $expectedVersion, $waitingOnUserId, $waitingOnLabel, $now): string {
                [$actor, $ticket] = $this->movableTicketOf($key);
                if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
                    return MoveToasts::FORM_EXPIRED;
                }
                if (null === $expectedVersion) {
                    return MoveToasts::CHANGED_MEANWHILE;
                }
                if (self::isWaitingOnMalformed($waitingOnUserId, $waitingOnLabel)) {
                    return MoveToasts::WAITING_ON_REFUSED;
                }
                try {
                    $this->ticketCommands->moveTo(
                        $actor->organizationId(),
                        $actor->userId(),
                        $key,
                        $stepId,
                        $expectedVersion,
                        '' === $waitingOnUserId ? null : new UserId(strtolower($waitingOnUserId)),
                        '' === $waitingOnLabel ? null : $waitingOnLabel,
                        $now,
                    );
                } catch (MoveRefused $refused) {
                    throw new MoveRefusedWithToast($this->refusalToastOf($refused, $ticket));
                } catch (WaitingOnRefused) {
                    throw new MoveRefusedWithToast(MoveToasts::WAITING_ON_REFUSED);
                }

                return MoveToasts::movedTo($this->listedStepNameOf($stepId));
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(TicketShowController::NOT_FOUND);
        } catch (TicketChangedMeanwhile) {
            $toast = MoveToasts::CHANGED_MEANWHILE;
        } catch (MoveRefusedWithToast $refused) {
            $toast = $refused->toast();
        }

        return $this->redirectWithToast($key, $toast);
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

    private function refusalToastOf(MoveRefused $refused, TicketPageView $ticket): string
    {
        if (null === $refused->ruleNoteTemplate) {
            return MoveToasts::MOVE_REFUSED;
        }

        return new RuleNote($refused->ruleNoteTemplate, $refused->ruleNotePeople)->render(
            fn (UserId $userId): string => $this->firstNameOf($userId, $ticket) ?? TicketShowController::UNKNOWN_PERSON,
        );
    }

    /**
     * One query on ins_ticket_turn, which the move has just refreshed.
     *
     * @return string|null null when the turn is a label's, or its person is unknown to Identity
     */
    private function turnFirstNameOf(TicketPageView $ticket): ?string
    {
        $turnUserId = $this->turnUserQueries->turnUsersOf([$ticket->id])[$ticket->id->toString()] ?? null;

        return null === $turnUserId ? null : $this->firstNameOf($turnUserId, $ticket);
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

    private function redirectWithToast(string $key, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);

        return $this->redirectToRoute(TicketShowController::ROUTE, ['key' => $key], Response::HTTP_SEE_OTHER);
    }
}
