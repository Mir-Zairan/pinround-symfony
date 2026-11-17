<?php

declare(strict_types=1);

namespace App\Assist\UI\Controller;

use App\Assist\UI\LocalAiChip;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /ai/laptop/status {ready, model}: the local-ai controller tells the server what it found on the laptop after a
 * check (ai-architecture.md, Laptop mode protocol). "Laptop ready" is a browser-side fact the browser sends again with
 * each feature request, so this stores nothing: it checks the call and answers 204.
 *
 * In that order: a person with no membership in the tenant is forbidden; a missing or wrong token in X-CSRF-Token
 * answers 422 as an expired page; a person LocalAiChip shows no chip to is forbidden with its line; a body over 1 KB
 * answers 413; a body that is not {"ready": bool, "model": string|null}, with a model name for a ready laptop and
 * none otherwise, or a model name off MODEL_NAME_PATTERN, answers 422.
 */
final class LaptopController extends AbstractController
{
    public const string STATUS_ROUTE = 'assist_laptop_status';

    public const string CSRF_HEADER = 'X-CSRF-Token';
    public const string CSRF_ID = 'ai_laptop';

    /** The model names Ollama's tags may carry and pinround shows or accepts (ai-architecture.md). */
    public const string MODEL_NAME_PATTERN = '/^[a-z0-9:._-]{1,64}\z/';

    /** A status is about 50 bytes; anything larger is refused before it is parsed. */
    public const int MAX_BODY_BYTES = 1024;

    public const string NOT_ALLOWED = LocalAiChip::NOT_A_MEMBER;
    public const string FORM_EXPIRED = 'The page was open too long. Reload it and try again.';
    public const string BODY_TOO_LARGE = 'This laptop status is larger than 1 KB.';
    public const string NOT_A_STATUS = 'A laptop status needs "ready" (true or false) and, for a ready laptop only, a model name of 1 to 64 characters from a-z, 0-9 and ":._-".';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LocalAiChip $localAiChip,
    ) {
    }

    #[Route('/ai/laptop/status', name: self::STATUS_ROUTE, methods: ['POST'])]
    public function status(Request $request): Response
    {
        if (!$this->isGranted(OrganizationPermission::VIEW, $this->tenantContext->get())) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->headers->get(self::CSRF_HEADER, ''))) {
            return self::refusal(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $refusal = $this->localAiChip->refusal();
        if (null !== $refusal) {
            return self::refusal($refusal, Response::HTTP_FORBIDDEN);
        }
        $body = self::bodyWithinCapOf($request);
        if (null === $body) {
            return self::refusal(self::BODY_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if (!self::isLaptopStatus($body)) {
            return self::refusal(self::NOT_A_STATUS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Reads at most one byte past MAX_BODY_BYTES, so a larger body is never held in memory whole.
     *
     * @return string|null null when the body is larger than MAX_BODY_BYTES or cannot be read
     */
    private static function bodyWithinCapOf(Request $request): ?string
    {
        $body = stream_get_contents($request->getContent(true), self::MAX_BODY_BYTES + 1);

        return false === $body || \strlen($body) > self::MAX_BODY_BYTES ? null : $body;
    }

    private static function isLaptopStatus(string $body): bool
    {
        try {
            $fields = json_decode($body, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!\is_array($fields) || ['model', 'ready'] !== self::sortedKeysOf($fields)) {
            return false;
        }
        $isReady = $fields['ready'];
        $model = $fields['model'];
        if (true === $isReady) {
            return \is_string($model) && 1 === preg_match(self::MODEL_NAME_PATTERN, $model);
        }

        return false === $isReady && null === $model;
    }

    /**
     * @param array<mixed> $fields
     *
     * @return list<int|string>
     */
    private static function sortedKeysOf(array $fields): array
    {
        $keys = array_keys($fields);
        sort($keys);

        return $keys;
    }

    private static function refusal(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
