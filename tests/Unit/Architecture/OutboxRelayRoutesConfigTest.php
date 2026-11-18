<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Conversation\Api\Event\ConversationEvent;
use App\Identity\Api\Event\IdentityEvent;
use App\Identity\Application\EmailPasswordResetLink;
use App\Identity\Infrastructure\Scheduler\SendApiTokenExpiryWarnings;
use App\Media\Api\Event\MediaEvent;
use App\Media\Application\ProcessUpload;
use App\Notification\Api\Event\NotificationEvent;
use App\Notification\Application\Digest\SendDigest;
use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Mercure\RefreshTicketPages;
use App\Notification\Infrastructure\Scheduler\ReleaseDueNotifications;
use App\Organization\Api\Event\OrganizationEvent;
use App\Organization\Application\EmailInvitationLink;
use App\Review\Api\Event\ReviewEvent;
use App\Ticketing\Api\Event\TicketEvent;
use App\Workflow\Api\Event\WorkflowEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * The outbox routes of every environment but test, read from messenger.yaml: the test env replaces the whole
 * outbox_relay.target_transports map, so the tests that run the relay cannot see the production one.
 */
final class OutboxRelayRoutesConfigTest extends TestCase
{
    private const array DOMAIN_EVENTS = [
        OrganizationEvent::class,
        WorkflowEvent::class,
        TicketEvent::class,
        ConversationEvent::class,
        NotificationEvent::class,
        IdentityEvent::class,
        MediaEvent::class,
        ReviewEvent::class,
    ];

    #[Test]
    public function theRelaySendsTheDomainEventsOnToAsyncNotifyAndProcessUploadOnToAsyncMedia(): void
    {
        self::assertEqualsCanonicalizing(
            array_fill_keys(self::DOMAIN_EVENTS, 'async_notify') + [ProcessUpload::class => 'async_media'],
            self::relayTargets(self::messengerConfig()),
        );
    }

    #[Test]
    public function theDomainEventsOfEachModuleAreRoutedToTheOutbox(): void
    {
        $routing = self::routing(self::messengerConfig());

        foreach (self::DOMAIN_EVENTS as $event) {
            self::assertSame('outbox', $routing[$event] ?? null, $event);
        }
    }

    /**
     * Each of these is sent straight to its worker's transport, never through the outbox: the notify commands and mail
     * to async_notify (compose.yaml's worker), the AI jobs (summary and intake check) to async_ai (worker-heavy).
     */
    #[Test]
    public function exactlyTheNotifyCommandsMailAndTheAiJobsAreRoutedStraightToTheirTransports(): void
    {
        self::assertEqualsCanonicalizing(
            array_fill_keys([
                EmailPasswordResetLink::class,
                EmailInvitationLink::class,
                SendEmailMessage::class,
                RefreshTicketPages::class,
                RefreshBell::class,
                ReleaseDueNotifications::class,
                SendDigest::class,
                SendApiTokenExpiryWarnings::class,
            ], 'async_notify') + [GenerateSummary::class => 'async_ai', GenerateIntakeSuggestion::class => 'async_ai'],
            array_filter(self::routing(self::messengerConfig()), static fn (mixed $transport): bool => 'outbox' !== $transport),
        );
    }

    /** The test environment routes only its own fixtures, so every app message goes the same way in tests as in production. */
    #[Test]
    public function theTestEnvironmentRoutesOnlyTestFixtures(): void
    {
        $config = self::messengerConfig();
        $testConfig = $config['when@test'] ?? null;
        self::assertIsArray($testConfig);
        $testRouting = self::routing($testConfig);
        self::assertNotSame([], $testRouting);

        foreach (array_keys($testRouting) as $message) {
            self::assertStringStartsWith('App\Tests\Fixtures\\', (string) $message);
            self::assertArrayNotHasKey($message, self::routing($config));
        }
    }

    #[Test]
    public function processUploadIsRoutedToTheOutboxNotStraightToAsyncMedia(): void
    {
        self::assertSame('outbox', self::routing(self::messengerConfig())[ProcessUpload::class] ?? null);
    }

    #[Test]
    public function theTestEnvironmentRepeatsEveryRelayTargetOfTheOthers(): void
    {
        $config = self::messengerConfig();
        $testConfig = $config['when@test'] ?? null;
        self::assertIsArray($testConfig);
        $testTargets = self::relayTargets($testConfig);

        $productionTargets = self::relayTargets($config);
        self::assertNotSame([], $productionTargets);
        foreach ($productionTargets as $message => $transport) {
            self::assertSame($transport, $testTargets[$message] ?? null, $message);
        }
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function relayTargets(array $config): array
    {
        self::assertIsArray($config['parameters'] ?? null);
        $targets = $config['parameters']['outbox_relay.target_transports'] ?? null;
        self::assertIsArray($targets);

        return $targets;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function routing(array $config): array
    {
        $framework = $config['framework'] ?? null;
        self::assertIsArray($framework);
        self::assertIsArray($framework['messenger'] ?? null);
        $routing = $framework['messenger']['routing'] ?? null;
        self::assertIsArray($routing);

        return $routing;
    }

    /**
     * @return array<mixed>
     */
    private static function messengerConfig(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/messenger.yaml');
        self::assertIsArray($config);

        return $config;
    }
}
