<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\AiGateway;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\SummaryRequesters;
use App\Assist\Application\Summaries\SummaryRequests;
use App\Assist\Application\UnknownAiProvider;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\SummaryGenerated;
use App\Ticketing\Api\GeneratedSummaryRefused;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Writes the summary a person asked for, in worker-ai inside the bus transaction under the message's TenantStamp. The
 * gateway decides the policy again with the requester's roles as they are now. An answer goes to Ticketing as
 * SummaryGenerated, in this transaction, and the request state is released; any other outcome, Ticketing refusing to
 * store the answer included, is recorded as a failed request and nothing is thrown, so the transaction commits the
 * usage rows Metering wrote and Messenger never retries a paid call. A message delivered again does nothing.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class GenerateSummaryHandler
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered jobs would ask the AI again. */
    public const string HANDLER_NAME = 'assist.generate_summary';

    /** The line every failed request shows; the reason stays out of it, as it may name the provider or the check. */
    public const string FAILED_SENTENCE = 'The AI answer failed. Try again.';

    public function __construct(
        private TenantContext $tenantContext,
        private OnceOnlyHandling $onceOnlyHandling,
        private SummaryRequesters $summaryRequesters,
        private SummaryRequests $summaryRequests,
        private AiGateway $aiGateway,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any read
     * @throws MessageForAnotherTenantRefused             when the message names another organization than the one set
     */
    public function __invoke(GenerateSummary $generateSummary): void
    {
        $organizationId = new OrganizationId($generateSummary->organizationId);
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new MessageForAnotherTenantRefused();
        }

        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($generateSummary, $organizationId): void {
            $ticketId = new TicketId($generateSummary->ticketId);
            $requesterId = new UserId($generateSummary->requesterId);
            $failure = $this->summarize($generateSummary, $organizationId, $ticketId, $requesterId);
            if (null === $failure) {
                $this->summaryRequests->release($organizationId, $ticketId);

                return;
            }

            $this->logger->warning('An AI summary failed.', ['outcome' => $failure]);
            $this->summaryRequests->recordFailure($organizationId, $ticketId, $requesterId, self::FAILED_SENTENCE);
        });
    }

    /**
     * @return string|null null when Ticketing stored the summary; else what stopped it, an AiResult status or the class
     *                     of what the gateway or Ticketing threw, never its message
     *
     * @throws HandlerFailedException when storing the summary failed for another reason than a summary rule
     */
    private function summarize(GenerateSummary $generateSummary, OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): ?string
    {
        $requester = $this->summaryRequesters->actorOf($requesterId, $organizationId);
        if (null === $requester) {
            return 'requester_not_member';
        }

        try {
            $gatewayAnswer = $this->aiGateway->run(new AiTask(AiFeature::Summary, $ticketId), $requester);
        } catch (AiNotAllowed|AiRateLimited|AiUnavailable|UnknownAiProvider|UnknownPromptTemplate $refusal) {
            return $refusal::class;
        }
        if (!$gatewayAnswer->isAnswered() || null === $gatewayAnswer->text) {
            return $gatewayAnswer->status->value;
        }

        try {
            $this->commandBus->dispatch(new SummaryGenerated(
                $generateSummary->organizationId,
                $generateSummary->ticketId,
                $gatewayAnswer->text,
                $generateSummary->requesterId,
                $gatewayAnswer->model,
                $gatewayAnswer->promptVersion,
                $this->clock->now(),
            ));
        } catch (HandlerFailedException $storeFailure) {
            if (!self::isOnlyRefusedSummaries($storeFailure)) {
                throw $storeFailure;
            }

            return GeneratedSummaryRefused::class;
        }

        return null;
    }

    /**
     * Ticketing refusing the answer is final, and the dispatch rolled back to its savepoint; any other failure is not.
     */
    private static function isOnlyRefusedSummaries(HandlerFailedException $storeFailure): bool
    {
        $wrappedFailures = $storeFailure->getWrappedExceptions();

        return [] !== $wrappedFailures && \count($wrappedFailures) === \count($storeFailure->getWrappedExceptions(GeneratedSummaryRefused::class));
    }
}
