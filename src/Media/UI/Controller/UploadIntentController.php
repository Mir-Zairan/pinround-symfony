<?php

declare(strict_types=1);

namespace App\Media\UI\Controller;

use App\Media\Api\MediaPermission;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Media\Application\DirectUploads;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two JSON calls of a direct upload (media-pipeline.md, Upload flow; ADR-0011), for the presigned-upload controller.
 * POST /media/intents {purpose, mime, bytes, target: {kind, id}} begins one in the request transaction and answers 201
 * with where to PUT the file; POST /media/intents/{id}/complete, after the PUT, answers 202 with the pending media
 * object. Both need a member of the tenant, the form token in the X-CSRF-Token header and a body of at most 4 KB.
 *
 * In that order: a person with no actor in the tenant is forbidden; a missing or wrong token answers 422 as an expired
 * page; a larger body 413; a body that is no begin request 400. Begin then asks MediaPermission UPLOAD on the target (403), and Uploads refuses a file type or size, a person
 * with too many uploads waiting, or a file the organization's storage has no room for, with its plain message (422)
 * before any URL is signed. Complete runs outside the request transaction, as Uploads::complete() opens its own, and answers an intent its person did not
 * begin, of another organization or with a malformed id as not found (404), a target they may no longer add to as
 * forbidden (403), and a used or expired intent with Uploads' message (422).
 */
final class UploadIntentController extends AbstractController
{
    public const string BEGIN_ROUTE = 'media_upload_intent_begin';
    public const string COMPLETE_ROUTE = 'media_upload_intent_complete';

    public const string CSRF_HEADER = 'X-CSRF-Token';
    public const string CSRF_ID = 'media_upload';

    /** A begin request is a few hundred bytes; anything larger is refused before it is parsed. */
    public const int MAX_BODY_BYTES = 4096;

    public const string NOT_ALLOWED = 'You may not add a file here.';
    public const string NOTHING_TO_ADD_TO = 'Nothing you may add a file to has that id.';
    /** An expired form token and an expired intent both mean the page sat open too long, so they say it alike. */
    public const string FORM_EXPIRED = DirectUploads::EXPIRED;
    public const string BODY_TOO_LARGE = 'This upload request is larger than 4 KB. Reload the page and try again.';
    public const string NOT_A_BEGIN_REQUEST = 'This upload request needs a purpose, a file type, a size and a target. Reload the page and try again.';

    /** One media object waits for the processing worker (MediaStatus::Pending, which the UI may not use). */
    private const string PENDING = 'pending';

    /** The method the slot's URL is signed for. */
    private const string PUT = 'PUT';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly Transactions $transactions,
        private readonly Uploads $uploads,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/media/intents', name: self::BEGIN_ROUTE, methods: ['POST'])]
    public function begin(Request $request): JsonResponse
    {
        $actor = $this->memberActor();
        if (null === $actor) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }
        if (!$this->hasValidToken($request)) {
            return self::refusal(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $body = self::bodyWithinCapOf($request);
        if (null === $body) {
            return self::refusal(self::BODY_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $beginRequest = self::beginRequestOf($body);
        if (null === $beginRequest) {
            return self::refusal(self::NOT_A_BEGIN_REQUEST, Response::HTTP_BAD_REQUEST);
        }
        if (!$this->isGranted(MediaPermission::UPLOAD, $beginRequest['target'])) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }

        try {
            $slot = $this->uploads->begin($actor->organizationId(), $actor->userId(), $beginRequest['target'], $beginRequest['purpose'], $beginRequest['mime'], $beginRequest['bytes'], $this->clock->now());
        } catch (UploadRefused $refused) {
            return self::refusal($refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UploadTargetDenied) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(
            [
                'intentId' => $slot->intentId,
                'url' => $slot->url,
                'method' => self::PUT,
                'headers' => $slot->headers,
                'expiresAt' => $slot->expiresAt->format(\DATE_ATOM),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/media/intents/{id}/complete', name: self::COMPLETE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function complete(string $id, Request $request): JsonResponse
    {
        // Outside a transaction CurrentActor finds no roles under row-level security, so the organization voter would deny.
        $actor = $this->transactions->run($this->memberActor(...));
        if (null === $actor) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        }
        if (!$this->hasValidToken($request)) {
            return self::refusal(self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null === self::bodyWithinCapOf($request)) {
            return self::refusal(self::BODY_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $mediaId = $this->uploads->complete($actor->organizationId(), $actor->userId(), $id, $this->clock->now());
        } catch (UploadIntentNotFound) {
            return self::refusal(self::NOTHING_TO_ADD_TO, Response::HTTP_NOT_FOUND);
        } catch (UploadTargetDenied) {
            return self::refusal(self::NOT_ALLOWED, Response::HTTP_FORBIDDEN);
        } catch (UploadRefused $refused) {
            return self::refusal($refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['mediaId' => $mediaId, 'status' => self::PENDING], Response::HTTP_ACCEPTED);
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

    private function hasValidToken(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF_ID, $request->headers->get(self::CSRF_HEADER, ''));
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

    /**
     * @return array{purpose: string, mime: string, bytes: int, target: UploadTarget}|null null when $body is no JSON
     *                                                                                     object with these fields of
     *                                                                                     these types
     */
    private static function beginRequestOf(string $body): ?array
    {
        try {
            $fields = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($fields)) {
            return null;
        }
        $purpose = $fields['purpose'] ?? null;
        $mime = $fields['mime'] ?? null;
        $bytes = $fields['bytes'] ?? null;
        $target = $fields['target'] ?? null;
        if (!\is_string($purpose) || !\is_string($mime) || !\is_int($bytes) || !\is_array($target)) {
            return null;
        }
        $targetKind = $target['kind'] ?? null;
        $targetId = $target['id'] ?? null;
        if (!\is_string($targetKind) || !\is_string($targetId)) {
            return null;
        }

        return ['purpose' => $purpose, 'mime' => $mime, 'bytes' => $bytes, 'target' => new UploadTarget($targetKind, $targetId)];
    }

    private static function refusal(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
