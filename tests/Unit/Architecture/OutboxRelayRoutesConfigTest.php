<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Conversation\Api\Event\ConversationEvent;
use App\Identity\Api\Event\IdentityEvent;
use App\Media\Api\Event\MediaEvent;
use App\Media\Application\ProcessUpload;
use App\Notification\Api\Event\NotificationEvent;
use App\Organization\Api\Event\OrganizationEvent;
use App\Ticketing\Api\Event\TicketEvent;
use App\Workflow\Api\Event\WorkflowEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        $routing = self::routing();

        foreach (self::DOMAIN_EVENTS as $event) {
            self::assertSame('outbox', $routing[$event] ?? null, $event);
        }
    }

    #[Test]
    public function processUploadIsRoutedToTheOutboxNotStraightToAsyncMedia(): void
    {
        self::assertSame('outbox', self::routing()[ProcessUpload::class] ?? null);
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
     * @return array<mixed>
     */
    private static function routing(): array
    {
        $framework = self::messengerConfig()['framework'] ?? null;
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
