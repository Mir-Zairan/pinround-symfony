<?php

declare(strict_types=1);

namespace App\Organization\UI\Admin;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\InvitationQueries;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Application\AlreadyInTheOrganization;
use App\Organization\Application\InvalidEmailAddress;
use App\Organization\Application\InvitationSentMeanwhile;
use App\Organization\Application\InviteMember;
use App\Organization\Application\OrganizationTransactions;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin page of people (FR-ID-02): invite an email address, and see the invitations not joined yet. ADMIN_AREA
 * guards both routes; AbstractController::denyAccessUnlessGranted gives SendAdminToTwoStepProof the attribute and the
 * organization it needs to send an Admin to the two-step proof.
 */
final class PeopleController extends AbstractController
{
    public const string ROUTE = 'organization_admin_people';
    public const string INVITE_ROUTE = 'organization_admin_people_invite';

    /** With the email as typed, trimmed. */
    public const string INVITE_SENT = 'Invite sent to %s';
    public const string EMAIL_INVALID = 'Enter an email address, such as name@company.com.';
    public const string ALREADY_IN = 'This person is in the organization already.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID = 'organization_admin_people_invite';

    public function __construct(
        private readonly InvitationQueries $invitationQueries,
        private readonly InviteMember $inviteMember,
        private readonly OrganizationTransactions $organizationTransactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentUser $currentUser,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/people', name: self::ROUTE, methods: ['GET'])]
    public function show(): Response
    {
        $organizationId = $this->tenantContext->get();
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::INVITE, $organizationId);
        \assert($organizationId instanceof OrganizationId);

        return $this->renderPage($organizationId);
    }

    /**
     * Without the request transaction: when InviteMember refuses a concurrent invite, its DELETE has already run outside
     * the ORM's savepoint, and only rolling back the whole transaction undoes it. runAs gives the invite a transaction of
     * its own, which also covers the access checks and the page shown with a field error.
     */
    #[Route('/admin/people/invite', name: self::INVITE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function invite(Request $request): Response
    {
        $organizationId = $this->tenantContext->get();
        if (null === $organizationId) {
            throw $this->createAccessDeniedException();
        }
        $email = $request->request->getString('email');
        $csrfToken = $request->request->getString('_csrf_token');
        $now = $this->clock->now();

        try {
            return $this->organizationTransactions->runAs($organizationId, function () use ($organizationId, $email, $csrfToken, $now): Response {
                $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
                $this->denyAccessUnlessGranted(OrganizationPermission::INVITE, $organizationId);
                if (!$this->isCsrfTokenValid(self::CSRF_ID, $csrfToken)) {
                    return $this->renderPage($organizationId, $email, formError: self::FORM_EXPIRED);
                }
                $actingUser = $this->currentUser->id();
                \assert(null !== $actingUser);

                try {
                    $this->inviteMember->withoutRoles($organizationId, $email, $actingUser, $now);
                } catch (InvalidEmailAddress) {
                    return $this->renderPage($organizationId, $email, emailError: self::EMAIL_INVALID);
                } catch (AlreadyInTheOrganization) {
                    return $this->renderPage($organizationId, $email, emailError: self::ALREADY_IN);
                }

                return $this->redirectWithInviteSent($email);
            });
        } catch (InvitationSentMeanwhile) {
            // Rolled back here, but the request that got in first committed the same invite.
            return $this->redirectWithInviteSent($email);
        }
    }

    private function redirectWithInviteSent(string $email): Response
    {
        $this->addFlash(self::TOAST, \sprintf(self::INVITE_SENT, trim($email)));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * Reads the pending invitations, so it needs a transaction open under $organizationId.
     */
    private function renderPage(OrganizationId $organizationId, string $typedEmail = '', ?string $emailError = null, ?string $formError = null): Response
    {
        $hasError = null !== $emailError || null !== $formError;

        return $this->render('@Organization/admin/people.html.twig', [
            'pendingInvitations' => $this->invitationQueries->pendingOf($organizationId, InvitationQueries::MAX_LIMIT),
            'typedEmail' => $typedEmail,
            'emailError' => $emailError,
            'formError' => $formError,
        ], new Response(status: $hasError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
