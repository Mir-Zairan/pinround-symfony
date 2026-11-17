<?php

declare(strict_types=1);

namespace App\Organization\UI\Admin;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\Organization\Application\ChangeAiSettings;
use App\Organization\Application\ChangeAiSettingsRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\SharedKernel\UI\RequiresRecentAuth;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Admin › AI tab (FR-ADM-05, admin.md › AI): each setting applies at once, through its own small form. The page
 * shows the settings as AiSettingsQueries reads them, which is what the AI policy enforces, so settings it cannot read
 * show as AI off. Every route asks ADMIN_AREA; the changes ask ADMIN_AI too, and the provider key also needs a recent
 * password and code (RequiresRecentAuth). The key is write-only: the page only says whether one is saved.
 */
final class AiSettingsController extends AbstractController
{
    public const string ROUTE = 'organization_admin_ai';
    public const string CHANGE_ROUTE = 'organization_admin_ai_change';
    public const string PROVIDER_KEY_ROUTE = 'organization_admin_ai_provider_key';

    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string CHANGED_MEANWHILE = 'Someone changed the AI settings at the same time. Check them and try again.';
    public const string PROVIDER_KEY_SAVED = 'API key saved.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CHANGE_CSRF_ID = 'organization_admin_ai_change';
    private const string PROVIDER_KEY_CSRF_ID = 'organization_admin_ai_provider_key';

    public function __construct(
        private readonly AiSettingsQueries $aiSettingsQueries,
        private readonly ChangeAiSettings $changeAiSettings,
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentUser $currentUser,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/ai', name: self::ROUTE, methods: ['GET'])]
    public function show(): Response
    {
        $organizationId = $this->tenantContext->get();
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
        \assert($organizationId instanceof OrganizationId);

        return $this->renderPage($organizationId);
    }

    /**
     * Without the request transaction, so a lost race with another save is rolled back before the page answers.
     */
    #[Route('/admin/ai', name: self::CHANGE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function change(Request $request): Response
    {
        $setting = $request->request->getString('setting');
        $choice = $request->request->getString('choice');

        return $this->runChange($request, self::CHANGE_CSRF_ID, function (OrganizationId $organizationId) use ($setting, $choice): Response {
            $this->changeAiSettings->changeSetting($organizationId, $setting, $choice, $this->actingUser(), $this->clock->now());

            return $this->redirectToRoute(self::ROUTE);
        });
    }

    #[RequiresRecentAuth]
    #[Route('/admin/ai/provider-key', name: self::PROVIDER_KEY_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function changeProviderKey(Request $request): Response
    {
        $providerKey = $request->request->getString('provider_key');

        return $this->runChange($request, self::PROVIDER_KEY_CSRF_ID, function (OrganizationId $organizationId) use ($providerKey): Response {
            try {
                $this->changeAiSettings->changeProviderKey($organizationId, $providerKey, $this->actingUser(), $this->clock->now());
            } catch (ChangeAiSettingsRefused $refusal) {
                return $this->renderPage($organizationId, providerKeyError: $refusal->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash(self::TOAST, self::PROVIDER_KEY_SAVED);

            return $this->redirectToRoute(self::ROUTE);
        });
    }

    /**
     * Runs $change after the access and CSRF checks, in a transaction of its own. A refused value or form shows the page
     * again with the reason and 422; a save that lost the race with another one shows it with CHANGED_MEANWHILE and 409.
     *
     * @param \Closure(OrganizationId): Response $change
     */
    private function runChange(Request $request, string $csrfId, \Closure $change): Response
    {
        $organizationId = $this->tenantContext->get();
        if (null === $organizationId) {
            throw $this->createAccessDeniedException();
        }
        $csrfToken = $request->request->getString('_csrf_token');

        try {
            return $this->transactions->run(function () use ($organizationId, $csrfId, $csrfToken, $change): Response {
                $this->denyAccessUnlessGranted(AdminPermission::AREA, $organizationId);
                $this->denyAccessUnlessGranted(AdminPermission::AI, $organizationId);
                if (!$this->isCsrfTokenValid($csrfId, $csrfToken)) {
                    return $this->renderPage($organizationId, formError: self::FORM_EXPIRED, status: Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                try {
                    return $change($organizationId);
                } catch (ChangeAiSettingsRefused $refusal) {
                    return $this->renderPage($organizationId, formError: $refusal->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            });
        } catch (OptimisticLockException) {
            // Rolled back: the page shows what the other save stored.
            return $this->transactions->run(fn (): Response => $this->renderPage($organizationId, formError: self::CHANGED_MEANWHILE, status: Response::HTTP_CONFLICT));
        }
    }

    private function actingUser(): UserId
    {
        return $this->currentUser->id() ?? throw $this->createAccessDeniedException();
    }

    /**
     * Reads the settings, so it needs a transaction open under $organizationId. $formError shows as the page's alert,
     * $providerKeyError under the key field.
     */
    private function renderPage(OrganizationId $organizationId, ?string $formError = null, ?string $providerKeyError = null, int $status = Response::HTTP_OK): Response
    {
        $aiSettings = $this->aiSettingsQueries->aiSettingsOf($organizationId);

        return $this->render('@Organization/admin/ai.html.twig', [
            'chosen' => self::choicesOf($aiSettings),
            'hasProviderKey' => $this->changeAiSettings->hasProviderKey($organizationId),
            'providerKeyMaxLength' => ChangeAiSettings::PROVIDER_KEY_MAX_LENGTH,
            'formError' => $formError,
            'providerKeyError' => $providerKeyError,
        ], new Response(status: $status));
    }

    /**
     * @return array<string, string> the choice of each setting (OrgSettings::AI_CHOICES) that matches the settings read
     */
    private static function choicesOf(AiSettings $aiSettings): array
    {
        return [
            'on' => $aiSettings->isOn ? 'on' : 'off',
            'who' => $aiSettings->isForLeadsOnly ? 'leads_only' : 'everyone',
            'send' => $aiSettings->isTitleOnly ? 'title_only' : 'title_and_text',
            'sync' => $aiSettings->isSummaryOnSyncOn ? 'on' : 'off',
            'where' => $aiSettings->whereAiRuns,
            'restricted_to_cloud' => $aiSettings->isRestrictedToCloudAllowed ? 'on' : 'off',
            // A null provider is Hugging Face (AiSettingsQueries).
            'provider' => $aiSettings->provider ?? 'huggingface',
        ];
    }
}
