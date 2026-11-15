<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\SharedKernel\Infrastructure\Http\BackgroundReadOnlySessionHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The session handler dev and prod use (LoginConfigTest pins its definition), on the tests' state Valkey, outside any
 * request, where it writes as RedisSessionHandler does (BackgroundReadOnlySessionHandlerTest covers background requests).
 */
final class SessionHandlerTest extends KernelTestCase
{
    #[Test]
    public function aSessionRoundTripsThroughTheStateValkeyUnderTheSessionPrefix(): void
    {
        $handler = self::getContainer()->get('test.session_handler');
        self::assertInstanceOf(BackgroundReadOnlySessionHandler::class, $handler);
        $connection = self::getContainer()->get('state_valkey.connection');
        $sessionId = bin2hex(random_bytes(16));

        self::assertTrue($handler->open('', '__Host-pinround'));
        self::assertTrue($handler->write($sessionId, 'the session data'));
        self::assertSame('the session data', $handler->read($sessionId));
        self::assertSame('the session data', $connection->get('session:'.$sessionId));

        self::assertTrue($handler->destroy($sessionId));
        self::assertSame('', $handler->read($sessionId));
        self::assertSame(0, $connection->exists('session:'.$sessionId));
    }

    #[Test]
    public function aSessionKeyLivesEightHoursFromItsLastWriteOrTouch(): void
    {
        $handler = self::getContainer()->get('test.session_handler');
        self::assertInstanceOf(BackgroundReadOnlySessionHandler::class, $handler);
        $connection = self::getContainer()->get('state_valkey.connection');
        $sessionId = bin2hex(random_bytes(16));
        $key = 'session:'.$sessionId;

        try {
            self::assertTrue($handler->write($sessionId, 'the session data'));
            self::assertSame(28800, $connection->ttl($key));

            $connection->expire($key, 60);
            self::assertTrue($handler->updateTimestamp($sessionId, 'the session data'));
            self::assertSame(28800, $connection->ttl($key));
        } finally {
            $handler->destroy($sessionId);
        }
    }
}
