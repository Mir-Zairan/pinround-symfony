<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\AccountPermission;
use App\Identity\Application\ApiTokenNotFound;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\CurrentSession;
use App\Identity\Application\IssueApiToken;
use App\Identity\Application\NewApiToken;
use App\Identity\Application\RevokeApiToken;
use App\Identity\Application\ShowApiTokens;
use App\Identity\Application\TooManyApiTokens;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\RequiresRecentAuth;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The tokens page (FR-ID-05, P09.T1): a person's own personal access tokens for Claude Code and the browser plugin, in
 * the organization they work in. Making one needs a recent password and code (RequiresRecentAuth); its plain token is
 * in the response to that request only, so no reload shows it again. Every action asks OwnAccountVoter for
 * AccountPermission::EDIT_OWN first, which a token-authenticated request never gets (ADR-0013, ADR-0021); the page
 * acts on the session's user, and a token id from the request is found only among that user's own tokens.
 */
final class TokenController extends AbstractController
{
    public const string TOKEN_REVOKED = 'The token is revoked. It no longer works.';
    public const string LIFETIME_NOT_OFFERED = 'Choose how long the token works from the list.';

    /** What each scope lets the token do, in the words the form and the list show; every scope ShowApiTokens names needs one. */
    public const array SCOPE_EXPLANATIONS = [
        'read' => 'Read your tickets, rounds and notes.',
        'rounds:write' => 'Add screenshots, notes and verdicts to your draft rounds.',
        'comments:write' => 'Write comments on tickets.',
        'tickets:move' => 'Move tickets to another step.',
        'reports:write' => 'Let Claude Code prepare report drafts for you. You still check and send them.',
        'notebooks:write' => 'Let Claude Code and the extension write to your notebooks.',
    ];

    /** The lifetimes the form offers, in days. */
    public const array LIFETIME_DAY_CHOICES = [30, 90, 365];

    private const string CREATE_CSRF_ID = 'identity_token_create';
    private const string REVOKE_CSRF_ID = 'identity_token_revoke';

    public function __construct(
        private readonly ShowApiTokens $showApiTokens,
        private readonly IssueApiToken $issueApiToken,
        private readonly RevokeApiToken $revokeApiToken,
        private readonly CurrentSession $currentSession,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/me/tokens', name: 'identity_tokens', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);

        return $this->renderPage($request);
    }

    #[Route('/me/tokens', name: 'identity_tokens_create', methods: ['POST'])]
    #[RequiresRecentAuth]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        $name = $request->request->getString('name');
        $scopeNames = $request->request->all('scopes');
        $lifetimeChoice = $request->request->getString('lifetime_days');
        $chosen = ['name' => $name, 'scopes' => array_values(array_filter($scopeNames, \is_string(...))), 'lifetimeDays' => $lifetimeChoice];
        if (!$this->isCsrfTokenValid(self::CREATE_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($request, $chosen, createError: LoginController::FORM_EXPIRED);
        }
        $lifetimeDays = self::offeredLifetimeDays($lifetimeChoice);
        if (null === $lifetimeDays) {
            return $this->renderPage($request, $chosen, createError: self::LIFETIME_NOT_OFFERED);
        }
        if (\count($chosen['scopes']) !== \count($scopeNames)) {
            return $this->renderPage($request, $chosen, createError: IssueApiToken::UNKNOWN_SCOPE);
        }

        try {
            $newToken = $this->issueApiToken->issueWithScopeNames($this->tenantContext->require(), $this->currentSession->userId(), $name, $chosen['scopes'], $lifetimeDays, $this->clock->now());
        } catch (ApiTokenRefused|TooManyApiTokens $refusal) {
            return $this->renderPage($request, $chosen, createError: $refusal->getMessage());
        }

        return $this->renderPage($request, newToken: $newToken);
    }

    /**
     * The id is not checked by the route, so a malformed one gets the same 404 as another person's.
     */
    #[Route('/me/tokens/{id}/revoke', name: 'identity_tokens_revoke', methods: ['POST'])]
    public function revoke(Request $request, string $id): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        if (!$this->isCsrfTokenValid(self::REVOKE_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($request, revokeError: LoginController::FORM_EXPIRED);
        }

        try {
            $isRevokedNow = ($this->revokeApiToken)($this->currentSession->userId(), $id, $this->clock->now());
        } catch (ApiTokenNotFound $notFound) {
            throw $this->createNotFoundException($notFound->getMessage(), $notFound);
        }
        // Already revoked, as after a double click, says nothing: the list no longer shows it.
        if ($isRevokedNow) {
            $this->addFlash('notice', self::TOKEN_REVOKED);
        }

        return self::notStored($this->redirectToRoute('identity_tokens'));
    }

    /**
     * @param array{name: string, scopes: list<string>, lifetimeDays: string}|null $chosen what the form sent; null
     *                                                                                     for the form's defaults
     */
    private function renderPage(Request $request, ?array $chosen = null, ?string $createError = null, ?string $revokeError = null, ?NewApiToken $newToken = null): Response
    {
        $hasError = null !== $createError || null !== $revokeError;

        return self::notStored($this->render('@Identity/tokens.html.twig', [
            'tokens' => $this->showApiTokens->liveOf($this->currentSession->userId(), $this->clock->now()),
            'scopeChoices' => $this->scopeChoices(),
            'lifetimeDayChoices' => self::LIFETIME_DAY_CHOICES,
            'chosen' => $chosen ?? ['name' => '', 'scopes' => $this->showApiTokens->defaultScopeNames(), 'lifetimeDays' => (string) ShowApiTokens::DEFAULT_LIFETIME_DAYS],
            'createError' => $createError,
            'revokeError' => $revokeError,
            'newToken' => $newToken,
            'mcpUrl' => $request->getSchemeAndHttpHost().'/mcp',
            'createCsrfTokenId' => self::CREATE_CSRF_ID,
            'revokeCsrfTokenId' => self::REVOKE_CSRF_ID,
        ], new Response(status: $hasError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /**
     * @return array<string, string> scope name => what it lets the token do
     *
     * @throws \LogicException when a scope has no explanation, so the form never offers a choice nobody explained
     */
    private function scopeChoices(): array
    {
        $choices = [];
        foreach ($this->showApiTokens->scopeNames() as $scopeName) {
            $choices[$scopeName] = self::SCOPE_EXPLANATIONS[$scopeName] ?? throw new \LogicException(\sprintf('The token scope "%s" has no explanation on the tokens page.', $scopeName));
        }

        return $choices;
    }

    private static function offeredLifetimeDays(string $choice): ?int
    {
        foreach (self::LIFETIME_DAY_CHOICES as $days) {
            if ((string) $days === $choice) {
                return $days;
            }
        }

        return null;
    }

    private static function notStored(Response $response): Response
    {
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
