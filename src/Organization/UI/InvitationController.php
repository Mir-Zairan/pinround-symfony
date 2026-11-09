<?php

declare(strict_types=1);

namespace App\Organization\UI;

use App\Identity\Api\CurrentUser;
use App\Identity\Api\InvitedPersonRegistration;
use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Application\AcceptInvitation;
use App\Organization\Application\AlreadyJoined;
use App\Organization\Application\InvitationForAnotherPerson;
use App\Organization\Application\InvitationLinks;
use App\Organization\Application\InvitationNoLongerValid;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Application\OrganizationTransactions;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * The page behind an emailed invite link (FR-ID-02). Public by design (security.yaml): the link token is the proof,
 * and InvitationVoter decides who may accept it. It runs without the request transaction, as InvitationLinks::find()
 * needs none open and the accept writes under the link's organization, which is not yet the request's tenant.
 */
final class InvitationController extends AbstractController
{
    use TargetPathTrait;

    public const string ROUTE = 'organization_invitation';
    public const string TOKEN_ROUTE = 'organization_invitation_token';

    public const string NO_LONGER_VALID = 'This invite is no longer valid. Ask your admin for a new one.';
    public const string FOR_ANOTHER_EMAIL = 'This invite is for another email. Log out and open the link again.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    /** With the organization's name. */
    public const string ACCOUNT_READY = 'You joined %s. Log in with your new password.';
    /** With the organization's name. */
    public const string JOINED = 'You joined %s.';
    /** With the organization's name. */
    public const string ALREADY_IN = 'You are in %s already.';
    public const string NAME_BLANK = 'Enter your name.';
    /** With the maximum length. */
    public const string NAME_TOO_LONG = 'A name can have at most %d characters.';
    /** With the minimum length. */
    public const string PASSWORD_TOO_SHORT = 'A password needs at least %d characters.';
    public const string EMAIL_TAKEN = 'An account with this email exists now. Log in to join.';
    public const string EMAIL_UNUSABLE = 'This invite has an email we cannot use. Ask your admin for a new one.';

    // The token waits in the session, so the address bar, the history and any Referer show only /invite.
    private const string TOKEN_SESSION_KEY = 'organization.invitation_token';
    private const string CSRF_ID = 'organization_invitation_accept';
    private const string FIREWALL = 'main';

    public function __construct(
        private readonly InvitationLinks $invitationLinks,
        private readonly AcceptInvitation $acceptInvitation,
        private readonly InvitedPersonRegistration $registration,
        private readonly CurrentUser $currentUser,
        private readonly OrganizationTransactions $organizationTransactions,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/invite/{token}', name: self::TOKEN_ROUTE, methods: ['GET'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function keepToken(Request $request, string $token): Response
    {
        $request->getSession()->set(self::TOKEN_SESSION_KEY, $token);

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/invite', name: self::ROUTE, methods: ['GET', 'POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function accept(Request $request): Response
    {
        $session = $request->getSession();
        $token = $session->get(self::TOKEN_SESSION_KEY);
        $link = \is_string($token) ? $this->invitationLinks->find($token) : null;
        if (!\is_string($token) || null === $link) {
            return $this->renderNoLongerValid($session);
        }
        if (!$this->isGranted(OrganizationPermission::ACCEPT_INVITATION, $link)) {
            return $this->renderRefusal($session, $link, $request->isMethod('POST'));
        }
        if (!$request->isMethod('POST')) {
            return $this->renderAcceptForm($link);
        }
        $name = $request->request->getString('name');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderAcceptForm($link, [self::FORM_EXPIRED], $name);
        }

        $userId = $this->currentUser->id();
        $now = $this->clock->now();
        try {
            if (null === $userId) {
                $password = $request->request->getString('password');
                $this->organizationTransactions->runAs($link->organizationId, fn () => $this->acceptInvitation->acceptAsNewPerson($link, $token, $name, $password, $now));
            } else {
                $this->organizationTransactions->runAs($link->organizationId, fn () => $this->acceptInvitation->acceptAs($link, $token, $userId, $now));
            }
        } catch (InvitationNoLongerValid) {
            return $this->renderNoLongerValid($session);
        } catch (InvitationForAnotherPerson) {
            return $this->renderForAnotherEmail();
        } catch (AlreadyJoined) {
            return $this->enterJoinedOrganization($session, $link, self::ALREADY_IN);
        } catch (RegistrationRefused $refused) {
            // The EntityManager may be closed now (InvitedPersonRegistration::register), so the page reads nothing.
            return $this->renderRegistrationRefused($session, $link, $refused, $name);
        }
        $session->remove(self::TOKEN_SESSION_KEY);

        if (null === $userId) {
            // No automatic login: the new person logs in like everyone else, so the login rules all apply.
            $this->addFlash('notice', \sprintf(self::ACCOUNT_READY, $link->organizationName));

            return $this->redirectToRoute('identity_login');
        }

        return $this->enterJoinedOrganization($session, $link, self::JOINED);
    }

    private function enterJoinedOrganization(SessionInterface $session, OpenInvitationLink $link, string $notice): Response
    {
        $session->remove(self::TOKEN_SESSION_KEY);
        // EnterChosenOrganization checks this choice against the memberships at the next request.
        $session->set(EnterChosenOrganization::SESSION_KEY, $link->organizationId->toString());
        $this->addFlash('notice', \sprintf($notice, $link->organizationName));

        return $this->redirectToRoute('web_home');
    }

    /**
     * Logged out, the voter refuses only when an account has the invited email; one made since the form opened gets
     * the same message as a registration that finds it.
     */
    private function renderRefusal(SessionInterface $session, OpenInvitationLink $link, bool $isJoinSubmitted): Response
    {
        if (null !== $this->currentUser->id()) {
            return $this->renderForAnotherEmail();
        }

        return $this->renderLogInToJoin($session, $link, $isJoinSubmitted ? [self::EMAIL_TAKEN] : []);
    }

    /**
     * The person has an account, so they log in first; the login brings them back here, where the token still waits.
     *
     * @param list<string> $errors
     */
    private function renderLogInToJoin(SessionInterface $session, OpenInvitationLink $link, array $errors = []): Response
    {
        $this->saveTargetPath($session, self::FIREWALL, $this->generateUrl(self::ROUTE));

        return $this->renderPage('log_in_to_join', $link->organizationName, $errors);
    }

    private function renderForAnotherEmail(): Response
    {
        return $this->renderPage('for_another_email', null, [self::FOR_ANOTHER_EMAIL], Response::HTTP_FORBIDDEN);
    }

    private function renderNoLongerValid(SessionInterface $session): Response
    {
        $session->remove(self::TOKEN_SESSION_KEY);

        return $this->renderPage('no_longer_valid', null, [self::NO_LONGER_VALID]);
    }

    /**
     * @param list<string> $errors
     */
    private function renderAcceptForm(OpenInvitationLink $link, array $errors = [], string $typedName = ''): Response
    {
        $status = [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;
        if (null === $this->currentUser->id()) {
            return $this->renderPage('new_person', $link->organizationName, $errors, $status, $link->invitedEmail, $typedName);
        }

        return $this->renderPage('join', $link->organizationName, $errors, $status, $link->invitedEmail);
    }

    private function renderRegistrationRefused(SessionInterface $session, OpenInvitationLink $link, RegistrationRefused $refused, string $typedName): Response
    {
        if (\in_array(RegistrationRefusal::EmailTaken, $refused->refusals, true)) {
            return $this->renderLogInToJoin($session, $link, [self::EMAIL_TAKEN]);
        }

        $errors = array_map(fn (RegistrationRefusal $refusal): string => match ($refusal) {
            RegistrationRefusal::InvalidEmail => self::EMAIL_UNUSABLE,
            RegistrationRefusal::NameBlank => self::NAME_BLANK,
            RegistrationRefusal::NameTooLong => \sprintf(self::NAME_TOO_LONG, $this->registration->maximumNameLength()),
            RegistrationRefusal::PasswordTooShort => \sprintf(self::PASSWORD_TOO_SHORT, $this->registration->minimumPasswordLength()),
        }, $refused->refusals);

        return $this->renderAcceptForm($link, $errors, $typedName);
    }

    /**
     * @param 'no_longer_valid'|'for_another_email'|'log_in_to_join'|'new_person'|'join' $view
     * @param list<string>                                                               $errors
     */
    private function renderPage(string $view, ?string $organizationName, array $errors, int $status = Response::HTTP_OK, ?string $invitedEmail = null, string $typedName = ''): Response
    {
        return $this->render('@Organization/invitation.html.twig', [
            'view' => $view,
            'organizationName' => $organizationName,
            'invitedEmail' => $invitedEmail,
            'typedName' => $typedName,
            'errors' => $errors,
            'minimumPasswordLength' => $this->registration->minimumPasswordLength(),
            'maximumNameLength' => $this->registration->maximumNameLength(),
        ], new Response(status: $status));
    }
}
