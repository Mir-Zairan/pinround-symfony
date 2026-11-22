<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use App\Identity\Api\UserView;
use App\Review\Api\CheckItemChange;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistPermission;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistRefused;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Checklist\ChecklistCards;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\RoundPage\FocusPage;
use App\Review\UI\RoundsController;
use App\Review\UI\VisibleTicketLookup;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\SharedKernel\UI\PingsClearedToast;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPageView;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The buttons of the checklist cards in the ticket's Rounds section, the checklist round's (P05.T6, ticket-page.md,
 * Rounds) and a findings round's (FR-RND-17): "Mark fixed" for the ticket's assignee, "OK" and "Not fixed" for its
 * tester. A change answers a Turbo form with a stream that replaces the changed item's card, with focus on the button
 * that took the clicked one's place, and the toast; any other form with a redirect to the ticket's Rounds section with
 * the toast flashed, or, for a form of the round page or its full-screen review (RETURN_ROUND_FIELD), with a redirect
 * back to that page. A refusal changes nothing and answers with its toast the same two ways, as a 403 or 422 stream.
 *
 * Without the request transaction, as VerdictController: each action runs in a transaction of its own, so a refused
 * command leaves nothing. In that order: a ticket the person may not see is the ticket page's 404; an item id
 * ChecklistQueries::accessFactsOf does not know (a malformed one and another organization's too) and an item of
 * another ticket are one 404; ChecklistPermission denied is a 403 toast; a form token that is not valid is a 422 toast;
 * then the command's refusal is a 422 toast.
 */
final class ChecklistController extends AbstractController
{
    public const string MARK_FIXED_ROUTE = 'review_checklist_mark_fixed';
    public const string MARK_OK_ROUTE = 'review_checklist_mark_ok';
    public const string MARK_NOT_FIXED_ROUTE = 'review_checklist_mark_not_fixed';

    public const string CSRF_FIELD = '_csrf_token';

    /**
     * With a round number: the form is on that round's page or its full-screen review, which the answer goes back to
     * with the toast flashed, in place of the ticket's Rounds section.
     */
    public const string RETURN_ROUND_FIELD = 'return_round';
    /** With RETURN_ROUND_FIELD: the "?screenshot=N" to go back to. */
    public const string RETURN_SCREENSHOT_FIELD = 'return_screenshot';
    /** With RETURN_ROUND_FIELD: FocusPage::SIDE_AFTER or SIDE_BEFORE, the full-screen review's picture to go back to. */
    public const string RETURN_SIDE_FIELD = 'return_side';
    /** With RETURN_SIDE_FIELD: the full-screen review's "?pin=N" to go back to. */
    public const string RETURN_PIN_FIELD = 'return_pin';

    /** With the pin's number and the tester's first name. */
    public const string MARKED_FIXED = 'Pin %d marked fixed. %s checks it.';
    /** With the pin's number, for a ticket with no tester stored, which any tester of its team may check. */
    public const string MARKED_FIXED_FOR_A_TESTER = 'Pin %d marked fixed. A tester checks it.';
    /** With the pin's number. */
    public const string CHECKED_OK = 'Pin %d checked OK.';
    /** With the pin's number. */
    public const string SENT_BACK = 'Pin %d sent back.';
    public const string ONLY_THE_ASSIGNEE = "Only the ticket's assignee can mark a pin fixed.";
    public const string ONLY_THE_TESTER = "Only the ticket's tester can check a fixed pin.";
    /** The toast app.js leaves when a checklist form gets no stream back. */
    public const string SAVE_FAILED = 'Could not save the checklist change. Try again.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'review_checklist:';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly ChecklistQueries $checklistQueries,
        private readonly ChecklistCommands $checklistCommands,
        private readonly ChecklistCards $checklistCards,
        private readonly RefusalAnswers $refusalAnswers,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of the checklist forms on one ticket, for csrf_token() in the checklist card.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    #[Route('/t/{key}/checklist/{itemId}/fixed', name: self::MARK_FIXED_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function markFixed(string $key, string $itemId, Request $request): Response
    {
        $now = $this->clock->now();

        return $this->respondToChange(
            $request,
            $key,
            $itemId,
            ChecklistPermission::MARK_FIXED,
            self::ONLY_THE_ASSIGNEE,
            fn (string $id, UserId $actor): CheckItemChange => $this->checklistCommands->markFixed($id, $actor, $now),
            static fn (CheckItemChange $change, TicketPageView $ticket): string => self::markedFixedToastOf($change, $ticket),
        );
    }

    #[Route('/t/{key}/checklist/{itemId}/ok', name: self::MARK_OK_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function markOk(string $key, string $itemId, Request $request): Response
    {
        $now = $this->clock->now();

        return $this->respondToChange(
            $request,
            $key,
            $itemId,
            ChecklistPermission::CHECK,
            self::ONLY_THE_TESTER,
            fn (string $id, UserId $actor): CheckItemChange => $this->checklistCommands->markOk($id, $actor, $now),
            static fn (CheckItemChange $change): string => \sprintf(self::CHECKED_OK, $change->pinNumber),
        );
    }

    #[Route('/t/{key}/checklist/{itemId}/not-fixed', name: self::MARK_NOT_FIXED_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function markNotFixed(string $key, string $itemId, Request $request): Response
    {
        $now = $this->clock->now();

        return $this->respondToChange(
            $request,
            $key,
            $itemId,
            ChecklistPermission::CHECK,
            self::ONLY_THE_TESTER,
            fn (string $id, UserId $actor): CheckItemChange => $this->checklistCommands->markNotFixed($id, $actor, $now),
            static fn (CheckItemChange $change): string => \sprintf(self::SENT_BACK, $change->pinNumber),
        );
    }

    /**
     * @param string                                            $permission  the ChecklistPermission the change needs
     * @param string                                            $deniedToast what a person it is denied to is told
     * @param \Closure(string, UserId): CheckItemChange         $change      the command, on the item's id as stored
     * @param \Closure(CheckItemChange, TicketPageView): string $toastOf     the change's toast, before any cleared pings
     */
    private function respondToChange(Request $request, string $key, string $itemId, string $permission, string $deniedToast, \Closure $change, \Closure $toastOf): Response
    {
        if (null === $this->tenantContext->get()) {
            // Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
            throw $this->createAccessDeniedException();
        }
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $returnUrl = $this->returnUrlOf($request, $key);

        try {
            $answer = $this->transactions->run(function () use ($key, $itemId, $permission, $deniedToast, $csrfToken, $change, $toastOf): array {
                $visible = $this->visibleTicketLookup->visibleTicketOf($key);
                $item = $this->checklistQueries->accessFactsOf($itemId);
                if (null === $item || !$item->ticketId->equals($visible->ticket->id)) {
                    throw new NotFoundHttpException(ChecklistRefused::NOT_FOUND);
                }
                if (!$this->isGranted($permission, $item)) {
                    throw new RefusedWithToast($deniedToast, Response::HTTP_FORBIDDEN);
                }
                if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
                    throw RefusedWithToast::formExpired();
                }

                try {
                    $changed = $change($item->itemId, $visible->actor->userId());
                } catch (ChecklistRefused $refused) {
                    if ($refused->isNotFound()) {
                        throw new NotFoundHttpException($refused->getMessage(), $refused);
                    }
                    throw new RefusedWithToast($refused->getMessage());
                }

                $checklist = $this->checklistCards->forRounds($visible->actor->organizationId(), $visible->ticket->id, [$item->roundId])[$item->roundId]
                    ?? throw new \LogicException(\sprintf('Round %s of a changed check item has no items.', $item->roundId));

                return [
                    'toast' => PingsClearedToast::withClearedPings($toastOf($changed, $visible->ticket), $changed->clearedPingCount),
                    'checklist' => $checklist,
                    'itemId' => $item->itemId,
                ];
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(RoundsController::NOT_FOUND);
        } catch (RefusedWithToast $refused) {
            return $this->refusalAnswers->answer($request, $refused, fn (string $toast): Response => $this->redirectBack($key, $returnUrl, $toast));
        }

        return $this->respondToChanged($request, $key, $returnUrl, $answer['toast'], $answer['checklist'], $answer['itemId']);
    }

    /**
     * The round page or full-screen review the form names in its RETURN_* fields, built from the route, so no form can
     * send the person off the site; null when it names no round number, and then the answer is the ticket's Rounds.
     */
    private function returnUrlOf(Request $request, string $key): ?string
    {
        $fields = $request->request->all();
        $roundNumber = RoundController::positionOf($fields[self::RETURN_ROUND_FIELD] ?? null);
        if (null === $roundNumber) {
            return null;
        }
        $parameters = ['key' => $key, 'number' => $roundNumber];
        $screenshotNumber = RoundController::positionOf($fields[self::RETURN_SCREENSHOT_FIELD] ?? null);
        if (null !== $screenshotNumber) {
            $parameters[RoundController::SCREENSHOT_PARAMETER] = $screenshotNumber;
        }
        $side = $fields[self::RETURN_SIDE_FIELD] ?? null;
        if (!\in_array($side, [FocusPage::SIDE_AFTER, FocusPage::SIDE_BEFORE], true)) {
            return $this->generateUrl(RoundController::ROUND_ROUTE, $parameters);
        }
        $parameters[RoundController::SIDE_PARAMETER] = $side;
        $pinPosition = RoundController::positionOf($fields[self::RETURN_PIN_FIELD] ?? null);
        if (null !== $pinPosition) {
            $parameters[RoundController::PIN_PARAMETER] = $pinPosition;
        }

        return $this->generateUrl(RoundController::FOCUS_ROUTE, $parameters);
    }

    private function respondToChanged(Request $request, string $key, ?string $returnUrl, string $toast, ChecklistCard $checklist, string $itemId): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectBack($key, $returnUrl, $toast);
        }

        return $this->render(
            '@Review/checklist_change.stream.html.twig',
            [
                'toast' => $toast,
                'checklist' => $checklist,
                'focusedControlId' => $checklist->focusedControlIdAfterChangeOf($itemId),
                'ticketKey' => $key,
                'checklistCsrfId' => self::csrfIdOf($key),
            ],
            new Response(null, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
        );
    }

    /**
     * @param string|null $returnUrl as returnUrlOf gives it; null goes to the ticket's Rounds section
     */
    private function redirectBack(string $key, ?string $returnUrl, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);
        if (null !== $returnUrl) {
            return $this->redirect($returnUrl, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute(RoundsController::TICKET_ROUTE, ['key' => $key, '_fragment' => RoundsSection::FRAME_ID], Response::HTTP_SEE_OTHER);
    }

    private static function markedFixedToastOf(CheckItemChange $change, TicketPageView $ticket): string
    {
        $testerId = $ticket->turnSnapshot->testerId;
        $testerName = null === $testerId ? null : ($ticket->displayNames[$testerId->toString()] ?? null);
        if (null === $testerId || null === $testerName) {
            return \sprintf(self::MARKED_FIXED_FOR_A_TESTER, $change->pinNumber);
        }

        return \sprintf(self::MARKED_FIXED, $change->pinNumber, new UserView($testerId, $testerName)->firstName());
    }
}
