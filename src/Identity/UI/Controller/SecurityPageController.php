<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\AccountPermission;
use App\Identity\Application\ChangePassword;
use App\Identity\Application\CurrentSession;
use App\Identity\Application\LogOutEverywhere;
use App\Identity\Application\PasswordChangeOutcome;
use App\Identity\Application\ShowTwoStepSetup;
use App\Identity\Application\TooManyCurrentPasswordTries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The security page (FR-ID-06): change password, "Log out of all devices" and whether two-step login is on. It acts
 * only on the logged-in user, never on an id from the request; every action asks OwnAccountVoter for
 * AccountPermission::EDIT_OWN first (ADR-0013).
 */
final class SecurityPageController extends AbstractController
{
    public const string PASSWORD_CHANGED = 'Your password is changed. Other devices are logged out.';
    public const string WRONG_CURRENT_PASSWORD = 'The current password is wrong.';
    public const string LOGGED_OUT_EVERYWHERE = 'You are logged out on all devices.';

    private const string PASSWORD_CSRF_ID = 'security_password';
    private const string LOG_OUT_EVERYWHERE_CSRF_ID = 'security_log_out_everywhere';

    public function __construct(
        private readonly ChangePassword $changePassword,
        private readonly LogOutEverywhere $logOutEverywhere,
        private readonly CurrentSession $currentSession,
        private readonly ClockInterface $clock,
        private readonly ShowTwoStepSetup $showTwoStepSetup,
    ) {
    }

    #[Route('/me/security', name: 'identity_security', methods: ['GET'])]
    public function show(): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);

        return $this->renderPage();
    }

    #[Route('/me/security/password', name: 'identity_security_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        if (!$this->isCsrfTokenValid(self::PASSWORD_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage(passwordError: LoginController::FORM_EXPIRED);
        }
        $newPassword = $request->request->getString('new_password');
        if ($newPassword !== $request->request->getString('new_password_repeat')) {
            return $this->renderPage(passwordError: PasswordResetController::PASSWORDS_DIFFER);
        }

        try {
            $outcome = ($this->changePassword)($this->currentSession->userId(), $request->request->getString('current_password'), $newPassword, $this->clock->now());
        } catch (TooManyCurrentPasswordTries $refusal) {
            return $this->renderPage(passwordError: LoginController::tooManyTriesFor($refusal->minutesToWait), status: Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (PasswordChangeOutcome::PasswordTooShort === $outcome) {
            return $this->renderPage(passwordError: \sprintf(PasswordResetController::PASSWORD_TOO_SHORT, ChangePassword::MINIMUM_PASSWORD_LENGTH));
        }
        if (PasswordChangeOutcome::WrongCurrentPassword === $outcome) {
            return $this->renderPage(passwordError: self::WRONG_CURRENT_PASSWORD);
        }

        $this->currentSession->keepAfterPasswordChange();
        $this->addFlash('notice', self::PASSWORD_CHANGED);

        return $this->redirectToRoute('identity_security');
    }

    #[Route('/me/security/log-out-everywhere', name: 'identity_security_log_out_everywhere', methods: ['POST'])]
    public function logOutEverywhere(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        if (!$this->isCsrfTokenValid(self::LOG_OUT_EVERYWHERE_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage(logOutError: LoginController::FORM_EXPIRED);
        }

        ($this->logOutEverywhere)($this->currentSession->userId());
        $this->currentSession->end();
        // On the new, empty session that end() started, so the login page shows it.
        $this->addFlash('notice', self::LOGGED_OUT_EVERYWHERE);

        return $this->redirectToRoute('identity_login');
    }

    private function renderPage(?string $passwordError = null, ?string $logOutError = null, ?int $status = null): Response
    {
        $hasError = null !== $passwordError || null !== $logOutError;

        return $this->render('@Identity/security.html.twig', [
            'passwordError' => $passwordError,
            'logOutError' => $logOutError,
            'minimumPasswordLength' => ChangePassword::MINIMUM_PASSWORD_LENGTH,
            'twoStepLoginState' => $this->showTwoStepSetup->stateOf($this->currentSession->userId()),
        ], new Response(status: $status ?? ($hasError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }
}
