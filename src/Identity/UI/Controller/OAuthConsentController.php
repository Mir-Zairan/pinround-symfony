<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Api\MemberOrganization;
use App\Identity\Application\AccountPermission;
use App\Identity\Application\AuthorizeOAuthClient;
use App\Identity\Application\CurrentSession;
use App\Identity\Application\OAuthAuthorizationRefused;
use App\Identity\Application\OAuthAuthorizationRequest;
use App\Identity\Application\OAuthConsentOrganizationNotFound;
use App\Identity\Application\OAuthDiscovery;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\SharedKernel\UI\RequiresRecentAuth;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The OAuth consent page, /oauth/authorize (ADR-0050): an app that wants to connect sends the person here; they see
 * its name, where it sends them back to and what it may do, pick one of their organizations and allow or deny. Both
 * actions need the recent password and two-step proof of making a personal access token (RequiresRecentAuth) and ask
 * OwnAccountVoter for AccountPermission::EDIT_OWN first (ADR-0013). Allow and Deny need the form's CSRF token; without
 * it the page comes back with 422 and no code. A client the server does not know, or a redirect URI it did not
 * register, gets an error page here and never a redirect. The routes run without the request transaction, as
 * MemberOrganizations and the code's save each open their own (OAuthAuthorizationCodes::add). No answer is stored.
 */
final class OAuthConsentController extends AbstractController
{
    public const string ROUTE = 'identity_oauth_authorize';
    public const string DECIDE_ROUTE = 'identity_oauth_authorize_decide';
    public const string ALLOW = 'allow';

    private const string CSRF_ID = 'identity_oauth_consent';

    public function __construct(
        private readonly AuthorizeOAuthClient $authorizeOAuthClient,
        private readonly CurrentSession $currentSession,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(OAuthDiscovery::AUTHORIZATION_PATH, name: self::ROUTE, methods: ['GET'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    #[RequiresRecentAuth]
    public function show(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        try {
            $authorization = $this->authorizeOAuthClient->check($request->query->all());
        } catch (OAuthAuthorizationRefused $refusal) {
            return $this->refusalAnswer($refusal);
        }

        return $this->renderConsent($authorization);
    }

    /**
     * Allow only when the decision says so; any other decision denies.
     */
    #[Route(OAuthDiscovery::AUTHORIZATION_PATH, name: self::DECIDE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    #[RequiresRecentAuth]
    public function decide(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        $fields = $request->request->all();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->formExpiredAnswer($fields);
        }

        try {
            $answerUrl = self::ALLOW === $request->request->getString('decision')
                ? $this->authorizeOAuthClient->allow($fields, $this->currentSession->userId(), $request->request->getString('organization_id'), $this->clock->now())
                : $this->authorizeOAuthClient->deny($fields);
        } catch (OAuthAuthorizationRefused $refusal) {
            return $this->refusalAnswer($refusal);
        } catch (OAuthConsentOrganizationNotFound $notFound) {
            throw $this->createNotFoundException($notFound->getMessage(), $notFound);
        }

        return self::notStored(new RedirectResponse($answerUrl));
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private function formExpiredAnswer(array $fields): Response
    {
        try {
            $authorization = $this->authorizeOAuthClient->check($fields);
        } catch (OAuthAuthorizationRefused) {
            return $this->renderRefusal(LoginController::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderConsent($authorization, LoginController::FORM_EXPIRED);
    }

    private function refusalAnswer(OAuthAuthorizationRefused $refusal): Response
    {
        if (null === $refusal->errorRedirectUrl) {
            return $this->renderRefusal($refusal->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return self::notStored(new RedirectResponse($refusal->errorRedirectUrl));
    }

    private function renderConsent(OAuthAuthorizationRequest $authorization, ?string $formError = null): Response
    {
        $currentOrganizationId = $this->tenantContext->get()?->toString();
        $organizations = $this->authorizeOAuthClient->organizationsOf($this->currentSession->userId());
        $organizationIds = array_map(static fn (MemberOrganization $organization): string => $organization->id->toString(), $organizations);
        $preselectedOrganizationId = match (true) {
            null !== $currentOrganizationId && \in_array($currentOrganizationId, $organizationIds, true) => $currentOrganizationId,
            1 === \count($organizationIds) => $organizationIds[0],
            default => null,
        };

        return self::notStored($this->render('@Identity/oauth_consent.html.twig', [
            'authorization' => $authorization,
            'scopeExplanations' => self::scopeExplanationsOf($authorization),
            'organizations' => $organizations,
            'preselectedOrganizationId' => $preselectedOrganizationId,
            'formError' => $formError,
            'csrfTokenId' => self::CSRF_ID,
            'refusal' => null,
        ], new Response(status: null === $formError ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    private function renderRefusal(string $refusal, int $status): Response
    {
        return self::notStored($this->render('@Identity/oauth_consent.html.twig', ['refusal' => $refusal], new Response(status: $status)));
    }

    /**
     * @return list<string> what each asked scope lets the app do, in the words of the tokens page
     *
     * @throws \LogicException when a scope has no explanation, so the page never asks for a scope nobody explained
     */
    private static function scopeExplanationsOf(OAuthAuthorizationRequest $authorization): array
    {
        return array_map(
            static fn (string $scopeName): string => TokenController::SCOPE_EXPLANATIONS[$scopeName] ?? throw new \LogicException(\sprintf('The token scope "%s" has no explanation.', $scopeName)),
            $authorization->scopeNames,
        );
    }

    private static function notStored(Response $response): Response
    {
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
