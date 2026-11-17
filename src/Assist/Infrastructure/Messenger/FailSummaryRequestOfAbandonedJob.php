<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\SummaryRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Fails a ticket's summary request when Messenger gives up on its GenerateSummary job, as no job is left to end it:
 * else the button would say "already on its way" until the queued state expires. A failure that will be retried
 * leaves the request queued. Runs after SendFailedMessageForRetryListener (priority 100), which decides the retry.
 */
#[AsEventListener]
final readonly class FailSummaryRequestOfAbandonedJob
{
    public function __construct(private SummaryRequests $summaryRequests, private LoggerInterface $logger)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $generateSummary = $event->getEnvelope()->getMessage();
        if (!$generateSummary instanceof GenerateSummary || $event->willRetry()) {
            return;
        }
        $tenantStamp = $event->getEnvelope()->last(TenantStamp::class);
        if (null === $tenantStamp) {
            return;
        }

        try {
            $organizationId = new OrganizationId($generateSummary->organizationId);
            // A job stamped for another organization than it names was refused unread; the request it names is not its own.
            if (!$tenantStamp->organizationId()->equals($organizationId)) {
                return;
            }
            $this->summaryRequests->recordFailure(
                $organizationId,
                new TicketId($generateSummary->ticketId),
                new UserId($generateSummary->requesterId),
                GenerateSummaryHandler::FAILED_SENTENCE,
            );
        } catch (\InvalidArgumentException|\RuntimeException $unendedRequest) {
            // A throw here would stop the worker; the request then stays queued until it expires.
            $this->logger->warning('A summary request could not be ended; it stays queued until it expires.', ['exception_class' => $unendedRequest::class]);
        }
    }
}
