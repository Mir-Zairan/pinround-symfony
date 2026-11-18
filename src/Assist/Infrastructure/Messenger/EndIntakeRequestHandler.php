<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\Intake\EndIntakeRequest;
use App\Assist\Application\Intake\IntakeRequests;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Releases or fails a "Check with AI" after its job committed. It never throws on a state it cannot reach: the job's
 * suggestion and usage rows are committed by then, and a throw would make Messenger deliver the job again for nothing.
 * Such a check stays queued until its state expires (IntakeRequests); a stored suggestion still answers its poll.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class EndIntakeRequestHandler
{
    public function __construct(private IntakeRequests $intakeRequests, private LoggerInterface $logger)
    {
    }

    public function __invoke(EndIntakeRequest $endIntakeRequest): void
    {
        try {
            if (null === $endIntakeRequest->failedSentence) {
                $this->intakeRequests->release($endIntakeRequest->organizationId, $endIntakeRequest->checkId, $endIntakeRequest->requesterId);

                return;
            }
            $this->intakeRequests->recordFailure($endIntakeRequest->organizationId, $endIntakeRequest->checkId, $endIntakeRequest->requesterId, $endIntakeRequest->failedSentence);
        } catch (\RuntimeException $unreachableState) {
            $this->logger->warning('An intake check could not be ended; it stays queued until it expires.', ['exception_class' => $unreachableState::class]);
        }
    }
}
