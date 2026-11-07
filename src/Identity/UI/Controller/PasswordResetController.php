<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\PasswordResetOutcome;
use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\ResetPassword;
use App\Identity\Application\TooManyPasswordResetRequests;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Forgot password" and the reset form (FR-ID-08). Public by design (security.yaml): the visitor is logged out, and
 * the emailed token is the proof.
 */
final class PasswordResetController extends AbstractController
{
    /** The same text for a known, unknown, blocked or password-less email, so the page never tells which exist. */
    public const string LINK_SENT = 'If an account uses this email, we sent a link to reset the password. It works for 1 hour.';
    public const string LINK_NO_LONGER_WORKS = 'This link no longer works. Ask for a new one.';
    public const string PASSWORD_CHANGED = 'Your password is changed. Log in with the new one.';
    public const string PASSWORDS_DIFFER = 'The two passwords are not the same.';
    /** With the minimum length. */
    public const string PASSWORD_TOO_SHORT = 'A password needs at least %d characters.';

    // The token waits in the session, so the address bar, the history and any Referer show only /password/reset.
    private const string TOKEN_SESSION_KEY = 'identity.password_reset_token';
    private const string FORGOT_CSRF_ID = 'password_forgot';
    private const string RESET_CSRF_ID = 'password_reset';

    public function __construct(
        private readonly RequestPasswordReset $requestPasswordReset,
        private readonly ResetPassword $resetPassword,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/password/forgot', name: 'identity_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('web_home');
        }
        if (!$request->isMethod('POST')) {
            return $this->renderForgot();
        }
        if (!$this->isCsrfTokenValid(self::FORGOT_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderForgot(errorMessage: LoginController::FORM_EXPIRED, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            ($this->requestPasswordReset)($request->request->getString('email'), (string) $request->getClientIp(), $this->clock->now());
        } catch (TooManyPasswordResetRequests $refusal) {
            return $this->renderForgot(errorMessage: LoginController::tooManyTriesFor($refusal->minutesToWait), status: Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->renderForgot(notice: self::LINK_SENT);
    }

    #[Route('/password/reset/{token}', name: 'identity_password_reset_token', methods: ['GET'])]
    public function keepToken(Request $request, string $token): Response
    {
        $request->getSession()->set(self::TOKEN_SESSION_KEY, $token);

        return $this->redirectToRoute('identity_password_reset');
    }

    #[Route('/password/reset', name: 'identity_password_reset', methods: ['GET', 'POST'])]
    public function reset(Request $request): Response
    {
        $session = $request->getSession();
        $token = $session->get(self::TOKEN_SESSION_KEY);
        if (!\is_string($token) || !$this->resetPassword->isLinkUsable($token, $this->clock->now())) {
            $session->remove(self::TOKEN_SESSION_KEY);

            return $this->renderLinkNoLongerWorks();
        }
        if (!$request->isMethod('POST')) {
            return $this->renderResetForm();
        }
        if (!$this->isCsrfTokenValid(self::RESET_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderResetForm(LoginController::FORM_EXPIRED);
        }
        $password = $request->request->getString('password');
        if ($password !== $request->request->getString('password_repeat')) {
            return $this->renderResetForm(self::PASSWORDS_DIFFER);
        }

        $outcome = ($this->resetPassword)($token, $password, $this->clock->now());
        if (PasswordResetOutcome::PasswordTooShort === $outcome) {
            return $this->renderResetForm(\sprintf(self::PASSWORD_TOO_SHORT, ResetPassword::MINIMUM_PASSWORD_LENGTH));
        }
        $session->remove(self::TOKEN_SESSION_KEY);
        if (PasswordResetOutcome::LinkNoLongerWorks === $outcome) {
            return $this->renderLinkNoLongerWorks();
        }

        $this->addFlash('notice', self::PASSWORD_CHANGED);

        return $this->redirectToRoute('identity_login');
    }

    private function renderForgot(?string $notice = null, ?string $errorMessage = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('@Identity/password_forgot.html.twig', [
            'notice' => $notice,
            'errorMessage' => $errorMessage,
        ], new Response(status: $status));
    }

    /**
     * One answer for an unknown, used and expired link.
     */
    private function renderLinkNoLongerWorks(): Response
    {
        return $this->render('@Identity/password_reset.html.twig', ['linkProblem' => self::LINK_NO_LONGER_WORKS, 'errorMessage' => null]);
    }

    private function renderResetForm(?string $errorMessage = null): Response
    {
        return $this->render('@Identity/password_reset.html.twig', [
            'linkProblem' => null,
            'errorMessage' => $errorMessage,
            'minimumPasswordLength' => ResetPassword::MINIMUM_PASSWORD_LENGTH,
        ], new Response(status: null === $errorMessage ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
