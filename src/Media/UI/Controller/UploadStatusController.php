<?php

declare(strict_types=1);

namespace App\Media\UI\Controller;

use App\Media\Api\MediaPermission;
use App\Media\Api\UploadedMedia;
use App\Media\Api\UploadedMediaId;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /media/{id}/status, which the presigned-upload controller polls once a second after a complete: answers
 * {"status": "pending" | "ready" | "rejected"} with `Cache-Control: no-store`, in the request transaction. Only the
 * member of the tenant who uploaded the picture may read it (MediaPermission::VIEW_UPLOAD_STATE, then the service
 * filters by uploader again): a person with no tenant or no right to view it, a malformed id, an unknown one, another
 * person's and another organization's all answer 404 with the same message. A poll past media_upload_status_per_user
 * (rate_limiter.yaml) answers 429 with a Retry-After in seconds. A GET with no side effect, so no form token.
 */
final class UploadStatusController extends AbstractController
{
    public const string STATUS_ROUTE = 'media_upload_status';

    public const string NOTHING_UPLOADED = 'Nothing you uploaded has that id.';
    public const string POLLING_TOO_FAST = 'You are checking your upload too often. Wait a minute and try again.';

    /**
     * @param int $statusWindowMinutes the interval of media_upload_status_per_user
     */
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly UploadedMedia $uploadedMedia,
        #[Autowire(service: 'limiter.media_upload_status_per_user')]
        private readonly RateLimiterFactoryInterface $statusPollsPerUser,
        #[Autowire(param: 'media.upload_status_per_user_minutes')]
        private readonly int $statusWindowMinutes,
    ) {
    }

    #[Route('/media/{id}/status', name: self::STATUS_ROUTE, methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $organizationId = $this->tenantContext->get();
        $actor = null !== $organizationId && $this->isGranted(OrganizationPermission::VIEW, $organizationId)
            ? $this->currentActor->actorFor($this->getUser())
            : null;
        if (null === $actor) {
            return self::refusal(self::NOTHING_UPLOADED, Response::HTTP_NOT_FOUND);
        }

        $pollLimit = $this->statusPollsPerUser->create($actor->userId()->toString())->consume();
        if (!$pollLimit->isAccepted()) {
            $refusal = self::refusal(self::POLLING_TOO_FAST, Response::HTTP_TOO_MANY_REQUESTS);
            $refusal->headers->set('Retry-After', (string) $this->secondsToWaitFor($pollLimit));

            return $refusal;
        }

        if (!$this->isGranted(MediaPermission::VIEW_UPLOAD_STATE, new UploadedMediaId($id))) {
            return self::refusal(self::NOTHING_UPLOADED, Response::HTTP_NOT_FOUND);
        }

        $state = $this->uploadedMedia->statesOf($actor->organizationId(), $actor->userId(), [$id])[strtolower($id)] ?? null;
        if (null === $state) {
            return self::refusal(self::NOTHING_UPLOADED, Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse(['status' => $state->value]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * At least 1 and at most the window, read against the wall clock because the limiter counts its window on it.
     */
    private function secondsToWaitFor(RateLimit $refusedLimit): int
    {
        $secondsLeft = (int) ceil((float) $refusedLimit->getRetryAfter()->format('U.u') - microtime(true));

        return min($this->statusWindowMinutes * 60, max(1, $secondsLeft));
    }

    private static function refusal(string $message, int $status): JsonResponse
    {
        $refusal = new JsonResponse(['message' => $message], $status);
        $refusal->headers->set('Cache-Control', 'no-store');

        return $refusal;
    }
}
