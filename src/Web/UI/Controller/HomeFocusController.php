<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Web\Application\ChooseHomeFocus;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves the Home focus chip a person picks (FR-HOME-08) for them in the organization the request works in. The user
 * and the organization come from the request's actor, never from the form; OrganizationVoter decides ORG_VIEW on that
 * organization first (ADR-0013).
 */
final class HomeFocusController extends AbstractController
{
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string FOCUS_NOT_OFFERED = 'Pick one of the focus chips on Home.';

    // The Home page's chips form names it too.
    private const string CSRF_ID = 'web_home_focus';

    public function __construct(
        private readonly ChooseHomeFocus $chooseHomeFocus,
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The chips' script asks with X-Requested-With and takes only 204 as saved, so the firewall's redirect to the login
     * page counts as a failure; a plain form post is redirected to Home.
     */
    #[Route('/home/focus', name: 'web_home_focus', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Without an organization the subject is null, which no voter decides, so the request is denied.
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        if (!($this->chooseHomeFocus)($actor, $request->request->getString('focus'), $this->clock->now())) {
            return self::refusal(self::FOCUS_NOT_OFFERED);
        }

        if ($request->isXmlHttpRequest()) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('web_home', status: Response::HTTP_SEE_OTHER);
    }

    private static function refusal(string $message): Response
    {
        return new Response($message, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
