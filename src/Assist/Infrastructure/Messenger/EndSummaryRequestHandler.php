<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\Summaries\EndSummaryRequest;
use App\Assist\Application\Summaries\SummaryRequests;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Releases or fails a ticket's summary request after the summary job committed. It never throws on a state it cannot
 * reach: the job's summary and usage rows are committed by then, and a throw would make Messenger deliver the job
 * again for nothing. Such a request stays queued until its state expires (SummaryRequests).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class EndSummaryRequestHandler
{
    public function __construct(private SummaryRequests $summaryRequests, private LoggerInterface $logger)
    {
    }

    public function __invoke(EndSummaryRequest $endSummaryRequest): void
    {
        try {
            if (null === $endSummaryRequest->failedSentence) {
                $this->summaryRequests->release($endSummaryRequest->organizationId, $endSummaryRequest->ticketId);

                return;
            }
            $this->summaryRequests->recordFailure($endSummaryRequest->organizationId, $endSummaryRequest->ticketId, $endSummaryRequest->requesterId, $endSummaryRequest->failedSentence);
        } catch (\RuntimeException $unreachableState) {
            $this->logger->warning('A summary request could not be ended; it stays queued until it expires.', ['exception_class' => $unreachableState::class]);
        }
    }
}
