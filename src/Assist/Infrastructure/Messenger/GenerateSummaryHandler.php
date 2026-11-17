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
use App\Ticketing\Api\TicketSummaryCommands;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Writes the summary a person asked for, in worker-heavy (async_ai) inside the bus transaction under the message's
 * TenantStamp. The gateway decides the policy again with the requester's roles as they are now.
 *
 * The AI is asked at most once per request: the request is marked on Valkey as one that may have asked the AI before the
 * gateway runs (markAiMayBeAsked), and a delivery that finds the mark fails the request without asking. The mark comes
 * before the gateway because only there can it stop a second paid call: the gateway may throw after the provider
 * answered, and nothing outside it can tell. So a delivery that stopped in the gateway's policy check, key read or limit
 * step, before any provider call, also leaves the mark, and its retry fails the request instead of asking. An answer is stored through Ticketing's TicketSummaryCommands in a savepoint, so a store that fails for any reason
 * rolls back only itself, and the transaction commits the usage rows Metering wrote with a failed request. Any other
 * outcome is a failed request too, and nothing is thrown. The request state on Valkey ends only after that commit
 * (EndSummaryRequest), so a Valkey failure never rolls the paid call back and a poll never sees the request end before
 * the summary is readable. A message delivered again after a commit does nothing.
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
        private TicketSummaryCommands $ticketSummaryCommands,
        private Connection $connection,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any read
     * @throws MessageForAnotherTenantRefused             when the message names another organization than the one set
     * @throws \RuntimeException                          when the request state cannot be marked, before the AI is asked
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

            $this->logger->warning('An AI summary failed.', $failure);
            $this->endRequestAfterCommit(EndSummaryRequest::failed($organizationId, $ticketId, $requesterId, self::sentenceFor($failure)));
        });
    }

    /**
     * Answers null when Ticketing stored the summary, else the failure warning's context: what stopped it, as an
     * `outcome` (an AiResult status or a word for a request that may not ask) or as the `exception_class` of what the
     * gateway or Ticketing threw, with a database error's SQLSTATE and code, never a message. Each key is one
     * RedactionProcessor keeps.
     *
     * @return array{outcome: string}|array{exception_class: class-string<\Throwable>, sqlstate?: string|null, code?: int}|null
     */
    private function summarize(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): ?array
    {
        $requester = $this->summaryRequesters->actorOf($requesterId, $organizationId);
        if (null === $requester) {
            return ['outcome' => 'requester_not_member'];
        }
        if (!$this->summaryRequests->markAiMayBeAsked($organizationId, $ticketId, $requesterId)) {
            return ['outcome' => 'ai_may_have_been_asked_or_not_queued'];
        }

        try {
            $gatewayAnswer = $this->aiGateway->run(new AiTask(AiFeature::Summary, $ticketId), $requester);
        } catch (AiNotAllowed|AiRateLimited|AiUnavailable|UnknownAiProvider|UnknownPromptTemplate $refusal) {
            return ['exception_class' => $refusal::class];
        }
        if (!$gatewayAnswer->isAnswered() || null === $gatewayAnswer->text) {
            return ['outcome' => $gatewayAnswer->status->value];
        }

        try {
            $answerText = $gatewayAnswer->text;
            $this->connection->transactional(function () use ($organizationId, $ticketId, $answerText, $requesterId, $gatewayAnswer): void {
                $this->ticketSummaryCommands->storeAiSummary(
                    $organizationId,
                    $ticketId,
                    $answerText,
                    $requesterId,
                    $gatewayAnswer->model,
                    $gatewayAnswer->promptVersion,
                    $this->clock->now(),
                );
            });
        } catch (\Throwable $storeFailure) {
            // Not retried: a retry would not ask the AI again (markAiMayBeAsked), so it could not store the answer either.
            // Class, SQLSTATE and code only: a database message can quote the answer.
            if ($storeFailure instanceof DriverException) {
                return ['exception_class' => $storeFailure::class, 'sqlstate' => $storeFailure->getSQLState(), 'code' => $storeFailure->getCode()];
            }

            return ['exception_class' => $storeFailure::class];
        }

        return null;
    }

    /**
     * @param array{outcome?: string, exception_class?: class-string<\Throwable>} $failure
     */
    private static function sentenceFor(array $failure): string
    {
        return AiRateLimited::class === ($failure['exception_class'] ?? null) ? AiRateLimited::PER_PERSON_MESSAGE : self::FAILED_SENTENCE;
    }

    /**
     * Held back until this bus has handled the job and its transaction has committed; dropped when it rolls back.
     */
    private function endRequestAfterCommit(EndSummaryRequest $endSummaryRequest): void
    {
        $this->commandBus->dispatch($endSummaryRequest, [new DispatchAfterCurrentBusStamp()]);
    }
}
