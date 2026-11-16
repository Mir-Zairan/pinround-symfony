<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\Api\VerdictCommands;
use App\Review\Api\VerdictPermission;
use App\Review\Api\VerdictRefused;
use App\Review\UI\RoundPage\AreaTab;
use App\Review\UI\RoundPage\RoundPages;
use App\Review\UI\RoundPage\RoundPanel;
use App\Review\UI\RoundsController;
use App\Review\UI\VisibleTicket;
use App\Review\UI\VisibleTicketLookup;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\TicketNotFound;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The verdicts of the review round page's side panel (P05.T7, review-round.md, Side panel): Fixed, Still wrong and
 * Undo on an earlier pin, and Save verdict on a screen area. None moves the ticket. A saved verdict answers a Turbo
 * form with a stream that shows the area's tab and side panel again with the toast, and any other form with a redirect
 * to the area's tab with the toast flashed. A refusal changes nothing and answers with its toast the same two ways, as
 * a 403 or 422 stream.
 *
 * Without the request transaction, as DraftRoundController: each action runs in a transaction of its own, so a refused
 * command leaves nothing. In that order: a ticket the person may not see, a round number it has not, and a round
 * RoundPermission VIEW denies are all the round page's 404; VerdictPermission SET denied is a 403 toast; a form token
 * that is not valid is a 422 toast; a pin or area id the round has not (a malformed one too) is a 404; then the
 * command's refusal is a 422 toast.
 */
final class VerdictController extends AbstractController
{
    public const string PIN_VERDICT_ROUTE = 'review_pin_verdict';
    public const string PIN_UNDO_ROUTE = 'review_pin_verdict_undo';
    public const string AREA_VERDICT_ROUTE = 'review_area_verdict';

    public const string CSRF_FIELD = '_csrf_token';
    /** A pin's fixed or still_wrong, an area's pass, needs_fix or question. */
    public const string VERDICT_FIELD = 'verdict';
    public const string NOTE_FIELD = 'note';
    /** In characters, as the area's note limit the domain enforces; the form only hints it. */
    public const int NOTE_MAX_LENGTH = 1000;

    /** With the team lead's name. */
    public const string VERDICT_SAVED = 'Verdict saved. %s can see it.';
    /** With the pin's number. */
    public const string PIN_MARKED_FIXED = 'Pin %d marked fixed.';
    /** With the pin's number. */
    public const string PIN_MARKED_STILL_WRONG = 'Pin %d marked still wrong.';
    /** With the pin's number. */
    public const string PIN_VERDICT_UNDONE = 'Verdict on pin %d undone.';
    public const string ONLY_TESTER_OR_LEAD = "Only the ticket's tester or the team lead can give verdicts.";
    public const string FORM_EXPIRED = 'This page was open too long. Reload it and try again.';
    /** The toast app.js leaves when a verdict form gets no stream back. */
    public const string SAVE_FAILED = 'Could not save the verdict. Try again.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'review_round_verdict:';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly ReviewRoundQueries $reviewRoundQueries,
        private readonly VerdictCommands $verdictCommands,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of the verdict forms on one ticket's rounds, for csrf_token() in the side panel.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    #[Route('/t/{key}/rounds/{number}/pins/{pinId}/verdict', name: self::PIN_VERDICT_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function pinVerdict(string $key, int $number, string $pinId, Request $request): Response
    {
        $verdict = $request->request->getString(self::VERDICT_FIELD);
        $now = $this->clock->now();

        return $this->respondToVerdict(
            $request,
            $key,
            $number,
            static fn (ReviewRoundView $round): ?AreaView => self::areaWithPin($round, $pinId),
            VerdictRefused::PIN_NOT_FOUND,
            function (VisibleTicket $visible, ReviewRoundView $round, AreaView $area) use ($pinId, $verdict, $now): string {
                $this->verdictCommands->setPinVerdict($round->id, $pinId, $visible->actor->userId(), $verdict, $now);

                return \sprintf(
                    VerdictCommands::PIN_FIXED === $verdict ? self::PIN_MARKED_FIXED : self::PIN_MARKED_STILL_WRONG,
                    self::pinNumberOf($area, $pinId),
                );
            },
        );
    }

    #[Route('/t/{key}/rounds/{number}/pins/{pinId}/undo', name: self::PIN_UNDO_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function undoPinVerdict(string $key, int $number, string $pinId, Request $request): Response
    {
        $now = $this->clock->now();

        return $this->respondToVerdict(
            $request,
            $key,
            $number,
            static fn (ReviewRoundView $round): ?AreaView => self::areaWithPin($round, $pinId),
            VerdictRefused::PIN_NOT_FOUND,
            function (VisibleTicket $visible, ReviewRoundView $round, AreaView $area) use ($pinId, $now): string {
                $this->verdictCommands->undoPinVerdict($round->id, $pinId, $visible->actor->userId(), $now);

                return \sprintf(self::PIN_VERDICT_UNDONE, self::pinNumberOf($area, $pinId));
            },
        );
    }

    #[Route('/t/{key}/rounds/{number}/areas/{areaId}/verdict', name: self::AREA_VERDICT_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function areaVerdict(string $key, int $number, string $areaId, Request $request): Response
    {
        $verdict = $request->request->getString(self::VERDICT_FIELD);
        $note = $request->request->getString(self::NOTE_FIELD);
        $now = $this->clock->now();

        return $this->respondToVerdict(
            $request,
            $key,
            $number,
            static function (ReviewRoundView $round) use ($areaId): ?AreaView {
                foreach ($round->areas as $area) {
                    if ($area->id === strtolower($areaId)) {
                        return $area;
                    }
                }

                return null;
            },
            VerdictRefused::AREA_NOT_FOUND,
            function (VisibleTicket $visible, ReviewRoundView $round, AreaView $area) use ($verdict, $note, $now): string {
                $this->verdictCommands->saveAreaVerdict($round->id, $area->id, $visible->actor->userId(), '' === $verdict ? null : $verdict, $note, $now);

                return \sprintf(self::VERDICT_SAVED, $visible->ticket->turnSnapshot->leadDisplayName ?? 'The team lead');
            },
        );
    }

    /**
     * @param \Closure(ReviewRoundView): ?AreaView                       $targetOf the area the action changes; null for an id the round has not
     * @param string                                                     $notFound the 404's message when $targetOf finds none
     * @param \Closure(VisibleTicket, ReviewRoundView, AreaView): string $change   the verdict, returning its toast
     */
    private function respondToVerdict(Request $request, string $key, int $number, \Closure $targetOf, string $notFound, \Closure $change): Response
    {
        if (null === $this->tenantContext->get()) {
            // Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
            throw $this->createAccessDeniedException();
        }
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $areaPosition = null;

        try {
            $answer = $this->transactions->run(function () use ($key, $number, $csrfToken, $targetOf, $notFound, $change, &$areaPosition): array {
                $visible = $this->visibleTicketLookup->visibleTicketOf($key);
                $round = $this->reviewRoundQueries->roundOf($visible->ticket->id, $number, $visible->actor->userId())
                    ?? throw new NotFoundHttpException(RoundController::NOT_FOUND);
                $facts = new RoundAccessFacts($visible->actor->organizationId(), $round->id, $visible->ticket->id, $round->ownerId, false, false);
                if (!$this->isGranted(RoundPermission::VIEW, $facts)) {
                    throw new NotFoundHttpException(RoundController::NOT_FOUND);
                }
                if (!$this->isGranted(VerdictPermission::SET, $facts)) {
                    throw new VerdictRefusedWithToast(self::ONLY_TESTER_OR_LEAD, Response::HTTP_FORBIDDEN);
                }
                if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
                    throw new VerdictRefusedWithToast(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $area = $targetOf($round) ?? throw new NotFoundHttpException($notFound);
                $areaPosition = $area->position;

                try {
                    $toast = $change($visible, $round, $area);
                } catch (VerdictRefused $refused) {
                    if ($refused->isNotFound()) {
                        throw new NotFoundHttpException($refused->getMessage(), $refused);
                    }
                    throw new VerdictRefusedWithToast($refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $changed = $this->reviewRoundQueries->roundOf($visible->ticket->id, $number, $visible->actor->userId())
                    ?? throw new \LogicException('The round of a saved verdict could not be read again.');
                $changedArea = self::areaById($changed, $area->id);

                return [
                    'toast' => $toast,
                    'position' => $changedArea->position,
                    'tabs' => RoundPages::tabsOf($changed, $changedArea),
                    'panel' => RoundPages::panelOf($visible->ticket, $changed, $changedArea, true),
                ];
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(RoundsController::NOT_FOUND);
        } catch (VerdictRefusedWithToast $refused) {
            return $this->respondToRefusal($request, $key, $number, $areaPosition, $refused);
        }

        return $this->respondToSaved($request, $key, $number, $answer);
    }

    /**
     * @param array{toast: string, position: int, tabs: list<AreaTab>, panel: RoundPanel} $answer
     */
    private function respondToSaved(Request $request, string $key, int $number, array $answer): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToArea($key, $number, $answer['position'], $answer['toast']);
        }

        return $this->render(
            '@Review/round_verdict.stream.html.twig',
            [
                'toast' => $answer['toast'],
                'tabs' => $answer['tabs'],
                'panel' => $answer['panel'],
                'ticketKey' => $key,
                'verdictCsrfId' => self::csrfIdOf($key),
                'roundNumber' => $number,
                'areaParameter' => RoundController::AREA_PARAMETER,
                'ticketRoute' => RoundsController::TICKET_ROUTE,
            ],
            new Response(null, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
        );
    }

    /**
     * A Turbo form gets the toast alone as a stream, which reads nothing; any other form the round page with it.
     */
    private function respondToRefusal(Request $request, string $key, int $number, ?int $areaPosition, VerdictRefusedWithToast $refused): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToArea($key, $number, $areaPosition, $refused->toast());
        }

        return $this->render(
            '@Web/_toast_stream.html.twig',
            ['message' => $refused->toast()],
            new Response(null, $refused->status(), ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
        );
    }

    /**
     * @param int|null $areaPosition the tab to open; the first one when the refusal came before the area was known
     */
    private function redirectToArea(string $key, int $number, ?int $areaPosition, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);
        $parameters = ['key' => $key, 'number' => $number];
        if (null !== $areaPosition) {
            $parameters[RoundController::AREA_PARAMETER] = $areaPosition;
        }

        return $this->redirectToRoute(RoundController::ROUND_ROUTE, $parameters, Response::HTTP_SEE_OTHER);
    }

    /**
     * The area whose "before" picture has the pin; ReviewRoundQueries lists only pins that count, so an AI suggestion
     * nobody kept is no pin to check either.
     */
    private static function areaWithPin(ReviewRoundView $round, string $pinId): ?AreaView
    {
        foreach ($round->areas as $area) {
            foreach ($area->before->pins ?? [] as $pin) {
                if ($pin->id === strtolower($pinId)) {
                    return $area;
                }
            }
        }

        return null;
    }

    private static function pinNumberOf(AreaView $area, string $pinId): int
    {
        foreach ($area->before->pins ?? [] as $pin) {
            if ($pin->id === strtolower($pinId)) {
                return $pin->number;
            }
        }

        throw new \LogicException('The pin is not on the area it was found on.');
    }

    private static function areaById(ReviewRoundView $round, string $areaId): AreaView
    {
        foreach ($round->areas as $area) {
            if ($area->id === $areaId) {
                return $area;
            }
        }

        throw new \LogicException('The area of a saved verdict could not be read again.');
    }
}
