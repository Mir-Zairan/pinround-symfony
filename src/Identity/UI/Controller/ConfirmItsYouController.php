<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\AccountPermission;
use App\Identity\Application\ConfirmItsYou;
use App\Identity\Application\ConfirmItsYouOutcome;
use App\Identity\Application\CurrentSession;
use App\Identity\Application\ShowTwoStepSetup;
use App\Identity\Application\TooManyCurrentPasswordTries;
use App\Identity\Application\TooManyTwoStepCodeTries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Confirm it's you" (ADR-0012): the password and a code from the app, or a recovery code, before an action marked
 * RequiresRecentAuth, then back to it; the password only while the demo switch skips two-step login (ADR-0049). It acts only on the logged-in user and asks OwnAccountVoter for
 * AccountPermission::EDIT_OWN first (ADR-0013). Every response says no-store.
 */
final class ConfirmItsYouController extends AbstractController
{
    /** One message for both, so the page never tells which of the two was right. */
    public const string WRONG_PASSWORD_OR_CODE = 'The password or code is wrong.';
    /** While the demo switch skips two-step login (ADR-0049), the page asks for the password only. */
    public const string WRONG_PASSWORD = 'The password is wrong.';

    private const string CSRF_ID = 'confirm_its_you';

    public function __construct(
        private readonly ConfirmItsYou $confirmItsYou,
        private readonly CurrentSession $currentSession,
        private readonly ShowTwoStepSetup $showTwoStepSetup,
        private readonly RecentAuthRedirects $recentAuthRedirects,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/me/confirm', name: 'identity_confirm', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);

        $userId = $this->currentSession->userId();
        $returnPath = $this->recentAuthRedirects->safeReturnPath($request->query->getString('return')) ?? $this->recentAuthRedirects->homePath();
        if ($this->confirmItsYou->asksForTwoStepCode() && !$this->showTwoStepSetup->stateOf($userId)->isOn()) {
            return self::notStored($this->recentAuthRedirects->toTwoStepSetup($request, $returnPath));
        }
        if (!$request->isMethod('POST')) {
            return $this->renderPage($returnPath);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($returnPath, LoginController::FORM_EXPIRED);
        }

        try {
            $outcome = ($this->confirmItsYou)($userId, $request->request->getString('password'), $request->request->getString('code'), $this->clock->now());
        } catch (TooManyCurrentPasswordTries|TooManyTwoStepCodeTries $refusal) {
            return $this->renderPage($returnPath, LoginController::tooManyTriesFor($refusal->minutesToWait), Response::HTTP_TOO_MANY_REQUESTS);
        }

        return match ($outcome) {
            ConfirmItsYouOutcome::Confirmed => self::notStored($this->redirect($returnPath)),
            ConfirmItsYouOutcome::WrongPasswordOrCode => $this->renderPage($returnPath, self::WRONG_PASSWORD_OR_CODE),
            ConfirmItsYouOutcome::WrongPassword => $this->renderPage($returnPath, self::WRONG_PASSWORD),
            // Turned off since the check above, by another request of this person.
            ConfirmItsYouOutcome::TwoStepLoginOff => self::notStored($this->recentAuthRedirects->toTwoStepSetup($request, $returnPath)),
        };
    }

    private function renderPage(string $returnPath, ?string $error = null, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        return $this->render('@Identity/confirm_its_you.html.twig', [
            'returnPath' => $returnPath,
            'error' => $error,
            'asksForTwoStepCode' => $this->confirmItsYou->asksForTwoStepCode(),
        ], self::notStored(new Response(status: null === $error ? Response::HTTP_OK : $status)));
    }

    private static function notStored(Response $response): Response
    {
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
