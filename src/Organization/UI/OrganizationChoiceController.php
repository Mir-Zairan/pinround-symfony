<?php

declare(strict_types=1);

namespace App\Organization\UI;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Application\OrganizationNames;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organization picker, /org/choose. It runs without the request transaction: the resolver gives ids only, so
 * OrganizationNames reads each name in a short transaction of its own with that organization as the tenant
 * (data-model.md, lookups before the tenant is known). Every action asks OrganizationMemberVoter first (ADR-0013). A
 * choice is accepted only for an organization of the logged-in user (OrganizationPermission::ENTER) and stored in the
 * session for EnterChosenOrganization. It keeps the session id: a choice grants nothing the memberships do not, and a
 * migration would restamp the session's created time, so switching would push back the 30-day limit (ADR-0012).
 */
final class OrganizationChoiceController extends AbstractController
{
    public const string ROUTE = 'organization_choose';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';

    private const string CSRF_ID = 'organization_choose';

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly MembershipQueries $membershipQueries,
        private readonly OrganizationNames $organizationNames,
    ) {
    }

    #[Route('/org/choose', name: self::ROUTE, methods: ['GET', 'POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function choose(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::CHOOSE);
        if (!$request->isMethod('POST')) {
            return $this->renderPage($request);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($request, self::FORM_EXPIRED);
        }
        try {
            $organizationId = new OrganizationId($request->request->getString('organization_id'));
        } catch (\InvalidArgumentException) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(OrganizationPermission::ENTER, $organizationId);

        $request->getSession()->set(EnterChosenOrganization::SESSION_KEY, $organizationId->toString());

        return $this->redirectToRoute('web_home');
    }

    private function renderPage(Request $request, ?string $formError = null): Response
    {
        $userId = $this->currentUser->id() ?? throw new \LogicException('The firewall lets only logged-in users here.');
        $organizationNames = $this->organizationNames->namesOf($this->membershipQueries->organizationsOf($userId));

        return $this->render('@Organization/choose.html.twig', [
            'organizationNames' => $organizationNames,
            'chosenOrganizationId' => $request->getSession()->get(EnterChosenOrganization::SESSION_KEY),
            'formError' => $formError,
        ], new Response(status: null === $formError ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
