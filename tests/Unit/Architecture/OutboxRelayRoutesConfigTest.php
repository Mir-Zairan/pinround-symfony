<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Identity\Api\Event\IdentityEvent;
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
    #[Test]
    public function theRelaySendsExactlyTheOrganizationWorkflowTicketAndIdentityEventsOnToAsyncNotify(): void
    {
        $config = self::messengerConfig();
        self::assertIsArray($config['parameters'] ?? null);

        self::assertEqualsCanonicalizing(
            [OrganizationEvent::class => 'async_notify', WorkflowEvent::class => 'async_notify', TicketEvent::class => 'async_notify', IdentityEvent::class => 'async_notify'],
            $config['parameters']['outbox_relay.target_transports'] ?? null,
        );
    }

    #[Test]
    public function theOrganizationWorkflowTicketAndIdentityEventsAreRoutedToTheOutbox(): void
    {
        $framework = self::messengerConfig()['framework'] ?? null;
        self::assertIsArray($framework);
        self::assertIsArray($framework['messenger'] ?? null);
        $routing = $framework['messenger']['routing'] ?? null;
        self::assertIsArray($routing);

        self::assertSame('outbox', $routing[OrganizationEvent::class] ?? null);
        self::assertSame('outbox', $routing[WorkflowEvent::class] ?? null);
        self::assertSame('outbox', $routing[TicketEvent::class] ?? null);
        self::assertSame('outbox', $routing[IdentityEvent::class] ?? null);
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
