<?php

declare(strict_types=1);

namespace App\Assist\UI\Controller;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Api\LocalPackageRefused;
use App\Assist\Api\LocalResultRefused;
use App\Assist\Application\LaptopRequestLimit;
use App\Assist\Application\LaptopRequestsLimited;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\UI\FormExpired;
use App\Assist\UI\LaptopRefusalStatuses;
use App\Assist\UI\LocalAiChip;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The laptop AI calls of the local-ai controller (ai-architecture.md, Laptop mode protocol).
 *
 * POST /ai/laptop/status {ready, model}: what the browser found on the laptop after a check. "Laptop ready" is a
 * browser-side fact the browser sends again with each feature request, so this stores nothing: it checks the call and
 * answers 204. In that order: a person with no membership in the tenant is forbidden; a missing or wrong token in
 * X-CSRF-Token answers 422 as an expired page; a person LocalAiChip shows no chip to is forbidden with its line; a body
 * over 1 KB answers 413; a body that is not {"ready": bool, "model": string|null}, with a model name for a ready laptop
 * and none otherwise, or a model name off MODEL_NAME_PATTERN, answers 422.
 *
 * POST /ai/laptop/package {feature, ticketId} answers 200 with the prompt package LocalAi::package stores, and POST
 * /ai/laptop/result {packageId, model, text} answers 201 with the result LocalAi::accept keeps. In that order: a person
 * with no membership in the tenant is forbidden; a missing or wrong token answers 422 as an expired page; a request
 * past LaptopRequestLimit (both routes together) answers 429 with a Retry-After in seconds; a body over its cap 413; a
 * body of other fields 422; a ticket or package id that is not a UUIDv7 answers 404 as an unknown one; then LocalAi's
 * refusal with the status LaptopRefusalStatuses gives it. The result route runs without the request transaction, as
 * LocalAi::accept commits the audit entry of a refusal in a transaction of its own.
 */
final class LaptopController extends AbstractController
{
    public const string STATUS_ROUTE = 'assist_laptop_status';
    public const string PACKAGE_ROUTE = 'assist_laptop_package';
    public const string RESULT_ROUTE = 'assist_laptop_result';

    public const string CSRF_HEADER = 'X-CSRF-Token';
    public const string CSRF_ID = 'ai_laptop';

    /** The model names Ollama's tags may carry and pinround shows or accepts (ai-architecture.md). */
    public const string MODEL_NAME_PATTERN = LocalModelName::PATTERN;

    /** A status or a package request is under 100 bytes; anything larger is refused before it is parsed. */
    public const int MAX_BODY_BYTES = 1024;

    /**
     * Six times the 20,000 bytes an AI answer may have (OutputValidator::MAX_ANSWER_BYTES), as JSON escapes a byte into
     * at most six, and room for the package id and the model name.
     */
    public const int MAX_RESULT_BODY_BYTES = 122880;

    public const string NOT_ALLOWED = LocalAiChip::NOT_A_MEMBER;
    public const string FORM_EXPIRED = FormExpired::LINE;
    public const string BODY_TOO_LARGE = 'This laptop status is larger than 1 KB.';
    public const string NOT_A_STATUS = 'A laptop status needs "ready" (true or false) and, for a ready laptop only, a model name of 1 to 64 characters from a-z, 0-9 and ":._-".';
    public const string PACKAGE_REQUEST_TOO_LARGE = 'This laptop AI request is larger than 1 KB.';
    public const string NOT_A_PACKAGE_REQUEST = 'A laptop AI request needs "feature", which must be "summary", and "ticketId".';
    public const string RESULT_TOO_LARGE = 'This laptop AI answer is larger than 120 KB.';
    public const string NOT_A_RESULT = 'A laptop AI answer needs "packageId", "model" and "text", each a string.';
    public const string TOO_MANY_REQUESTS = LaptopRequestsLimited::MESSAGE;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly Transactions $transactions,
        private readonly LocalAiChip $localAiChip,
        private readonly LocalAi $localAi,
        private readonly LaptopRequestLimit $laptopRequestLimit,
    ) {
    }

    #[Route('/ai/laptop/status', name: self::STATUS_ROUTE, methods: ['POST'])]
    public function status(Request $request): Response
    {
        if (!$this->isGranted(OrganizationPermission::VIEW, $this->tenantContext->get())) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }
        if (!$this->hasValidToken($request)) {
            return self::refusal(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $refusal = $this->localAiChip->refusal();
        if (null !== $refusal) {
            return self::refusal($refusal, Response::HTTP_FORBIDDEN);
        }
        $body = self::bodyWithinCapOf($request, self::MAX_BODY_BYTES);
        if (null === $body) {
            return self::refusal(self::BODY_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if (!self::isLaptopStatus($body)) {
            return self::refusal(self::NOT_A_STATUS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/ai/laptop/package', name: self::PACKAGE_ROUTE, methods: ['POST'])]
    public function package(Request $request): Response
    {
        $actor = $this->memberActor();
        if (null === $actor) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }
        if (!$this->hasValidToken($request)) {
            return self::refusal(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $limitRefusal = $this->limitRefusalOf($actor);
        if (null !== $limitRefusal) {
            return $limitRefusal;
        }
        $body = self::bodyWithinCapOf($request, self::MAX_BODY_BYTES);
        if (null === $body) {
            return self::refusal(self::PACKAGE_REQUEST_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $fields = self::stringFieldsOf($body, ['feature', 'ticketId']);
        $purpose = null === $fields ? null : LocalAiPurpose::tryFrom($fields['feature']);
        if (null === $fields || null === $purpose) {
            return self::refusal(self::NOT_A_PACKAGE_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $ticketId = new TicketId($fields['ticketId']);
        } catch (\InvalidArgumentException) {
            return self::refusal(PolicyRefusal::CannotSeeTicket->sentence(), Response::HTTP_NOT_FOUND);
        }

        try {
            $package = $this->localAi->package($purpose, $ticketId, $actor->userId(), $actor->organizationId());
        } catch (LocalPackageRefused $refused) {
            return self::refusal($refused->getMessage(), LaptopRefusalStatuses::ofPackageRefusal($refused));
        }

        return new JsonResponse([
            'packageId' => $package->packageId->toString(),
            'instructions' => $package->instructions,
            'prompt' => $package->prompt,
            'schema' => $package->schema,
            'expiresAt' => $package->expiresAt->format(\DATE_ATOM),
        ]);
    }

    #[Route('/ai/laptop/result', name: self::RESULT_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function result(Request $request): Response
    {
        // Outside a transaction CurrentActor finds no roles under row-level security, so the organization voter would deny.
        $actor = $this->transactions->run($this->memberActor(...));
        if (null === $actor) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }
        if (!$this->hasValidToken($request)) {
            return self::refusal(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $limitRefusal = $this->limitRefusalOf($actor);
        if (null !== $limitRefusal) {
            return $limitRefusal;
        }
        $body = self::bodyWithinCapOf($request, self::MAX_RESULT_BODY_BYTES);
        if (null === $body) {
            return self::refusal(self::RESULT_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $fields = self::stringFieldsOf($body, ['model', 'packageId', 'text']);
        if (null === $fields) {
            return self::refusal(self::NOT_A_RESULT, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $packageId = new LocalPackageId($fields['packageId']);
        } catch (\InvalidArgumentException) {
            return self::refusal(LocalResultRefused::packageNotFound()->getMessage(), Response::HTTP_NOT_FOUND);
        }

        try {
            $localResult = $this->localAi->accept($packageId, $fields['model'], $fields['text'], $actor->userId(), $actor->organizationId());
        } catch (LocalResultRefused $refused) {
            return self::refusal($refused->getMessage(), LaptopRefusalStatuses::ofResultRefusal($refused));
        }

        return new JsonResponse(
            [
                'packageId' => $localResult->packageId->toString(),
                'label' => $localResult->label,
                'text' => $localResult->text,
                'model' => $localResult->model->value,
                'postedAt' => $localResult->postedAt->format(\DATE_ATOM),
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * The logged-in member of the tenant, or null when there is none; needs an open transaction under the tenant.
     */
    private function memberActor(): ?ActorContext
    {
        if (!$this->isGranted(OrganizationPermission::VIEW, $this->tenantContext->get())) {
            return null;
        }

        return $this->currentActor->actorFor($this->getUser());
    }

    /**
     * Counts the request. After the token check, so a page of another site cannot spend a member's requests by posting
     * in their session; before the body is read, so a refused request costs no parsing.
     */
    private function limitRefusalOf(ActorContext $actor): ?JsonResponse
    {
        try {
            $this->laptopRequestLimit->consume($actor);
        } catch (LaptopRequestsLimited $limited) {
            $refusal = self::refusal(self::TOO_MANY_REQUESTS, Response::HTTP_TOO_MANY_REQUESTS);
            $refusal->headers->set('Retry-After', (string) $limited->retryAfterSeconds);

            return $refusal;
        }

        return null;
    }

    private function hasValidToken(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF_ID, $request->headers->get(self::CSRF_HEADER, ''));
    }

    /**
     * Reads at most one byte past $maxBytes, so a larger body is never held in memory whole.
     *
     * @return string|null null when the body is larger than $maxBytes or cannot be read
     */
    private static function bodyWithinCapOf(Request $request, int $maxBytes): ?string
    {
        $body = stream_get_contents($request->getContent(true), $maxBytes + 1);

        return false === $body || \strlen($body) > $maxBytes ? null : $body;
    }

    /**
     * @param list<string> $sortedNames
     *
     * @return array<string, string>|null the fields, or null when the body is not a JSON object of exactly these
     *                                    names, each a string
     */
    private static function stringFieldsOf(string $body, array $sortedNames): ?array
    {
        try {
            $fields = json_decode($body, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($fields) || $sortedNames !== self::sortedKeysOf($fields)) {
            return null;
        }
        $strings = [];
        foreach ($sortedNames as $name) {
            if (!\is_string($fields[$name])) {
                return null;
            }
            $strings[$name] = $fields[$name];
        }

        return $strings;
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
