<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Infrastructure\Messenger\RejectMessageForAnotherTenantMiddleware;
use App\SharedKernel\Infrastructure\Messenger\RelayOutboxMessageMiddleware;
use App\SharedKernel\Infrastructure\Messenger\TenantStampMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Every Messenger bus must fill TenantContext before doctrine_transaction begins, and refuse a message for another
 * organization before dispatch_after_current_bus can hold it back (ADR-0005). The bus definitions
 * are read from debug:container, which rebuilds the container when its cached dump is stale.
 */
final class MessengerBusMiddlewareTest extends KernelTestCase
{
    #[Test]
    public function everyBusRunsBothTenantMiddlewareInTheirPlace(): void
    {
        $middlewareByBus = $this->middlewareByBus();

        // A new bus is added here on purpose, so its author sees this rule.
        self::assertEqualsCanonicalizing(['command.bus', 'event.bus'], array_keys($middlewareByBus));
        foreach ($middlewareByBus as $busId => $middlewareIds) {
            self::assertMiddlewareDirectlyBefore($busId, $middlewareIds, TenantStampMiddleware::class, $busId.'.middleware.doctrine_transaction');
            self::assertMiddlewareDirectlyBefore($busId, $middlewareIds, RejectMessageForAnotherTenantMiddleware::class, 'messenger.middleware.dispatch_after_current_bus');
            // The relay must see the outbox's ReceivedStamp, which failed_message_processing_middleware puts back on
            // a message retried from failed, and must stop the message before any handler or tenant switch.
            self::assertMiddlewareDirectlyBefore($busId, $middlewareIds, 'messenger.middleware.failed_message_processing_middleware', RelayOutboxMessageMiddleware::class);
            self::assertMiddlewareDirectlyBefore($busId, $middlewareIds, RelayOutboxMessageMiddleware::class, TenantStampMiddleware::class);
        }
    }

    #[Test]
    public function eachBusRunsTheFrameworkMiddlewareAroundTheTenantMiddlewareAndTheRelay(): void
    {
        $middlewareByBus = $this->middlewareByBus();

        foreach (['command.bus', 'event.bus'] as $busId) {
            self::assertSame([
                $busId.'.middleware.traceable',
                'messenger.middleware.add_default_stamps_middleware',
                $busId.'.middleware.add_bus_name_stamp_middleware',
                'messenger.middleware.reject_redelivered_message_middleware',
                RejectMessageForAnotherTenantMiddleware::class,
                'messenger.middleware.dispatch_after_current_bus',
                'messenger.middleware.decode_failed_message_middleware',
                'messenger.middleware.failed_message_processing_middleware',
                RelayOutboxMessageMiddleware::class,
                TenantStampMiddleware::class,
                $busId.'.middleware.doctrine_transaction',
                $busId.'.middleware.send_message',
                $busId.'.middleware.handle_message',
            ], $middlewareByBus[$busId] ?? null, $busId);
        }
    }

    #[Test]
    public function theCommandBusIsTheDefaultBus(): void
    {
        $container = self::getContainer();

        self::assertSame($container->get('command.bus'), $container->get(MessageBusInterface::class));
    }

    #[Test]
    public function theEventBusAcceptsAMessageNobodyHandlesAndTheCommandBusRefusesIt(): void
    {
        $container = self::getContainer();

        self::assertSame([], $container->get('event.bus')->dispatch(new \stdClass())->all(HandledStamp::class));

        $this->expectException(NoHandlerForMessageException::class);
        $container->get('command.bus')->dispatch(new \stdClass());
    }

    /**
     * @param list<string> $middlewareIds
     */
    private static function assertMiddlewareDirectlyBefore(string $busId, array $middlewareIds, string $middlewareId, string $nextMiddlewareId): void
    {
        $position = array_search($middlewareId, $middlewareIds, true);
        self::assertIsInt($position, \sprintf('The bus "%s" has no %s.', $busId, $middlewareId));
        self::assertSame(1, array_count_values($middlewareIds)[$middlewareId]);
        self::assertSame(
            $nextMiddlewareId,
            $middlewareIds[$position + 1] ?? null,
            \sprintf('On the bus "%s", %s must come directly after %s.', $busId, $nextMiddlewareId, $middlewareId),
        );
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
