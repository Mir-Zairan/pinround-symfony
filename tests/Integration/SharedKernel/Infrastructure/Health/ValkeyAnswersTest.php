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
    // A test-only ACL user on the state Valkey, removed after each test that adds it.
    private const string PROBE_USER = 'zz_health_probe';
    private const string PROBE_PASSWORD = 'pa?ss,word';

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
    public function theQueryOptionsTheProbeKeepsReachTheServer(): void
    {
        // A Valkey has 16 databases unless configured otherwise, so SELECT 99 fails.
        $this->expectException(InvalidArgumentException::class);

        new ValkeyAnswers('probe', 'redis://'.self::stateValkeyAddress().'?lazy=1&dbindex=99')->verify();
    }

    /**
     * @return iterable<string, array{string}> sprintf patterns; %1$s is the password, %2$s the state Valkey's host:port
     */
    public static function dsnsWithAPasswordHoldingAQuestionMarkAndAComma(): iterable
    {
        yield 'alone' => ['redis://'.self::PROBE_USER.':%1$s@%2$s'];
        yield 'with a query' => ['redis://'.self::PROBE_USER.':%1$s@%2$s?lazy=1&dbindex=0'];
        yield 'on each of several hosts' => ['redis://'.self::PROBE_USER.':%1$s@%2$s,valkey://'.self::PROBE_USER.':%1$s@%2$s'];
    }

    #[Test]
    #[DataProvider('dsnsWithAPasswordHoldingAQuestionMarkAndAComma')]
    public function aPasswordHoldingAQuestionMarkAndACommaStillAuthenticates(string $dsnPattern): void
    {
        $this->withProbeUser(static function () use ($dsnPattern): void {
            new ValkeyAnswers('probe', \sprintf($dsnPattern, self::PROBE_PASSWORD, self::stateValkeyAddress()))->verify();
        });

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theWrongPasswordFails(): void
    {
        $this->withProbeUser(function (): void {
            $this->expectException(InvalidArgumentException::class);

            new ValkeyAnswers('probe', \sprintf('redis://%s:not-the-password@%s', self::PROBE_USER, self::stateValkeyAddress()))->verify();
        });
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

    /**
     * The user may only SELECT, which is all the probe sends after AUTH.
     */
    private function withProbeUser(\Closure $test): void
    {
        self::assertSame("+OK\r\n", self::sendToStateValkey('ACL', 'SETUSER', self::PROBE_USER, 'reset', 'on', '>'.self::PROBE_PASSWORD, '+select'));

        try {
            $test();
        } finally {
            self::sendToStateValkey('ACL', 'DELUSER', self::PROBE_USER);
        }
    }

    /**
     * One command in the Redis protocol, without a client library; returns the first line of the reply.
     */
    private static function sendToStateValkey(string ...$arguments): string
    {
        $socket = stream_socket_client('tcp://'.self::stateValkeyAddress(), $errorCode, $errorMessage, 1.0);
        self::assertIsResource($socket, $errorMessage ?? '');
        $command = '*'.\count($arguments)."\r\n";
        foreach ($arguments as $argument) {
            $command .= '$'.\strlen($argument)."\r\n".$argument."\r\n";
        }
        fwrite($socket, $command);
        $reply = (string) fgets($socket);
        fclose($socket);

        return $reply;
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
