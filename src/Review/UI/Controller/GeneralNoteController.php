<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use App\Review\Api\GeneralNoteCommands;
use App\Review\Api\GeneralNoteRefused;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\UI\RoundsController;
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

/**
 * "Add note" in "General notes on this round" on a shared round's page (ADR-0048, note of 2026-11-22). An added note
 * answers with a redirect back to the section with "Note added." flashed. A refusal changes nothing and answers a
 * Turbo form with its toast alone as a 403 or 422 stream, so the typed note stays in the form, and any other form with
 * the same redirect and the toast flashed (RefusalAnswers).
 *
 * Without the request transaction, as VerdictController: the action runs in a transaction of its own, so a refused
 * note leaves nothing. In that order: nobody logged in is sent to log in; a ticket the person may not see, a round
 * number it has not, and a round RoundPermission VIEW denies are the round page's 404; RoundPermission ADD_GENERAL_NOTE
 * denied is a 403 toast; a form token that is not valid is a 422 toast; then GeneralNoteCommands' refusal is a 404
 * for a round that takes no notes at all and a 422 toast otherwise.
 */
final class GeneralNoteController extends AbstractController
{
    public const string ROUTE = 'review_round_general_note';
    /** The id of the section on the round page, which an added or refused note comes back to. */
    public const string SECTION_ID = 'round-general-notes';

    public const string CSRF_FIELD = '_csrf_token';
    /** Pass, needs_fix or question; empty for none. */
    public const string VERDICT_FIELD = 'verdict';
    public const string NOTE_FIELD = 'note';
    /** The screenshot number the page showed, which the answer shows again; anything but a whole number shows the first. */
    public const string RETURN_SCREENSHOT_FIELD = 'screenshot';
    /** The form only hints it; GeneralNote enforces it. */
    public const int NOTE_MAX_LENGTH = GeneralNoteCommands::NOTE_MAX_LENGTH;

    public const string NOTE_ADDED = 'Note added.';
    public const string MAY_NOT_ADD = 'You cannot add notes to this round.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'review_round_general_note:';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly ReviewRoundQueries $reviewRoundQueries,
        private readonly GeneralNoteCommands $generalNoteCommands,
        private readonly RefusalAnswers $refusalAnswers,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of the general note forms on one ticket's rounds, for csrf_token() on the round page.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    #[Route('/t/{key}/rounds/{number}/notes', name: self::ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function add(string $key, int $number, Request $request): Response
    {
        if (null === $this->tenantContext->get()) {
            // Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
            throw $this->createAccessDeniedException();
        }
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $verdict = $request->request->getString(self::VERDICT_FIELD);
        $note = $request->request->getString(self::NOTE_FIELD);
        $now = $this->clock->now();
        $screenshotNumber = RoundController::positionOf($request->request->get(self::RETURN_SCREENSHOT_FIELD));

        try {
            $this->transactions->run(function () use ($key, $number, $csrfToken, $verdict, $note, $now): void {
                $visible = $this->visibleTicketLookup->visibleTicketOf($key);
                $round = $this->reviewRoundQueries->roundOf($visible->ticket->id, $number, $visible->actor->userId())
                    ?? throw new NotFoundHttpException(RoundController::NOT_FOUND);
                $facts = new RoundAccessFacts($visible->actor->organizationId(), $round->id, $visible->ticket->id, $round->ownerId, false, false);
                if (!$this->isGranted(RoundPermission::VIEW, $facts)) {
                    throw new NotFoundHttpException(RoundController::NOT_FOUND);
                }
                if (!$this->isGranted(RoundPermission::ADD_GENERAL_NOTE, $facts)) {
                    throw new RefusedWithToast(self::MAY_NOT_ADD, Response::HTTP_FORBIDDEN);
                }
                if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
                    throw RefusedWithToast::formExpired();
                }

                try {
                    $this->generalNoteCommands->add($round->id, $visible->actor->userId(), $verdict, $note, $now);
                } catch (GeneralNoteRefused $refused) {
                    if ($refused->isNotFound()) {
                        throw new NotFoundHttpException(RoundController::NOT_FOUND, $refused);
                    }
                    throw new RefusedWithToast($refused->getMessage());
                }
            });
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(RoundsController::NOT_FOUND);
        } catch (RefusedWithToast $refused) {
            return $this->refusalAnswers->answer($request, $refused, fn (string $toast): Response => $this->redirectToSection($key, $number, $screenshotNumber, $toast));
        }

        return $this->redirectToSection($key, $number, $screenshotNumber, self::NOTE_ADDED);
    }

    /**
     * @param int|null $screenshotNumber the screenshot to show again; none, or 0, shows the first
     */
    private function redirectToSection(string $key, int $number, ?int $screenshotNumber, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);
        $parameters = ['key' => $key, 'number' => $number, '_fragment' => self::SECTION_ID];
        if (null !== $screenshotNumber && $screenshotNumber > 0) {
            $parameters[RoundController::SCREENSHOT_PARAMETER] = $screenshotNumber;
        }

        return $this->redirectToRoute(RoundController::ROUND_ROUTE, $parameters, Response::HTTP_SEE_OTHER);
    }
}
