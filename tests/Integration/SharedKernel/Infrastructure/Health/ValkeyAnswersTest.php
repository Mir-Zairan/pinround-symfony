<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\ValkeyAnswers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

/**
 * Against the compose stack's two Valkeys (CI: its two Valkey services), through the services /health/ready runs and
 * with the DSN shapes MESSENGER_TRANSPORT_DSN may take in prod (DsnWithoutStreamEnvVarProcessorTest).
 */
final class ValkeyAnswersTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configuredValkeys(): iterable
    {
        yield 'the cache Valkey' => ['health.cache_valkey_answers', 'cache_valkey'];
        yield 'the state Valkey' => ['health.state_valkey_answers', 'state_valkey'];
    }

    #[Test]
    #[DataProvider('configuredValkeys')]
    public function eachConfiguredValkeyAnswersUnderItsOwnName(string $serviceId, string $expectedName): void
    {
        $valkeyAnswers = self::getContainer()->get($serviceId);
        self::assertInstanceOf(ValkeyAnswers::class, $valkeyAnswers);

        $valkeyAnswers->verify();

        self::assertSame($expectedName, $valkeyAnswers->name());
    }

    /**
     * @return iterable<string, array{string}> sprintf patterns; %1$s is the state Valkey's host:port
     */
    public static function messengerDsnShapes(): iterable
    {
        yield 'host and port' => ['redis://%1$s'];
        yield 'trailing slash' => ['redis://%1$s/'];
        yield 'db index in the query' => ['redis://%1$s?dbindex=2'];
        yield 'valkey scheme, lazy' => ['valkey://%1$s?lazy=1'];
        yield 'Messenger options RedisAdapter does not know' => ['redis://%1$s?auto_setup=false&delete_after_ack=true&serializer=1'];
        yield 'a long timeout of its own' => ['redis://%1$s?timeout=30&read_timeout=30&retry_interval=100'];
        yield 'several hosts' => ['redis://%1$s,redis://%1$s'];
    }

    #[Test]
    #[DataProvider('messengerDsnShapes')]
    public function theStateValkeyAnswersOnEveryMessengerDsnShape(string $dsnPattern): void
    {
        new ValkeyAnswers('state_valkey', \sprintf($dsnPattern, self::stateValkeyAddress()))->verify();

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dsnsOfAValkeyThatIsDown(): iterable
    {
        yield 'nothing listens' => ['redis://127.0.0.1:1'];
        // RedisAdapter would return a proxy that never connects, so a lazy DSN alone must not pass.
        yield 'nothing listens, lazy' => ['valkey://127.0.0.1:1?lazy=1'];
        yield 'not a Valkey DSN' => ['in-memory://'];
    }

    #[Test]
    #[DataProvider('dsnsOfAValkeyThatIsDown')]
    public function itThrowsWhenTheValkeyDoesNotAnswer(string $dsn): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValkeyAnswers('probe', $dsn)->verify();
    }

    #[Test]
    public function aDsnOfSeveralHostsFailsWhenOneOfThemIsDown(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValkeyAnswers('probe', 'redis://'.self::stateValkeyAddress().',redis://127.0.0.1:1')->verify();
    }

    #[Test]
    public function itGivesUpOnAHostThatNeverAnswersWithinTheTimeout(): void
    {
        // TEST-NET-1 (RFC 5737): routed nowhere, so a connect waits for its timeout (30 s by default).
        $unanswered = new ValkeyAnswers('probe', 'redis://192.0.2.1:6379?timeout=30');
        $startedAt = microtime(true);

        try {
            $unanswered->verify();
            self::fail('A host that never answers passed.');
        } catch (InvalidArgumentException) {
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        if ($elapsedSeconds < 0.5) {
            self::markTestSkipped('This network refused 192.0.2.1 at once, so the connect timeout was not reached.');
        }
        self::assertLessThan(3.0, $elapsedSeconds);
    }

    private static function stateValkeyAddress(): string
    {
        $dsn = $_SERVER['TEST_STATE_VALKEY_DSN'] ?? null;
        self::assertIsString($dsn, 'TEST_STATE_VALKEY_DSN is not set (.env.test).');
        $host = parse_url($dsn, \PHP_URL_HOST);
        self::assertIsString($host);

        return $host.':'.(parse_url($dsn, \PHP_URL_PORT) ?? 6379);
    }
}
