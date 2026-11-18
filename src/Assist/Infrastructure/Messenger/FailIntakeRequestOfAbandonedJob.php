<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Intake\IntakeRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Fails a "Check with AI" when Messenger gives up on its GenerateIntakeSuggestion job, as no job is left to end it:
 * else the page would poll a queued check until its state expires, and the draft would wait there that long. A failure
 * that will be retried leaves the check queued. Runs after SendFailedMessageForRetryListener (priority 100), which
 * decides the retry.
 */
#[AsEventListener]
final readonly class FailIntakeRequestOfAbandonedJob
{
    public function __construct(private IntakeRequests $intakeRequests, private LoggerInterface $logger)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $generateIntakeSuggestion = $event->getEnvelope()->getMessage();
        if (!$generateIntakeSuggestion instanceof GenerateIntakeSuggestion || $event->willRetry()) {
            return;
        }
        $tenantStamp = $event->getEnvelope()->last(TenantStamp::class);
        if (null === $tenantStamp) {
            return;
        }

        try {
            $organizationId = new OrganizationId($generateIntakeSuggestion->organizationId);
            // A job stamped for another organization than it names was refused unread; the check it names is not its own.
            if (!$tenantStamp->organizationId()->equals($organizationId)) {
                return;
            }
            $this->intakeRequests->recordFailure(
                $organizationId,
                new IntakeCheckId($generateIntakeSuggestion->checkId),
                new UserId($generateIntakeSuggestion->requesterId),
                GenerateIntakeSuggestionHandler::FAILED_SENTENCE,
            );
        } catch (\InvalidArgumentException|\RuntimeException $unendedRequest) {
            // A throw here would stop the worker; the check then stays queued until it expires.
            $this->logger->warning('An intake check could not be ended; it stays queued until it expires.', ['exception_class' => $unendedRequest::class]);
        }
    }
}
