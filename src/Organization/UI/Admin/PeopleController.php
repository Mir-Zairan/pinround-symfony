<?php

declare(strict_types=1);

namespace App\Organization\UI\Admin;

use App\Identity\Api\ApiTokenNotInOrganization;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\OrganizationApiTokens;
use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\InvitationQueries;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\PendingInvitation;
use App\Organization\Application\AlreadyInTheOrganization;
use App\Organization\Application\InvalidEmailAddress;
use App\Organization\Application\InvitationSentMeanwhile;
use App\Organization\Application\InviteMember;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The admin page of people (FR-ID-02): invite an email address, see the invitations not joined yet, and see and revoke
 * the organization's live API tokens (P09.T1). ADMIN_AREA guards every route; AbstractController::denyAccessUnlessGranted gives SendAdminToTwoStepProof the attribute and the
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

    public const int PAGE_SIZE = 20;
    /** The query parameters of the next page: the invitedAt and id of the last invitation shown, both or neither. */
    public const string AFTER_INVITED_AT = 'after_invited_at';
    public const string AFTER_ID = 'after_id';

    public const string REVOKE_TOKEN_ROUTE = 'organization_admin_people_token_revoke';
    public const string TOKEN_REVOKED = 'The token is revoked. It no longer works.';
    /** The query parameters of the next page of API tokens: the expiresAt and id of the last token shown, both or neither. */
    public const string TOKENS_AFTER_EXPIRES_AT = 'tokens_after_expires_at';
    public const string TOKENS_AFTER_ID = 'tokens_after_id';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID = 'organization_admin_people_invite';
    private const string REVOKE_TOKEN_CSRF_ID = 'organization_admin_people_token_revoke';
    /** With microseconds and the offset, so the cursor names the exact created_at the database holds. */
    private const string CURSOR_TIME_FORMAT = 'Y-m-d\TH:i:s.uP';
    /** expires_at keeps whole seconds, so its cursor needs none. */
    private const string TOKEN_CURSOR_TIME_FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(
        private readonly InvitationQueries $invitationQueries,
        private readonly InviteMember $inviteMember,
        private readonly OrganizationApiTokens $organizationApiTokens,
        private readonly UserQueries $userQueries,
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentUser $currentUser,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The page after the invitation that AFTER_INVITED_AT and AFTER_ID name, and after the API token that
     * TOKENS_AFTER_EXPIRES_AT and TOKENS_AFTER_ID name, or the first page of either without them.
     */
    #[Route('/admin/people', name: self::ROUTE, methods: ['GET'])]
    public function show(Request $request): Response
    {
        $organizationId = $this->tenantContext->get();
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::INVITE, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::ADMIN, $organizationId);
        \assert($organizationId instanceof OrganizationId);

        return $this->renderPage($organizationId, after: $this->pageCursorOf($request->query), tokensAfter: $this->tokenPageCursorOf($request->query));
    }

    /**
     * Revokes any person's token of the organization, in the request transaction with its ApiTokenRevoked, and goes
     * back to the pages of both lists the form names in the same parameters as show. The id is not checked by the
     * route, so a malformed one gets the same 404 as another organization's. A token revoked already, as after a double
     * click, says nothing: the list no longer shows it.
     */
    #[Route('/admin/people/tokens/{id}/revoke', name: self::REVOKE_TOKEN_ROUTE, methods: ['POST'])]
    public function revokeToken(Request $request, string $id): Response
    {
        $organizationId = $this->tenantContext->get();
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::ADMIN, $organizationId);
        \assert($organizationId instanceof OrganizationId);
        $after = $this->pageCursorOf($request->request);
        $tokensAfter = $this->tokenPageCursorOf($request->request);
        if (!$this->isCsrfTokenValid(self::REVOKE_TOKEN_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($organizationId, formError: self::FORM_EXPIRED, after: $after, tokensAfter: $tokensAfter);
        }
        $admin = $this->currentUser->id();
        \assert(null !== $admin);

        try {
            $isRevokedNow = $this->organizationApiTokens->revokeAsAdmin($admin, $id, $this->clock->now());
        } catch (ApiTokenNotInOrganization $notFound) {
            throw $this->createNotFoundException($notFound->getMessage(), $notFound);
        }
        if ($isRevokedNow) {
            $this->addFlash(self::TOAST, self::TOKEN_REVOKED);
        }

        return $this->redirectToRoute(self::ROUTE, [...$this->invitationsPageQueryOf($after), ...$this->tokensPageQueryOf($tokensAfter)]);
    }

    /**
     * Without the request transaction: when InviteMember refuses a concurrent invite, its DELETE has already run outside
     * the ORM's savepoint, and only rolling back the whole transaction undoes it. Transactions gives the invite a
     * transaction of its own, which also covers the access checks and the page shown with a field error.
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
            return $this->transactions->run(function () use ($organizationId, $email, $csrfToken, $now): Response {
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
     * The cursor pendingOf reads, which needs only the id and invitedAt; null when the request names no page.
     *
     * @param InputBag<string> $parameters the query of show, or the form of revokeToken
     *
     * @throws BadRequestHttpException when only one of the two parameters is given, or either is no valid value
     */
    private function pageCursorOf(InputBag $parameters): ?PendingInvitation
    {
        $hasInvitedAt = $parameters->has(self::AFTER_INVITED_AT);
        if (!$hasInvitedAt && !$parameters->has(self::AFTER_ID)) {
            return null;
        }
        $invitedAtText = $parameters->getString(self::AFTER_INVITED_AT);
        $id = $parameters->getString(self::AFTER_ID);
        $invitedAt = \DateTimeImmutable::createFromFormat('!'.self::CURSOR_TIME_FORMAT, $invitedAtText);
        // The round trip refuses what createFromFormat rolls over, such as a 31 November, instead of reading another time.
        if (!$hasInvitedAt || false === $invitedAt || $invitedAt->format(self::CURSOR_TIME_FORMAT) !== $invitedAtText || !Uuid::isValid($id)) {
            throw new BadRequestHttpException('The page of invitations to show is not valid.');
        }

        return new PendingInvitation($id, '', $invitedAt, $invitedAt);
    }

    /**
     * @param InputBag<string> $parameters the query of show, or the form of revokeToken
     *
     * @return array{\DateTimeImmutable, string}|null the expiresAt and id of the last API token shown; null when the
     *                                                request names no page of tokens
     *
     * @throws BadRequestHttpException when only one of the two parameters is given, or either is no valid value
     */
    private function tokenPageCursorOf(InputBag $parameters): ?array
    {
        $hasExpiresAt = $parameters->has(self::TOKENS_AFTER_EXPIRES_AT);
        if (!$hasExpiresAt && !$parameters->has(self::TOKENS_AFTER_ID)) {
            return null;
        }
        $expiresAtText = $parameters->getString(self::TOKENS_AFTER_EXPIRES_AT);
        $id = $parameters->getString(self::TOKENS_AFTER_ID);
        $expiresAt = \DateTimeImmutable::createFromFormat('!'.self::TOKEN_CURSOR_TIME_FORMAT, $expiresAtText);
        if (!$hasExpiresAt || false === $expiresAt || $expiresAt->format(self::TOKEN_CURSOR_TIME_FORMAT) !== $expiresAtText || !Uuid::isValid($id)) {
            throw new BadRequestHttpException('The page of API tokens to show is not valid.');
        }

        return [$expiresAt, $id];
    }

    /**
     * Reads the pending invitations and the live API tokens, so it needs a transaction open under $organizationId.
     *
     * @param array{\DateTimeImmutable, string}|null $tokensAfter the expiresAt and id of the last API token shown
     */
    private function renderPage(OrganizationId $organizationId, string $typedEmail = '', ?string $emailError = null, ?string $formError = null, ?PendingInvitation $after = null, ?array $tokensAfter = null): Response
    {
        $hasError = null !== $emailError || null !== $formError;
        // Each list's Show more keeps the page the other list is on.
        $invitationsPageQuery = $this->invitationsPageQueryOf($after);
        $tokensPageQuery = $this->tokensPageQueryOf($tokensAfter);
        $pendingInvitations = $this->invitationQueries->pendingOf($organizationId, self::PAGE_SIZE + 1, $after);
        $nextPage = null;
        if (\count($pendingInvitations) > self::PAGE_SIZE) {
            $pendingInvitations = \array_slice($pendingInvitations, 0, self::PAGE_SIZE);
            $lastShown = $pendingInvitations[self::PAGE_SIZE - 1];
            $nextPage = [
                ...$tokensPageQuery,
                self::AFTER_INVITED_AT => $lastShown->invitedAt()->format(self::CURSOR_TIME_FORMAT),
                self::AFTER_ID => $lastShown->id(),
            ];
        }
        $liveTokens = $this->organizationApiTokens->livePage($this->clock->now(), self::PAGE_SIZE + 1, $tokensAfter[0] ?? null, $tokensAfter[1] ?? null);
        $tokensNextPage = null;
        if (\count($liveTokens) > self::PAGE_SIZE) {
            $liveTokens = \array_slice($liveTokens, 0, self::PAGE_SIZE);
            $lastShownToken = $liveTokens[self::PAGE_SIZE - 1];
            $tokensNextPage = [
                ...$invitationsPageQuery,
                self::TOKENS_AFTER_EXPIRES_AT => $lastShownToken->expiresAt->format(self::TOKEN_CURSOR_TIME_FORMAT),
                self::TOKENS_AFTER_ID => $lastShownToken->id,
            ];
        }
        $ownerIds = [];
        foreach ($liveTokens as $token) {
            $ownerIds[$token->ownerId->toString()] = $token->ownerId;
        }

        return $this->render('@Organization/admin/people.html.twig', [
            'pendingInvitations' => $pendingInvitations,
            'nextPage' => $nextPage,
            'liveTokens' => $liveTokens,
            'tokenOwnerNames' => array_map(static fn (UserView $owner): string => $owner->displayName, $this->userQueries->byIds(array_values($ownerIds))),
            'tokensNextPage' => $tokensNextPage,
            'shownPagesQuery' => [...$invitationsPageQuery, ...$tokensPageQuery],
            'revokeTokenCsrfId' => self::REVOKE_TOKEN_CSRF_ID,
            'typedEmail' => $typedEmail,
            'emailError' => $emailError,
            'formError' => $formError,
        ], new Response(status: $hasError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array<string, string> the parameters pageCursorOf reads back as $after; none for the first page
     */
    private function invitationsPageQueryOf(?PendingInvitation $after): array
    {
        return null === $after ? [] : [
            self::AFTER_INVITED_AT => $after->invitedAt()->format(self::CURSOR_TIME_FORMAT),
            self::AFTER_ID => $after->id(),
        ];
    }

    /**
     * @param array{\DateTimeImmutable, string}|null $tokensAfter
     *
     * @return array<string, string> the parameters tokenPageCursorOf reads back as $tokensAfter; none for the first page
     */
    private function tokensPageQueryOf(?array $tokensAfter): array
    {
        return null === $tokensAfter ? [] : [
            self::TOKENS_AFTER_EXPIRES_AT => $tokensAfter[0]->format(self::TOKEN_CURSOR_TIME_FORMAT),
            self::TOKENS_AFTER_ID => $tokensAfter[1],
        ];
    }
}
