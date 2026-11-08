<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\AcceptTwoStepCode;
use App\Identity\Application\PendingLogin;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Application\TooManyTwoStepCodeTries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Login step two (FR-ID-03, ADR-0012): after the right password of a user whose two-step login is on, a code from the
 * authenticator app or one recovery code logs them in. The page is public, like the login page; the pending login in
 * the session is the proof, and without it the page sends the visitor to log in. Every response says no-store.
 */
final class LoginCodeController extends AbstractController
{
    public const string WRONG_CODE = 'That code does not work. Type the code your app shows now, or a recovery code you have not used.';
    /** With the minutes a pending login waits for its code. */
    public const string LOG_IN_AGAIN = 'Log in again, then type your code within %d minutes.';

    private const string CSRF_ID = 'login_code';

    public function __construct(
        private readonly PendingLogin $pendingLogin,
        private readonly AcceptTwoStepCode $acceptTwoStepCode,
        private readonly SessionConfirmations $sessionConfirmations,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/login/code', name: 'identity_login_code', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if (null !== $this->getUser()) {
            return self::notStored($this->redirectToRoute('web_home'));
        }

        $now = $this->clock->now();
        $userId = $this->pendingLogin->userIdAt($now);
        if (null === $userId) {
            return $this->logInAgain();
        }
        if (!$request->isMethod('POST')) {
            return $this->renderPage();
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage(LoginController::FORM_EXPIRED);
        }
        if ($request->request->has('cancel')) {
            $this->pendingLogin->forget();

            return self::notStored($this->redirectToRoute('identity_login'));
        }

        try {
            $isAccepted = ($this->acceptTwoStepCode)($userId, $request->request->getString('code'), $now);
        } catch (TooManyTwoStepCodeTries $refusal) {
            return $this->renderPage(LoginController::tooManyTriesFor($refusal->minutesToWait), Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$isAccepted) {
            return $this->renderPage(self::WRONG_CODE);
        }

        $targetUrl = $this->pendingLogin->finish($userId);
        if (null === $targetUrl) {
            return $this->logInAgain();
        }
        // After the login: its new session id keeps the attributes.
        $this->sessionConfirmations->recordRecentAuth($now);
        $this->sessionConfirmations->recordTwoStepConfirmation($now);

        return self::notStored($this->redirect($targetUrl));
    }

    private function logInAgain(): Response
    {
        $this->pendingLogin->forget();
        $this->addFlash('notice', \sprintf(self::LOG_IN_AGAIN, intdiv(PendingLogin::PENDING_LOGIN_SECONDS, 60)));

        return self::notStored($this->redirectToRoute('identity_login'));
    }

    private function renderPage(?string $error = null, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        return $this->render('@Identity/login_code.html.twig', [
            'error' => $error,
        ], self::notStored(new Response(status: null === $error ? Response::HTTP_OK : $status)));
    }

    private static function notStored(Response $response): Response
    {
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
