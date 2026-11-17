<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeSuggestion;
use App\Assist\Application\AiDraft;
use App\Assist\Application\AiGateway;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Intake\EndIntakeRequest;
use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Intake\IntakeRequests;
use App\Assist\Application\Suggestions;
use App\Assist\Application\Summaries\SummaryRequesters;
use App\Assist\Application\UnknownAiProvider;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Checks the draft a person sent with "Check with AI", in worker-heavy (async_ai) inside the bus transaction under the
 * message's TenantStamp, as GenerateSummaryHandler writes a summary. The gateway decides the policy again with the
 * requester's roles as they are now, and the draft's words again, so a draft that mentions security never reaches
 * cloud AI even when the admin changed the settings after the request.
 *
 * The AI is asked at most once per check: the check is marked on Valkey as one that may have asked the AI, which hands
 * over its draft, before the gateway runs, and a delivery that finds the mark fails the check without asking. A checked
 * answer is stored in ast_suggestion in a savepoint, so a store that fails rolls back only itself and the transaction
 * commits the usage rows Metering wrote with a failed check. Any other outcome is a failed check too, and nothing is
 * thrown. The check's state ends only after that commit (EndIntakeRequest). A message delivered again after a commit
 * does nothing.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class GenerateIntakeSuggestionHandler
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered jobs would ask the AI again. */
    public const string HANDLER_NAME = 'assist.generate_intake_suggestion';

    /** The line every failed check shows; the reason stays out of it, as it may name the provider or the check. */
    public const string FAILED_SENTENCE = 'The AI answer failed. Try again.';

    public function __construct(
        private TenantContext $tenantContext,
        private OnceOnlyHandling $onceOnlyHandling,
        private SummaryRequesters $requesters,
        private IntakeRequests $intakeRequests,
        private AiGateway $aiGateway,
        private Suggestions $suggestions,
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
     * @throws \RuntimeException                          when the check's state cannot be marked, before the AI is asked
     */
    public function __invoke(GenerateIntakeSuggestion $generateIntakeSuggestion): void
    {
        $organizationId = new OrganizationId($generateIntakeSuggestion->organizationId);
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new MessageForAnotherTenantRefused();
        }

        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($organizationId, $generateIntakeSuggestion): void {
            $checkId = new IntakeCheckId($generateIntakeSuggestion->checkId);
            $requesterId = new UserId($generateIntakeSuggestion->requesterId);
            $failure = $this->suggest($organizationId, $checkId, $requesterId);
            if (null === $failure) {
                $this->endRequestAfterCommit(EndIntakeRequest::stored($organizationId, $checkId, $requesterId));

                return;
            }

            $this->logger->warning('An AI intake check failed.', $failure);
            $this->endRequestAfterCommit(EndIntakeRequest::failed($organizationId, $checkId, $requesterId, self::sentenceFor($failure)));
        });
    }

    /**
     * Answers null when the suggestion was stored, else the failure warning's context: what stopped it, as an
     * `intake_outcome` (an AiResult status or a word for a check that may not ask) or as the `exception_class` of what
     * was thrown, with a database error's SQLSTATE and code, never a message. Each key is one RedactionProcessor keeps.
     *
     * @return array{intake_outcome: string}|array{exception_class: class-string<\Throwable>, sqlstate?: string|null, code?: int}|null
     */
    private function suggest(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?array
    {
        $requester = $this->requesters->actorOf($requesterId, $organizationId);
        if (null === $requester) {
            return ['intake_outcome' => 'requester_not_member'];
        }
        $draft = $this->intakeRequests->markAiMayBeAsked($organizationId, $checkId, $requesterId);
        if (null === $draft) {
            return ['intake_outcome' => 'ai_may_have_been_asked_or_not_queued'];
        }

        try {
            $task = new AiTask(AiFeature::Intake, draft: new AiDraft($draft->projectId, $draft->title, $draft->detail, $draft->pinNotes));
            $gatewayAnswer = $this->aiGateway->run($task, $requester);
        } catch (AiNotAllowed|AiRateLimited|AiUnavailable|UnknownAiProvider|UnknownPromptTemplate|\InvalidArgumentException $refusal) {
            return ['exception_class' => $refusal::class];
        }
        if (!$gatewayAnswer->isAnswered() || null === $gatewayAnswer->data) {
            return ['intake_outcome' => $gatewayAnswer->status->value];
        }

        try {
            $suggestion = IntakeSuggestion::fromCheckedData($gatewayAnswer->data, $gatewayAnswer->model, $gatewayAnswer->promptVersion);
            $this->connection->transactional(function () use ($checkId, $organizationId, $requesterId, $suggestion): void {
                $this->suggestions->addIntake($checkId, $organizationId, $requesterId, $suggestion, $this->clock->now());
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
     * @param array{intake_outcome?: string, exception_class?: class-string<\Throwable>} $failure
     */
    private static function sentenceFor(array $failure): string
    {
        return AiRateLimited::class === ($failure['exception_class'] ?? null) ? AiRateLimited::PER_PERSON_MESSAGE : self::FAILED_SENTENCE;
    }

    /**
     * Held back until this bus has handled the job and its transaction has committed; dropped when it rolls back.
     */
    private function endRequestAfterCommit(EndIntakeRequest $endIntakeRequest): void
    {
        $this->commandBus->dispatch($endIntakeRequest, [new DispatchAfterCurrentBusStamp()]);
    }
}
