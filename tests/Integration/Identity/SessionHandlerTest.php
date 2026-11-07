<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * The session handler dev and prod use (LoginConfigTest pins its definition), on the tests' state Valkey.
 */
final class SessionHandlerTest extends KernelTestCase
{
    #[Test]
    public function aSessionRoundTripsThroughTheStateValkeyUnderTheSessionPrefix(): void
    {
        $handler = self::getContainer()->get('test.session_handler');
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
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
}
