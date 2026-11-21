<?php

declare(strict_types=1);

namespace App\Organization\UI\Admin;

use App\Identity\Api\ApiTokenNotInOrganization;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\OAuthConnectionNotFound;
use App\Identity\Api\OAuthConnectionRefused;
use App\Identity\Api\OrganizationApiTokens;
use App\Identity\Api\OrganizationOAuthConnections;
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
use App\Organization\Application\OrganizationPeopleQueries;
use App\Organization\Application\PeoplePageCursor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
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
 * The admin page of people (FR-ID-02): see the organization's people with their roles and teams (P14.T3, read only),
 * invite an email address, see the invitations not joined yet, see and revoke the organization's live API tokens
 * (P09.T1), and see and end its OAuth connections (ADR-0050). ADMIN_AREA guards every route; AbstractController::denyAccessUnlessGranted gives SendAdminToTwoStepProof the attribute and the
 * organization it needs to send an Admin to the two-step proof.
 */
final class PeopleController extends AbstractController
{
    public const string ROUTE = 'organization_admin_people';
    public const string INVITE_ROUTE = 'organization_admin_people_invite';
    public const string INVITE_PAGE_ROUTE = 'organization_admin_people_invite_page';

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
    /**
     * The query parameters of the next page of people: the lowercased name (PeoplePageCursor::nameKeyOf) and user id
     * of the last person shown, both or neither.
     */
    public const string PEOPLE_AFTER_NAME = 'people_after_name';
    public const string PEOPLE_AFTER_ID = 'people_after_id';

    public const string END_CONNECTION_ROUTE = 'organization_admin_people_connection_end';
    public const string CONNECTION_ENDED = 'The connection is ended. The app can no longer use pinround.';
    /** The query parameters of the next page of connected apps: the connectedAt and id of the last one shown, both or neither. */
    public const string CONNECTIONS_AFTER_CONNECTED_AT = 'connections_after_connected_at';
    public const string CONNECTIONS_AFTER_ID = 'connections_after_id';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID = 'organization_admin_people_invite';
    private const string REVOKE_TOKEN_CSRF_ID = 'organization_admin_people_token_revoke';
    private const string END_CONNECTION_CSRF_ID = 'organization_admin_people_connection_end';
    /** With microseconds and the offset, so the cursor names the exact created_at the database holds. */
    private const string CURSOR_TIME_FORMAT = 'Y-m-d\TH:i:s.uP';
    /** expires_at and a connection's connectedAt keep whole seconds, so their cursors need none. */
    private const string TOKEN_CURSOR_TIME_FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(
        private readonly OrganizationPeopleQueries $organizationPeopleQueries,
        private readonly InvitationQueries $invitationQueries,
        private readonly InviteMember $inviteMember,
        private readonly OrganizationApiTokens $organizationApiTokens,
        private readonly OrganizationOAuthConnections $organizationOAuthConnections,
        private readonly UserQueries $userQueries,
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentUser $currentUser,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The page after the person PEOPLE_AFTER_NAME and PEOPLE_AFTER_ID name, after the invitation that AFTER_INVITED_AT
     * and AFTER_ID name, after the API token that TOKENS_AFTER_EXPIRES_AT and TOKENS_AFTER_ID name, and after the
     * connected app that CONNECTIONS_AFTER_CONNECTED_AT and CONNECTIONS_AFTER_ID name, or the first page of each
     * without them.
     */
    #[Route('/admin/people', name: self::ROUTE, methods: ['GET'])]
    public function show(Request $request): Response
    {
        $organizationId = $this->tenantContext->get();
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::INVITE, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::ADMIN, $organizationId);
        \assert($organizationId instanceof OrganizationId);

        return $this->renderPage(
            $organizationId,
            after: $this->pageCursorOf($request->query),
            tokensAfter: $this->tokenPageCursorOf($request->query),
            peopleAfter: $this->peoplePageCursorOf($request->query),
            connectionsAfter: $this->connectionPageCursorOf($request->query),
        );
    }

    /**
     * Revokes any person's token of the organization, in the request transaction with its ApiTokenRevoked, and goes
     * back to the pages of the lists the form names in the same parameters as show. The id is not checked by the
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
        $peopleAfter = $this->peoplePageCursorOf($request->request);
        $connectionsAfter = $this->connectionPageCursorOf($request->request);
        if (!$this->isCsrfTokenValid(self::REVOKE_TOKEN_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($organizationId, formError: self::FORM_EXPIRED, after: $after, tokensAfter: $tokensAfter, peopleAfter: $peopleAfter, connectionsAfter: $connectionsAfter);
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

        return $this->redirectToShownPages($after, $tokensAfter, $peopleAfter, $connectionsAfter);
    }

    /**
     * Ends any person's OAuth connection of the organization, in the request transaction with its ApiTokenRevoked, and
     * goes back to the pages of the lists the form names, as revokeToken does. The id is not checked by the route, so a
     * malformed one gets the same 404 as another organization's. A connection ended already says nothing.
     */
    #[Route('/admin/people/connections/{id}/end', name: self::END_CONNECTION_ROUTE, methods: ['POST'])]
    public function endConnection(Request $request, string $id): Response
    {
        $organizationId = $this->tenantContext->get();
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
        $this->denyAccessUnlessGranted(OrganizationPermission::ADMIN, $organizationId);
        \assert($organizationId instanceof OrganizationId);
        $after = $this->pageCursorOf($request->request);
        $tokensAfter = $this->tokenPageCursorOf($request->request);
        $peopleAfter = $this->peoplePageCursorOf($request->request);
        $connectionsAfter = $this->connectionPageCursorOf($request->request);
        if (!$this->isCsrfTokenValid(self::END_CONNECTION_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($organizationId, formError: self::FORM_EXPIRED, after: $after, tokensAfter: $tokensAfter, peopleAfter: $peopleAfter, connectionsAfter: $connectionsAfter);
        }

        try {
            $isEndedNow = $this->organizationOAuthConnections->end($id, $this->clock->now());
        } catch (OAuthConnectionNotFound $notFound) {
            throw $this->createNotFoundException($notFound->getMessage(), $notFound);
        } catch (OAuthConnectionRefused $refused) {
            // The guards above ask the same ADMIN_AREA the voter asks, so only a rule added to the voter lands here.
            return $this->renderPage($organizationId, formError: $refused->getMessage(), after: $after, tokensAfter: $tokensAfter, peopleAfter: $peopleAfter, connectionsAfter: $connectionsAfter, errorStatus: Response::HTTP_FORBIDDEN);
        }
        if ($isEndedNow) {
            $this->addFlash(self::TOAST, self::CONNECTION_ENDED);
        }

        return $this->redirectToShownPages($after, $tokensAfter, $peopleAfter, $connectionsAfter);
    }

    /**
     * @param array{\DateTimeImmutable, string}|null $tokensAfter
     * @param array{\DateTimeImmutable, string}|null $connectionsAfter
     */
    private function redirectToShownPages(?PendingInvitation $after, ?array $tokensAfter, ?PeoplePageCursor $peopleAfter, ?array $connectionsAfter): Response
    {
        return $this->redirectToRoute(self::ROUTE, [
            ...$this->peoplePageQueryOf($peopleAfter),
            ...$this->invitationsPageQueryOf($after),
            ...$this->tokensPageQueryOf($tokensAfter),
            ...$this->connectionsPageQueryOf($connectionsAfter),
        ]);
    }

    /**
     * The address the invite form posts to, opened by hand or by a reload after the post: sends to the page with the
     * form instead of a 405 page.
     */
    #[Route('/admin/people/invite', name: self::INVITE_PAGE_ROUTE, methods: ['GET'])]
    public function showInvitePage(): Response
    {
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $this->tenantContext->get());

        return $this->redirectToRoute(self::ROUTE);
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
     * @param InputBag<string> $parameters the query of show, or the form of revokeToken or endConnection
     *
     * @return array{\DateTimeImmutable, string}|null the connectedAt and id of the last connected app shown; null when
     *                                                the request names no page of them
     *
     * @throws BadRequestHttpException when only one of the two parameters is given, or either is no valid value
     */
    private function connectionPageCursorOf(InputBag $parameters): ?array
    {
        $hasConnectedAt = $parameters->has(self::CONNECTIONS_AFTER_CONNECTED_AT);
        if (!$hasConnectedAt && !$parameters->has(self::CONNECTIONS_AFTER_ID)) {
            return null;
        }
        $connectedAtText = $parameters->getString(self::CONNECTIONS_AFTER_CONNECTED_AT);
        $id = $parameters->getString(self::CONNECTIONS_AFTER_ID);
        $connectedAt = \DateTimeImmutable::createFromFormat('!'.self::TOKEN_CURSOR_TIME_FORMAT, $connectedAtText);
        if (!$hasConnectedAt || false === $connectedAt || $connectedAt->format(self::TOKEN_CURSOR_TIME_FORMAT) !== $connectedAtText || !Uuid::isValid($id)) {
            throw new BadRequestHttpException('The page of connected apps to show is not valid.');
        }

        return [$connectedAt, $id];
    }

    /**
     * @param InputBag<string> $parameters the query of show, or the form of revokeToken
     *
     * @return PeoplePageCursor|null the place of the last person shown; null when the request names no page of people
     *
     * @throws BadRequestHttpException when only one of the two parameters is given, or either is no valid value
     */
    private function peoplePageCursorOf(InputBag $parameters): ?PeoplePageCursor
    {
        $hasName = $parameters->has(self::PEOPLE_AFTER_NAME);
        if (!$hasName && !$parameters->has(self::PEOPLE_AFTER_ID)) {
            return null;
        }
        if (!$hasName) {
            throw new BadRequestHttpException('The page of people to show is not valid.');
        }

        try {
            return new PeoplePageCursor($parameters->getString(self::PEOPLE_AFTER_NAME), new UserId($parameters->getString(self::PEOPLE_AFTER_ID)));
        } catch (\InvalidArgumentException $notAPlace) {
            throw new BadRequestHttpException('The page of people to show is not valid.', $notAPlace);
        }
    }

    /**
     * Reads the people, the pending invitations, the live API tokens and the connected apps, so it needs a transaction
     * open under $organizationId.
     *
     * @param array{\DateTimeImmutable, string}|null $tokensAfter      the expiresAt and id of the last API token shown
     * @param array{\DateTimeImmutable, string}|null $connectionsAfter the connectedAt and id of the last connected app
     *                                                                 shown
     * @param int                                    $errorStatus      the status when an error is given
     */
    private function renderPage(OrganizationId $organizationId, string $typedEmail = '', ?string $emailError = null, ?string $formError = null, ?PendingInvitation $after = null, ?array $tokensAfter = null, ?PeoplePageCursor $peopleAfter = null, ?array $connectionsAfter = null, int $errorStatus = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        $hasError = null !== $emailError || null !== $formError;
        $now = $this->clock->now();
        // Each list's Show more keeps the pages the other lists are on.
        $peoplePageQuery = $this->peoplePageQueryOf($peopleAfter);
        $invitationsPageQuery = $this->invitationsPageQueryOf($after);
        $tokensPageQuery = $this->tokensPageQueryOf($tokensAfter);
        $connectionsPageQuery = $this->connectionsPageQueryOf($connectionsAfter);

        $people = $this->organizationPeopleQueries->pageOf($organizationId, self::PAGE_SIZE + 1, $peopleAfter);
        $peopleNextPage = null;
        if (\count($people) > self::PAGE_SIZE) {
            $people = \array_slice($people, 0, self::PAGE_SIZE);
            $peopleNextPage = [
                ...$invitationsPageQuery,
                ...$tokensPageQuery,
                ...$connectionsPageQuery,
                ...$this->peoplePageQueryOf(PeoplePageCursor::after($people[self::PAGE_SIZE - 1])),
            ];
        }
        $pendingInvitations = $this->invitationQueries->pendingOf($organizationId, self::PAGE_SIZE + 1, $after);
        $nextPage = null;
        if (\count($pendingInvitations) > self::PAGE_SIZE) {
            $pendingInvitations = \array_slice($pendingInvitations, 0, self::PAGE_SIZE);
            $lastShown = $pendingInvitations[self::PAGE_SIZE - 1];
            $nextPage = [
                ...$peoplePageQuery,
                ...$tokensPageQuery,
                ...$connectionsPageQuery,
                self::AFTER_INVITED_AT => $lastShown->invitedAt()->format(self::CURSOR_TIME_FORMAT),
                self::AFTER_ID => $lastShown->id(),
            ];
        }
        $liveTokens = $this->organizationApiTokens->livePage($now, self::PAGE_SIZE + 1, $tokensAfter[0] ?? null, $tokensAfter[1] ?? null);
        $tokensNextPage = null;
        if (\count($liveTokens) > self::PAGE_SIZE) {
            $liveTokens = \array_slice($liveTokens, 0, self::PAGE_SIZE);
            $lastShownToken = $liveTokens[self::PAGE_SIZE - 1];
            $tokensNextPage = [
                ...$peoplePageQuery,
                ...$invitationsPageQuery,
                ...$connectionsPageQuery,
                self::TOKENS_AFTER_EXPIRES_AT => $lastShownToken->expiresAt->format(self::TOKEN_CURSOR_TIME_FORMAT),
                self::TOKENS_AFTER_ID => $lastShownToken->id,
            ];
        }
        $connections = $this->organizationOAuthConnections->livePage($now, self::PAGE_SIZE + 1, $connectionsAfter[0] ?? null, $connectionsAfter[1] ?? null);
        $connectionsNextPage = null;
        if (\count($connections) > self::PAGE_SIZE) {
            $connections = \array_slice($connections, 0, self::PAGE_SIZE);
            $lastShownConnection = $connections[self::PAGE_SIZE - 1];
            $connectionsNextPage = [
                ...$peoplePageQuery,
                ...$invitationsPageQuery,
                ...$tokensPageQuery,
                ...$this->connectionsPageQueryOf([$lastShownConnection->connectedAt, $lastShownConnection->familyId]),
            ];
        }
        $ownerIds = [];
        foreach ($liveTokens as $token) {
            $ownerIds[$token->ownerId->toString()] = $token->ownerId;
        }
        foreach ($connections as $connection) {
            $ownerIds[$connection->userId->toString()] = $connection->userId;
        }

        return $this->render('@Organization/admin/people.html.twig', [
            'people' => $people,
            'peopleNextPage' => $peopleNextPage,
            // Each null on the list's first page; on a later one, the list's first page with the other lists' pages kept.
            'peopleFirstPage' => null === $peopleAfter ? null : [...$invitationsPageQuery, ...$tokensPageQuery, ...$connectionsPageQuery],
            'invitationsFirstPage' => null === $after ? null : [...$peoplePageQuery, ...$tokensPageQuery, ...$connectionsPageQuery],
            'tokensFirstPage' => null === $tokensAfter ? null : [...$peoplePageQuery, ...$invitationsPageQuery, ...$connectionsPageQuery],
            'connectionsFirstPage' => null === $connectionsAfter ? null : [...$peoplePageQuery, ...$invitationsPageQuery, ...$tokensPageQuery],
            'pendingInvitations' => $pendingInvitations,
            'nextPage' => $nextPage,
            'liveTokens' => $liveTokens,
            'ownerNames' => array_map(static fn (UserView $owner): string => $owner->displayName, $this->userQueries->byIds(array_values($ownerIds))),
            'tokensNextPage' => $tokensNextPage,
            'connections' => $connections,
            'connectionsNextPage' => $connectionsNextPage,
            'shownPagesQuery' => [...$peoplePageQuery, ...$invitationsPageQuery, ...$tokensPageQuery, ...$connectionsPageQuery],
            'revokeTokenCsrfId' => self::REVOKE_TOKEN_CSRF_ID,
            'endConnectionCsrfId' => self::END_CONNECTION_CSRF_ID,
            'typedEmail' => $typedEmail,
            'emailError' => $emailError,
            'formError' => $formError,
        ], new Response(status: $hasError ? $errorStatus : Response::HTTP_OK));
    }

    /**
     * @return array<string, string> the parameters peoplePageCursorOf reads back as $peopleAfter; none for the first page
     */
    private function peoplePageQueryOf(?PeoplePageCursor $peopleAfter): array
    {
        return null === $peopleAfter ? [] : [
            self::PEOPLE_AFTER_NAME => $peopleAfter->nameKey,
            self::PEOPLE_AFTER_ID => $peopleAfter->userId->toString(),
        ];
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

    /**
     * @param array{\DateTimeImmutable, string}|null $connectionsAfter
     *
     * @return array<string, string> the parameters connectionPageCursorOf reads back as $connectionsAfter; none for the
     *                               first page
     */
    private function connectionsPageQueryOf(?array $connectionsAfter): array
    {
        return null === $connectionsAfter ? [] : [
            self::CONNECTIONS_AFTER_CONNECTED_AT => $connectionsAfter[0]->format(self::TOKEN_CURSOR_TIME_FORMAT),
            self::CONNECTIONS_AFTER_ID => $connectionsAfter[1],
        ];
    }
}
