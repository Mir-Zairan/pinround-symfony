<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\AiGateway;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Summaries\EndSummaryRequest;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\SummaryRequesters;
use App\Assist\Application\UnknownAiProvider;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\GeneratedSummaryRefused;
use App\Ticketing\Api\TicketSummaryCommands;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Writes the summary a person asked for, in worker-heavy (async_ai) inside the bus transaction under the message's
 * TenantStamp. The gateway decides the policy again with the requester's roles as they are now. An answer is stored
 * through Ticketing's TicketSummaryCommands in this transaction; any other outcome, Ticketing refusing to store the
 * answer included, is a failed request and nothing is thrown, so the transaction commits the usage rows Metering wrote
 * and Messenger never retries a paid call. The request state on Valkey changes only after that commit
 * (EndSummaryRequest), so a Valkey failure never rolls the paid call back and a poll never sees the request end before
 * the summary is readable. A message delivered again does nothing.
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
        private AiGateway $aiGateway,
        private TicketSummaryCommands $ticketSummaryCommands,
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

        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($organizationId, $generateSummary): void {
            $ticketId = new TicketId($generateSummary->ticketId);
            $requesterId = new UserId($generateSummary->requesterId);
            $failure = $this->summarize($organizationId, $ticketId, $requesterId);
            if (null === $failure) {
                $this->endRequestAfterCommit(EndSummaryRequest::stored($organizationId, $ticketId, $requesterId));

                return;
            }

            $this->logger->warning('An AI summary failed.', ['outcome' => $failure]);
            $this->endRequestAfterCommit(EndSummaryRequest::failed($organizationId, $ticketId, $requesterId, self::FAILED_SENTENCE));
        });
    }

    /**
     * @return string|null null when Ticketing stored the summary; else what stopped it, an AiResult status or the class
     *                     of what the gateway or Ticketing threw, never its message
     */
    private function summarize(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): ?string
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
            $this->ticketSummaryCommands->storeAiSummary(
                $organizationId,
                $ticketId,
                $gatewayAnswer->text,
                $requesterId,
                $gatewayAnswer->model,
                $gatewayAnswer->promptVersion,
                $this->clock->now(),
            );
        } catch (GeneratedSummaryRefused $refused) {
            // Final, as the same answer is refused again; it wrote nothing, so the transaction may still commit.
            return $refused::class;
        }

        return null;
    }

    /**
     * Held back until this bus has handled the job and its transaction has committed; dropped when it rolls back.
     */
    private function endRequestAfterCommit(EndSummaryRequest $endSummaryRequest): void
    {
        $this->commandBus->dispatch($endSummaryRequest, [new DispatchAfterCurrentBusStamp()]);
    }
}
