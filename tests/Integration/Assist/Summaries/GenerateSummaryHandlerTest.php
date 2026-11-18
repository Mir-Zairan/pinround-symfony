<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Summaries;

use App\Assist\Api\Summaries;
use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiGateway;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiResult;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Gateway\GuardedAiGateway;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\SummaryRequests;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Messenger\GenerateSummaryHandler;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\Assist\Infrastructure\Valkey\ValkeySummaryRequests;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\Summary\SummaryRows;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketSummaryCommands;
use App\Ticketing\Application\CheckedTicketSummaryCommands;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketSummaries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The summary job as worker-heavy runs it: Omar asks for a summary of the checkout ticket through the Api, and
 * Messenger's worker handles the queued GenerateSummary from the in-memory async_ai transport on the real command.bus,
 * gateway (FakeProvider), metering and Ticketing's store, each in a transaction that commits. The ticket has a human summary
 * already, which an answer must replace as the current one and a failure must leave. Its text carries a marker that
 * must reach no log. The clock is frozen in March 2027, a month whose usage partition the migrations always create.
 */
final class GenerateSummaryHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c7d01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c7d02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000c7d21';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c7d41';
    private const string HUMAN_SUMMARY = '0197a3c0-0000-7000-8000-0000000c7d51';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c7d11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c7d12';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string TICKET_TEXT = 'Pressing Pay does nothing MARKER-7cd.';
    private const string AI_ON_IN_THE_CLOUD = '{"on": true, "where": "cloud"}';
    private const string USAGE_LIMITER_PREFIX = 'ai_calls_per_user-';
    private const string FAILED_SENTENCE = 'The AI answer failed. Try again.';
    /** One character over ast_usage_model_check, so the usage insert of an answer from this model fails in PostgreSQL. */
    private const int TOO_LONG_MODEL_LENGTH = 201;
    /** max_retries of async_ai in config/packages/messenger.yaml. */
    private const int ASYNC_AI_MAX_RETRIES = 5;
    private const array HUMAN_SUMMARY_ROW = [
        'text' => 'Lena: Pay does nothing on the last step.', 'source' => 'human', 'author_id' => null, 'model' => null,
        'prompt_version' => null, 'is_current' => true, 'created_at' => '2027-03-14 09:00:00',
    ];

    private ?TicketId $checkoutTicketId = null;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [self::LENA => ['Lena', 'lead', 'developer']]);
        $this->shopTeamRows()->addUser(self::OMAR, 'Omar');
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->connection()->executeStatement(
                "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::OMAR],
            );
            $this->connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('ai', ?::jsonb), '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::AI_ON_IN_THE_CLOUD],
            );
        });
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $this->checkoutTicketId = new TicketFactory($ticketCommands, $this->transactions(), $this->connection())
            ->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Checkout fails', 'description' => self::TICKET_TEXT]);
        $this->summaryRows()->addOf(self::ACME_FOODS, self::HUMAN_SUMMARY, $this->checkoutTicketId()->toString(), self::HUMAN_SUMMARY_ROW['text'], true, '2027-03-14 09:00:00+00');
        $this->forgetRequestStateAndCountedCalls();
        $this->capturedLogRecords()->clear();
    }

    protected function tearDown(): void
    {
        $this->forgetRequestStateAndCountedCalls();
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRows();
        $this->checkoutTicketId = null;
        parent::tearDown();
    }

    #[Test]
    public function anAnswerBecomesTheCurrentSummaryLabeledWithItsRequesterModelAndPromptVersionAndEndsTheRequest(): void
    {
        $this->requestAsOmar();

        self::runTheWorkerUntilIdle('async_ai');

        self::assertSame([
            [...self::HUMAN_SUMMARY_ROW, 'is_current' => false],
            [
                'text' => FakeProvider::fixedTextFor(AiFeature::Summary), 'source' => 'ai', 'author_id' => self::OMAR,
                'model' => 'meta-llama/Llama-3.1-8B-Instruct', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-15 10:00:00',
            ],
        ], $this->summaryRowsOfTheCheckoutTicket());
        self::assertNull($this->requestState());
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertSame(['answered'], $this->usageStatuses());
        self::assertSame(1, $this->processedMessageCount());
    }

    #[Test]
    public function neitherTheTicketTextNorTheAnswerReachesALogAtInfoOrAbove(): void
    {
        $this->requestAsOmar();
        $this->capturedLogRecords()->clear();

        self::runTheWorkerUntilIdle('async_ai');

        self::assertCount(2, $this->summaryRowsOfTheCheckoutTicket(), 'No summary was stored, so the answer never passed a log.');
        // Prod writes info and up (monolog.yaml); the debug records here are DBAL's statements, which it logs with their
        // parameters only when kernel.debug is on, so never in prod.
        $records = array_filter($this->capturedLogRecords()->all(), static fn (LogRecord $record): bool => $record->level->isHigherThan(Level::Debug));
        self::assertNotSame([], $records);
        $formatter = new JsonFormatter();
        foreach ($records as $record) {
            $line = $formatter->format($record);
            self::assertStringNotContainsString('MARKER-7cd', $line);
            self::assertStringNotContainsString(FakeProvider::fixedTextFor(AiFeature::Summary), $line);
        }
    }

    /**
     * @return iterable<string, array{string, int, list<string>, array<string, string>, string}>
     */
    public static function failures(): iterable
    {
        yield 'the provider failed' => ['provider fails', 1, ['failed'], ['summary_outcome' => 'failed'], self::FAILED_SENTENCE];
        yield 'Omar reached his limit after the request' => ['limit reached', 0, [], ['exception_class' => AiRateLimited::class], 'Too many AI requests. Try again in a minute.'];
        yield 'the organization key cannot be read' => ['unreadable key', 0, [], ['exception_class' => AiUnavailable::class], self::FAILED_SENTENCE];
        yield 'the admin turned AI off after the request' => ['AI turned off', 0, [], ['exception_class' => AiNotAllowed::class], self::FAILED_SENTENCE];
        yield 'Omar left the organization after the request' => ['Omar left', 0, [], ['summary_outcome' => 'requester_not_member'], self::FAILED_SENTENCE];
    }

    /**
     * @param list<string>          $expectedUsageStatuses
     * @param array<string, string> $expectedContext
     */
    #[Test]
    #[DataProvider('failures')]
    public function aJobThatGetsNoAnswerLeavesAFailedRequestAndTheCurrentSummaryAndIsNotRetried(string $failure, int $expectedProviderCalls, array $expectedUsageStatuses, array $expectedContext, string $expectedSentence): void
    {
        $this->requestAsOmar();
        $this->arrange($failure);

        self::runTheWorkerUntilIdle('async_ai');

        $this->assertTheRequestFailedAndTheSummaryStayed($expectedSentence);
        self::assertCount($expectedProviderCalls, $this->fakeProvider()->calls());
        self::assertSame($expectedUsageStatuses, $this->usageStatuses(), 'The job\'s transaction did not commit the usage rows.');
        self::assertSame([['An AI summary failed.', $expectedContext]], $this->warnings());
    }

    #[Test]
    public function aRejectedAnswerLeavesAFailedRequestAndTheCurrentSummary(): void
    {
        $this->givenGatewayAnswering(AiResult::rejected('The AI answer to summary.v1 has more than 60 words.', 'meta-llama/Llama-3.1-8B-Instruct', 'fake', 'summary.v1', 'AI summary'));
        $this->requestAsOmar();

        self::runTheWorkerUntilIdle('async_ai');

        $this->assertTheRequestFailedAndTheSummaryStayed();
        self::assertSame([['An AI summary failed.', ['summary_outcome' => 'rejected']]], $this->warnings());
    }

    /**
     * The output check caps a summary's words, not its characters, so a few long words pass it and Ticketing refuses
     * them. Without the catch the refusal escapes the handler and Messenger retries the paid call.
     */
    #[Test]
    public function anAnswerTicketingRefusesToStoreLeavesAFailedRequestAndIsNotRetried(): void
    {
        $tooLongText = str_repeat('Checkout-step-three-ignores-the-Pay-button-MARKER-7cd ', 40);
        $this->givenGatewayAnswering(AiResult::withText($tooLongText, 'meta-llama/Llama-3.1-8B-Instruct', 'fake', 'summary.v1', 'AI summary'));
        $this->requestAsOmar();
        $this->capturedLogRecords()->clear();

        self::runTheWorkerUntilIdle('async_ai');

        self::assertGreaterThan(2000, mb_strlen(trim($tooLongText)));
        $this->assertTheRequestFailedAndTheSummaryStayed();
        self::assertSame(1, $this->processedMessageCount(), 'The job\'s transaction did not commit.');
        self::assertSame([['An AI summary failed.', ['exception_class' => \App\Ticketing\Api\GeneratedSummaryRefused::class]]], $this->warnings());
        $formatter = new JsonFormatter();
        foreach ($this->capturedLogRecords()->all() as $record) {
            if ($record->level->isHigherThan(Level::Debug)) {
                self::assertStringNotContainsString('MARKER-7cd', $formatter->format($record));
            }
        }
    }

    #[Test]
    public function theSameJobDeliveredTwiceAsksTheAiAndStoresTheSummaryOnce(): void
    {
        $this->requestAsOmar();
        self::runTheWorkerUntilIdle('async_ai');
        $sent = $this->asyncAi()->getSent();
        self::assertCount(1, $sent);

        $this->asyncAi()->send($sent[0]);
        self::runTheWorkerUntilIdle('async_ai');

        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertCount(2, $this->summaryRowsOfTheCheckoutTicket());
        self::assertSame(['answered'], $this->usageStatuses());
        self::assertSame(1, $this->processedMessageCount());
        self::assertNull($this->requestState());
    }

    #[Test]
    public function aJobStampedWithAnotherOrganizationThanItNamesIsRefusedBeforeAnythingRuns(): void
    {
        $this->requestAsOmar();
        $generateSummary = new GenerateSummary(self::ACME_FOODS, $this->checkoutTicketId()->toString(), self::OMAR);

        try {
            self::getContainer()->get('command.bus')->dispatch(new Envelope($generateSummary, [new TenantStamp(new OrganizationId(self::BETA_BAKERY)), new ReceivedStamp('async_ai')]));
            self::fail('The job ran under another organization than it names.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(MessageForAnotherTenantRefused::class, $failure->getPrevious());
        }

        self::assertSame([], $this->fakeProvider()->calls());
        $state = $this->requestState();
        self::assertNotNull($state);
        self::assertTrue($state->isQueued(), 'The refused job changed the request state.');
        self::assertSame(0, $this->processedMessageCount());
    }

    /**
     * The store writes the summary rows, then PostgreSQL refuses a statement and aborts the transaction, as a failed
     * tkt_summary write does. Without the savepoint the job's commit fails, the usage row of the paid call is lost and
     * Messenger retries the job.
     */
    #[Test]
    public function aStoreTheDatabaseRefusesIsUndoneAloneAndKeepsTheUsageRowAndFailsTheRequestWithoutARetry(): void
    {
        // Built by hand: resolving the container's store would lock its service id against the replacement below.
        $realStore = new CheckedTicketSummaryCommands($this->tenantContext(), new DbalTicketSummaries($this->connection(), $this->tenantContext()));
        self::getContainer()->set(CheckedTicketSummaryCommands::class, new readonly class($realStore, $this->connection()) implements TicketSummaryCommands {
            public function __construct(private TicketSummaryCommands $realStore, private Connection $connection)
            {
            }

            public function storeAiSummary(OrganizationId $organizationId, TicketId $ticketId, string $text, UserId $requesterId, string $model, string $promptVersion, \DateTimeImmutable $generatedAt): void
            {
                $this->realStore->storeAiSummary($organizationId, $ticketId, $text, $requesterId, $model, $promptVersion, $generatedAt);
                $this->connection->executeQuery('SELECT 1 / 0');
            }

            public function storeChosenLaptopSummary(OrganizationId $organizationId, TicketId $ticketId, string $text, UserId $posterId, string $model, string $promptVersion, UserId $chooserId, \DateTimeImmutable $chosenAt): void
            {
                throw new \LogicException('The summary job never stores a laptop summary.');
            }
        });
        $this->requestAsOmar();

        self::runTheWorkerUntilIdle('async_ai');

        $this->assertTheRequestFailedAndTheSummaryStayed();
        self::assertSame(['answered'], $this->usageStatuses());
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertSame(1, $this->processedMessageCount());
        self::assertSame([['An AI summary failed.', ['exception_class' => DriverException::class, 'sqlstate' => '22012', 'code' => 7]]], $this->warnings());
    }

    /**
     * The usage insert fails in PostgreSQL after the provider answered, so the job's transaction rolls back and
     * Messenger delivers it again. That delivery finds the request marked as one that may have asked the AI and fails it
     * without asking again.
     */
    #[Test]
    public function aJobRetriedAfterTheAiWasAskedFailsTheRequestWithoutAskingAgain(): void
    {
        $this->requestAsOmar();
        $this->storeAiSettings(json_encode(['on' => true, 'where' => 'cloud', 'provider_model' => str_repeat('m', self::TOO_LONG_MODEL_LENGTH)], \JSON_THROW_ON_ERROR));

        self::runTheWorkerUntilIdle('async_ai');
        $queued = $this->requestState();
        self::assertNotNull($queued);
        self::assertTrue($queued->isQueued(), 'The request ended although its job will be retried.');
        self::assertCount(2, $this->asyncAi()->getSent(), 'The job was not retried, so the usage insert did not fail.');
        $this->runTheWorkerThroughRetries(1);

        self::assertCount(1, $this->fakeProvider()->calls(), 'The retry asked the AI again.');
        $this->assertTheRequestFailedAndTheSummaryStayed(expectedDeliveries: 2);
        self::assertSame([], $this->usageStatuses(), 'The test proves nothing if the usage insert did not fail.');
        self::assertSame(1, $this->processedMessageCount());
        $warnings = $this->warnings();
        self::assertSame([
            'Error thrown while handling message {class}. Sending for retry #{retryCount} using {delay} ms delay. Error: "{error}"',
            'An AI summary failed.',
        ], array_column($warnings, 0), 'The first delivery is retried and the second fails the request, each warning once.');
        self::assertSame([GenerateSummary::class, 1], [$warnings[0][1]['class'] ?? null, $warnings[0][1]['retryCount'] ?? null]);
        self::assertSame(['summary_outcome' => 'ai_may_have_been_asked_or_not_queued'], $warnings[1][1]);
    }

    /**
     * Every delivery fails before the AI is asked, so no job is left to end the request when Messenger gives up.
     */
    #[Test]
    public function aJobMessengerGivesUpOnFailsTheRequest(): void
    {
        $watchedRequests = $this->watchSummaryRequests();
        $watchedRequests->failEveryMarkWith(new \RedisException('read error on connection'));
        $this->requestAsOmar();

        self::runTheWorkerUntilIdle('async_ai');
        $this->runTheWorkerThroughRetries(self::ASYNC_AI_MAX_RETRIES - 1);
        $queued = $this->requestState();
        self::assertNotNull($queued);
        self::assertTrue($queued->isQueued(), 'The request ended although its job has a retry left.');
        $this->runTheWorkerThroughRetries(1);

        self::assertCount(1 + self::ASYNC_AI_MAX_RETRIES, $this->asyncAi()->getSent(), 'The job was not delivered once and retried five times.');
        self::assertSame([], $this->fakeProvider()->calls());
        $this->assertTheRequestFailedAndTheSummaryStayed(expectedDeliveries: 1 + self::ASYNC_AI_MAX_RETRIES);
        self::assertSame(0, $this->processedMessageCount());
    }

    /**
     * A page that polls sees the request end only when it can read the new summary, or it stops polling and shows the
     * old one.
     */
    #[Test]
    public function theRequestIsReleasedOnlyOnceTheStoredSummaryAndItsUsageRowAreCommitted(): void
    {
        $watchedRequests = $this->watchSummaryRequests();
        $this->requestAsOmar();

        self::runTheWorkerUntilIdle('async_ai');

        self::assertSame([['release', ['current_summary_source' => 'ai', 'usage_statuses' => ['answered']]]], $watchedRequests->endings());
        self::assertNull($this->requestState());
    }

    #[Test]
    public function aFailedRequestIsRecordedOnlyOnceItsUsageRowIsCommitted(): void
    {
        $watchedRequests = $this->watchSummaryRequests();
        $this->requestAsOmar();
        $this->arrange('provider fails');

        self::runTheWorkerUntilIdle('async_ai');

        self::assertSame([['recordFailure', ['current_summary_source' => 'human', 'usage_statuses' => ['failed']]]], $watchedRequests->endings());
        $this->assertTheRequestFailedAndTheSummaryStayed();
    }

    /**
     * Ending the request inside the job's transaction would roll the stored summary and the usage row back on a Valkey
     * failure, and Messenger would pay for the AI call again with no usage row for the first.
     */
    #[Test]
    public function aRequestStateValkeyCannotReleaseKeepsTheStoredSummaryAndItsUsageRowAndIsNotRetried(): void
    {
        $watchedRequests = $this->watchSummaryRequests();
        $watchedRequests->failEveryEndWith(new \RedisException('read error on connection'));
        $this->requestAsOmar();

        self::runTheWorkerUntilIdle('async_ai');

        self::assertSame(
            [['human', false], ['ai', true]],
            array_map(static fn (array $row): array => [$row['source'], $row['is_current']], $this->summaryRowsOfTheCheckoutTicket()),
        );
        self::assertSame(['answered'], $this->usageStatuses());
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertSame(1, $this->processedMessageCount());
        self::assertCount(1, $this->asyncAi()->getSent(), 'The job was retried.');
        $state = $this->requestState();
        self::assertNotNull($state);
        self::assertTrue($state->isQueued(), 'The request ended although Valkey refused it.');
        self::assertSame([['A summary request could not be ended; it stays queued until it expires.', ['exception_class' => \RedisException::class]]], $this->warnings());
    }

    #[Test]
    public function aFailureValkeyCannotRecordKeepsTheUsageRowAndIsNotRetried(): void
    {
        $watchedRequests = $this->watchSummaryRequests();
        $watchedRequests->failEveryEndWith(new \RedisException('read error on connection'));
        $this->requestAsOmar();
        $this->arrange('provider fails');

        self::runTheWorkerUntilIdle('async_ai');

        self::assertSame(['failed'], $this->usageStatuses());
        self::assertCount(1, $this->fakeProvider()->calls());
        self::assertSame(1, $this->processedMessageCount());
        self::assertCount(1, $this->asyncAi()->getSent(), 'The job was retried.');
        self::assertSame([self::HUMAN_SUMMARY_ROW], $this->summaryRowsOfTheCheckoutTicket());
        $state = $this->requestState();
        self::assertNotNull($state);
        self::assertTrue($state->isQueued(), 'The request failed although Valkey refused it.');
        self::assertSame([
            ['An AI summary failed.', ['summary_outcome' => 'failed']],
            ['A summary request could not be ended; it stays queued until it expires.', ['exception_class' => \RedisException::class]],
        ], $this->warnings());
    }

    /**
     * The in-memory transport hands out a retry only once the clock is past the time it was sent at, which the frozen
     * clock never is. A millisecond passes before each run, as the worker's wait would, and the test retry delay is 0,
     * so each run gets one retry; the request state stores whole seconds, so its time stays 10:00:00.
     */
    private function runTheWorkerThroughRetries(int $retryCount): void
    {
        for ($retry = 1; $retry <= $retryCount; ++$retry) {
            Clock::get()->sleep(0.001);
            self::runTheWorkerUntilIdle('async_ai');
        }
    }

    private function watchSummaryRequests(): WatchedSummaryRequests
    {
        $realRequests = new ValkeySummaryRequests(self::getContainer()->get('state_valkey.connection'), new MockClock(self::NOW));
        $watchedRequests = new WatchedSummaryRequests($realRequests, $this->whatAnotherConnectionSees(...));
        // Before anything builds QueuedSummaries or the handlers, which take the port once.
        self::getContainer()->set(ValkeySummaryRequests::class, $watchedRequests);

        return $watchedRequests;
    }

    /**
     * Through the migrator's own connection, under the organization the handler runs for, so only committed rows.
     *
     * @return array{current_summary_source: mixed, usage_statuses: list<mixed>}
     */
    private function whatAnotherConnectionSees(): array
    {
        $ticketId = $this->checkoutTicketId()->toString();

        return $this->migrator()->transactional(static fn (Connection $migrator): array => [
            'current_summary_source' => $migrator->fetchOne('SELECT source FROM tkt_summary WHERE ticket_id = ? AND is_current', [$ticketId]),
            'usage_statuses' => $migrator->fetchFirstColumn('SELECT status FROM ast_usage WHERE organization_id = ? ORDER BY at, id', [self::ACME_FOODS]),
        ]);
    }

    private function arrange(string $failure): void
    {
        $arrangement = match ($failure) {
            'provider fails' => fn () => $this->fakeProvider()->failNextCallWith(AiUnavailable::failed('huggingface', \RuntimeException::class)),
            'limit reached' => $this->useUpOmarsLimit(...),
            'unreadable key' => fn () => $this->storeAiSettings('{"on": true, "where": "cloud", "provider": "anthropic", "secret_enc": "not base64!", "key_id": "change-me-test"}'),
            'AI turned off' => fn () => $this->storeAiSettings('{"on": false}'),
            'Omar left' => fn () => $this->asMigratorUnderAcmeFoods('DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?', [self::ACME_FOODS, self::OMAR]),
            default => throw new \LogicException('No failure is called '.$failure.'.'),
        };
        $arrangement();
    }

    private function assertTheRequestFailedAndTheSummaryStayed(string $expectedSentence = self::FAILED_SENTENCE, int $expectedDeliveries = 1): void
    {
        $failed = $this->requestState();
        self::assertNotNull($failed, 'The request state was released, so the page shows nothing.');
        self::assertSame(
            [SummaryRequestView::FAILED, self::OMAR, '2027-03-15 10:00:00', $expectedSentence],
            [$failed->status, $failed->requesterId->toString(), $failed->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $failed->sentence],
        );
        self::assertSame([self::HUMAN_SUMMARY_ROW], $this->summaryRowsOfTheCheckoutTicket());
        self::assertCount($expectedDeliveries, $this->asyncAi()->getSent(), 'The job was delivered another number of times.');
    }

    private function givenGatewayAnswering(AiResult $answer): void
    {
        // Before anything builds the handler, which takes the gateway once.
        self::getContainer()->set(GuardedAiGateway::class, new readonly class($answer) implements AiGateway {
            public function __construct(private AiResult $answer)
            {
            }

            public function run(AiTask $task, ActorContext $viewer): AiResult
            {
                return $this->answer;
            }
        });
    }

    private function requestAsOmar(): void
    {
        $summaries = self::getContainer()->get('test.assist_summaries');
        \assert($summaries instanceof Summaries);
        $ticketId = $this->checkoutTicketId();
        $this->transactions()->committedAs(self::ACME_FOODS, static function () use ($summaries, $ticketId): void {
            $summaries->request($ticketId, new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
        });
    }

    private function storeAiSettings(string $aiJson): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb) WHERE organization_id = ?",
            [$aiJson, self::ACME_FOODS],
        ));
    }

    private function useUpOmarsLimit(): void
    {
        $limit = self::getContainer()->get('test.assist_ai_call_limit');
        \assert($limit instanceof AiCallLimit);
        $omar = new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none());
        for ($call = 1; $call <= 30; ++$call) {
            $limit->consume($omar);
        }
    }

    private function requestState(): ?SummaryRequestView
    {
        $requests = self::getContainer()->get('test.assist_summary_requests');
        \assert($requests instanceof SummaryRequests);

        return $requests->latestOf(new OrganizationId(self::ACME_FOODS), $this->checkoutTicketId());
    }

    /**
     * @return list<array{text: string, source: string, author_id: ?string, model: ?string, prompt_version: ?string, is_current: bool, created_at: string}>
     */
    private function summaryRowsOfTheCheckoutTicket(): array
    {
        return $this->summaryRows()->of(self::ACME_FOODS, $this->checkoutTicketId()->toString());
    }

    /**
     * @return list<string> oldest first
     */
    private function usageStatuses(): array
    {
        /** @var list<string> $statuses */
        $statuses = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT status FROM ast_usage WHERE organization_id = ? ORDER BY at, id',
            [self::ACME_FOODS],
        ));

        return $statuses;
    }

    /**
     * CapturedLogRecords keeps a record before RedactionProcessor runs, so each goes through it here: a key a real log
     * drops is missing from what a test asserts too.
     *
     * @return list<array{string, array<mixed>}> message and context of each warning and worse
     */
    private function warnings(): array
    {
        $redaction = new RedactionProcessor();

        return array_values(array_map(
            static function (LogRecord $record) use ($redaction): array {
                $redacted = $redaction($record);

                return [$redacted->message, $redacted->context];
            },
            array_filter($this->capturedLogRecords()->all(), static fn (LogRecord $record): bool => Level::Warning->includes($record->level)),
        ));
    }

    private function processedMessageCount(): int
    {
        $count = $this->migrator()->fetchOne('SELECT count(*) FROM processed_message WHERE handler = ?', [GenerateSummaryHandler::HANDLER_NAME]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param list<string> $parameters
     */
    private function asMigratorUnderAcmeFoods(string $statement, array $parameters): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement($statement, $parameters));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function forgetRequestStateAndCountedCalls(): void
    {
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        $keys = $stateValkey->keys('summary_request:'.self::ACME_FOODS.':*');
        if (\is_array($keys) && [] !== $keys) {
            $stateValkey->del($keys);
        }
        self::getContainer()->get(ValkeyLimiterStorage::class)->delete(self::USAGE_LIMITER_PREFIX.self::ACME_FOODS.':'.self::OMAR);
    }

    private function removeRows(): void
    {
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [GenerateSummaryHandler::HANDLER_NAME]);
        // Where a job Messenger gave up on lands.
        $this->connection()->executeStatement('DELETE FROM messenger_failed');
        // ast_usage is append-only for the app; the migrator owns it, and row-level security applies to it too.
        $this->asMigratorUnderAcmeFoods('DELETE FROM ast_usage WHERE organization_id = ?', [self::ACME_FOODS]);
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function checkoutTicketId(): TicketId
    {
        return $this->checkoutTicketId ?? throw new \LogicException('The ticket is made before each test.');
    }

    private function asyncAi(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ai');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function fakeProvider(): FakeProvider
    {
        return self::getContainer()->get(FakeProvider::class);
    }

    private function capturedLogRecords(): CapturedLogRecords
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);

        return $capturedLogRecords;
    }

    private function summaryRows(): SummaryRows
    {
        return new SummaryRows($this->migrator(), $this->tenantContext());
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
