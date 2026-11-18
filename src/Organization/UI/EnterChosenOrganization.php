<?php

declare(strict_types=1);

namespace App\Organization\UI;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\LoginAssurance;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Makes the organization chosen in the session the tenant of a logged-in user's request (ADR-0005). It runs after the
 * firewall (8), which has authenticated the user, and before RequestTransaction begins (6), so the request
 * transaction gets the tenant. The user's organizations come from the resolver on every request, so a user removed
 * from the chosen organization leaves it at their next request. A session without a valid choice gets the user's only
 * organization; with two or more, or none, the request goes to the picker, except on the routes listed in
 * organization.routes_without_organization (config/services.yaml). A request a personal access token authenticated
 * (ADR-0021) works in the token's organization only: its session is never read or written, so nothing in it can switch
 * the organization, and a token whose user has left that organization gets a 401. Holds no state, so it is safe in
 * worker mode.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class EnterChosenOrganization
{
    public const string SESSION_KEY = 'organization.chosen_id';
    /** The request attribute with the number of the user's organizations, for the avatar menu. */
    public const string ORGANIZATION_COUNT_ATTRIBUTE = '_user_organization_count';
    public const string NOT_A_MEMBER_OF_THE_TOKENS_ORGANIZATION = 'You are no longer a member of the organization this token works in.';

    /**
     * @param list<string> $routesWithoutOrganization
     */
    public function __construct(
        private CurrentUser $currentUser,
        private MembershipQueries $membershipQueries,
        private TenantContext $tenantContext,
        private UrlGeneratorInterface $urlGenerator,
        private TokenStorageInterface $tokenStorage,
        private LoginAssurance $loginAssurance,
        #[Autowire(param: 'organization.routes_without_organization')]
        private array $routesWithoutOrganization,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $userId = $this->currentUser->id();
        if (!$event->isMainRequest() || null === $userId) {
            return;
        }

        $request = $event->getRequest();
        $organizationIds = $this->membershipQueries->organizationsOf($userId);
        $request->attributes->set(self::ORGANIZATION_COUNT_ATTRIBUTE, \count($organizationIds));

        $apiTokenAccess = $this->loginAssurance->apiTokenAccessOf($this->tokenStorage->getToken());
        if (null !== $apiTokenAccess) {
            $this->enterTheTokensOrganization($event, $apiTokenAccess, $organizationIds);

            return;
        }

        $this->enterTheSessionsChoice($event, $request, $organizationIds);
    }

    /**
     * @param list<OrganizationId> $organizationIds
     */
    private function enterTheTokensOrganization(RequestEvent $event, ApiTokenAccess $apiTokenAccess, array $organizationIds): void
    {
        $organizationId = self::find($apiTokenAccess->organizationId->toString(), $organizationIds);
        if (null === $organizationId) {
            $event->setResponse(new JsonResponse(
                ['error' => self::NOT_A_MEMBER_OF_THE_TOKENS_ORGANIZATION],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer'],
            ));

            return;
        }

        $this->tenantContext->set($organizationId);
    }

    /**
     * @param list<OrganizationId> $organizationIds
     */
    private function enterTheSessionsChoice(RequestEvent $event, Request $request, array $organizationIds): void
    {
        $session = $request->getSession();
        $chosenOrganizationId = self::find($session->get(self::SESSION_KEY), $organizationIds);
        if (null === $chosenOrganizationId) {
            $session->remove(self::SESSION_KEY);
            if (1 === \count($organizationIds)) {
                $chosenOrganizationId = $organizationIds[0];
                $session->set(self::SESSION_KEY, $chosenOrganizationId->toString());
            }
        }

        if (null !== $chosenOrganizationId) {
            $this->tenantContext->set($chosenOrganizationId);

            return;
        }
        if (!\in_array($request->attributes->get('_route'), $this->routesWithoutOrganization, true)) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate(OrganizationChoiceController::ROUTE)));
        }
    }

    /**
     * @param list<OrganizationId> $organizationIds
     */
    private static function find(mixed $organizationIdText, array $organizationIds): ?OrganizationId
    {
        foreach ($organizationIds as $organizationId) {
            if ($organizationId->toString() === $organizationIdText) {
                return $organizationId;
            }
        }

        return null;
    }
}
