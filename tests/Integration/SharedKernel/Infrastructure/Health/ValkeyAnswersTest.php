<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\ValkeyAnswers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

/**
 * Against the compose stack's two Valkeys, through the services /health/ready runs.
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

    #[Test]
    public function itThrowsWhenNothingListens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValkeyAnswers('probe', 'redis://127.0.0.1:1')->verify();
    }

    #[Test]
    public function itGivesUpOnAHostThatNeverAnswersWithinTheTimeout(): void
    {
        // TEST-NET-1 (RFC 5737): routed nowhere, so a connect waits for its timeout (30 s by default).
        $unanswered = new ValkeyAnswers('probe', 'redis://192.0.2.1:6379');
        $startedAt = microtime(true);

        try {
            $unanswered->verify();
            self::fail('A host that never answers passed.');
        } catch (InvalidArgumentException) {
        }

        self::assertLessThan(3.0, microtime(true) - $startedAt);
    }

    #[Test]
    public function aDsnThatIsNotAValkeyFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValkeyAnswers('probe', 'in-memory://')->verify();
    }
}
