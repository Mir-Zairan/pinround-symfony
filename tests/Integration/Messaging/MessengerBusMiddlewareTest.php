<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Infrastructure\Messenger\TenantStampMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every Messenger bus must fill TenantContext before doctrine_transaction begins (ADR-0005). The bus definitions
 * are read from debug:container, which rebuilds the container when its cached dump is stale.
 */
final class MessengerBusMiddlewareTest extends KernelTestCase
{
    #[Test]
    public function everyBusRunsTheTenantStampMiddlewareDirectlyBeforeDoctrineTransaction(): void
    {
        $middlewareByBus = $this->middlewareByBus();

        // A new bus is added here on purpose, so its author sees this rule.
        self::assertEqualsCanonicalizing(['messenger.bus.default'], array_keys($middlewareByBus));
        foreach ($middlewareByBus as $busId => $middlewareIds) {
            $position = array_search(TenantStampMiddleware::class, $middlewareIds, true);
            self::assertIsInt($position, \sprintf('The bus "%s" has no %s.', $busId, TenantStampMiddleware::class));
            self::assertSame(
                $busId.'.middleware.doctrine_transaction',
                $middlewareIds[$position + 1] ?? null,
                \sprintf('On the bus "%s", doctrine_transaction must come directly after %s.', $busId, TenantStampMiddleware::class),
            );
            self::assertSame(1, array_count_values($middlewareIds)[TenantStampMiddleware::class]);
        }
    }

    #[Test]
    public function theDefaultBusRunsTheTenantStampMiddlewareAfterTheFrameworkMiddlewareAndBeforeSendingAndHandling(): void
    {
        self::assertSame([
            'messenger.bus.default.middleware.traceable',
            'messenger.middleware.add_default_stamps_middleware',
            'messenger.bus.default.middleware.add_bus_name_stamp_middleware',
            'messenger.middleware.reject_redelivered_message_middleware',
            'messenger.middleware.dispatch_after_current_bus',
            'messenger.middleware.decode_failed_message_middleware',
            'messenger.middleware.failed_message_processing_middleware',
            TenantStampMiddleware::class,
            'messenger.bus.default.middleware.doctrine_transaction',
            'messenger.bus.default.middleware.send_message',
            'messenger.bus.default.middleware.handle_message',
        ], $this->middlewareByBus()['messenger.bus.default'] ?? null);
    }

    /**
     * @return array<string, list<string>> the middleware service ids of each bus, in the order they run
     */
    private function middlewareByBus(): array
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('debug:container'));
        $tester->execute(['--tag' => 'messenger.bus', '--format' => 'json']);
        $tester->assertCommandIsSuccessful();

        /** @var array{definitions: array<string, array{arguments: array{0: list<array{type: string, id: string}>}}>} $description */
        $description = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(
            static fn (array $bus): array => array_column($bus['arguments'][0], 'id'),
            $description['definitions'],
        );
    }
}
