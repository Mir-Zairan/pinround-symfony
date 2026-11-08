<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\AccountPermission;
use App\Identity\Application\ConfirmTwoStepSetup;
use App\Identity\Application\CurrentSession;
use App\Identity\Application\QrCodeImages;
use App\Identity\Application\RecoveryCodes;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Application\ShowTwoStepSetup;
use App\Identity\Application\StartTwoStepSetup;
use App\Identity\Application\TwoStepConfirmRefusal;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Turning two-step login on (FR-ID-03, ADR-0012): a QR code and the key of a new seed, then a code from the app, then
 * the recovery codes once. It acts only on the logged-in user; every action asks OwnAccountVoter for
 * AccountPermission::EDIT_OWN first (ADR-0013). Every page here says no-store: they show the seed or the codes.
 */
final class TwoStepLoginController extends AbstractController
{
    public const string WRONG_CODE = 'The code is wrong. Type the code your app shows now.';

    private const string START_CSRF_ID = 'two_step_start';
    private const string CONFIRM_CSRF_ID = 'two_step_confirm';

    public function __construct(
        private readonly ShowTwoStepSetup $showTwoStepSetup,
        private readonly StartTwoStepSetup $startTwoStepSetup,
        private readonly ConfirmTwoStepSetup $confirmTwoStepSetup,
        private readonly QrCodeImages $qrCodeImages,
        private readonly CurrentSession $currentSession,
        private readonly SessionConfirmations $sessionConfirmations,
        private readonly RecentAuthRedirects $recentAuthRedirects,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/me/security/two-step', name: 'identity_two_step', methods: ['GET'])]
    public function show(): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);

        return $this->renderSetup();
    }

    #[Route('/me/security/two-step/start', name: 'identity_two_step_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        if (!$this->isCsrfTokenValid(self::START_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderSetup(LoginController::FORM_EXPIRED);
        }

        ($this->startTwoStepSetup)($this->currentSession->userId(), $this->clock->now());

        return $this->redirectToRoute('identity_two_step');
    }

    #[Route('/me/security/two-step/confirm', name: 'identity_two_step_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        if (!$this->isCsrfTokenValid(self::CONFIRM_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderSetup(LoginController::FORM_EXPIRED);
        }

        $now = $this->clock->now();
        $userId = $this->currentSession->userId();
        $outcome = ($this->confirmTwoStepSetup)($userId, $request->request->getString('code'), $now);
        if (TwoStepConfirmRefusal::WrongCode === $outcome) {
            return $this->renderSetup(self::WRONG_CODE);
        }
        if (!$outcome instanceof RecoveryCodes) {
            // Not started, or already on: the page shows what there is to do now.
            return $this->redirectToRoute('identity_two_step');
        }
        // The right code is proof of two-step login, as at a login with a code.
        $this->sessionConfirmations->recordTwoStepConfirmation($userId, $now);

        // Rendered in this response and never stored anywhere in plain text, so a reload cannot show them again.
        return $this->render('@Identity/two_step_recovery_codes.html.twig', [
            'recoveryCodes' => $outcome->reveal(),
            // Set when an action needing a recent proof sent the person here (AskToConfirmItsYou).
            'continuePath' => $this->recentAuthRedirects->takeAfterTwoStepSetup($request),
        ], self::notStored(new Response()));
    }

    private function renderSetup(?string $error = null): Response
    {
        $setup = ($this->showTwoStepSetup)($this->currentSession->userId());

        return $this->render('@Identity/two_step.html.twig', [
            'setup' => $setup,
            // An image from a data: URI, not markup in the page, so Twig escapes it like any other value.
            'qrCodeSource' => null === $setup->provisioningUri ? null : 'data:image/svg+xml;base64,'.base64_encode($this->qrCodeImages->svgOf($setup->provisioningUri)),
            'error' => $error,
        ], self::notStored(new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    private static function notStored(Response $response): Response
    {
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
