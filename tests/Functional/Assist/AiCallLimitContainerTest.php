<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist;

use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiRateLimited;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * AiCallLimit as the test container wires it: the limiter ai_calls_per_user on the state Valkey of the tests. The
 * limiter reads the real time, not the clock service, so each test uses an organization and users no other run has
 * counted, and all its calls fall in the window its first call opens.
 */
final class AiCallLimitContainerTest extends KernelTestCase
{
    private const int LIMIT = 30;

    #[Test]
    public function thirtyCallsPassAndTheThirtyFirstIsRefusedWithThePerPersonMessage(): void
    {
        $omar = self::freshCaller();

        for ($call = 1; $call <= self::LIMIT; ++$call) {
            $this->limit()->consume($omar);
        }
        self::assertSame(self::LIMIT, $this->hitsOf($omar));

        try {
            $this->limit()->consume($omar);
            self::fail('The 31st call was accepted.');
        } catch (AiRateLimited $refusal) {
            self::assertSame('Too many AI requests. Try again in a minute.', $refusal->getMessage());
            self::assertNull($refusal->provider);
        }
        self::assertSame(self::LIMIT, $this->hitsOf($omar));
    }

    #[Test]
    public function aPersonAtTheLimitInOneOrganizationCanStillCallInAnother(): void
    {
        $omarAtAcmeFoods = self::freshCaller();
        for ($call = 1; $call <= self::LIMIT; ++$call) {
            $this->limit()->consume($omarAtAcmeFoods);
        }
        $omarElsewhere = new ActorContext($omarAtAcmeFoods->userId(), new OrganizationId(new UuidV7()->toRfc4122()), Roles::none());

        $this->limit()->consume($omarElsewhere);

        self::assertSame(1, $this->hitsOf($omarElsewhere));
        self::assertSame(self::LIMIT, $this->hitsOf($omarAtAcmeFoods));
    }

    #[Test]
    public function anotherPersonInTheSameOrganizationIsNotCountedWithTheOneAtTheLimit(): void
    {
        $omar = self::freshCaller();
        for ($call = 1; $call <= self::LIMIT; ++$call) {
            $this->limit()->consume($omar);
        }
        $mia = new ActorContext(new UserId(new UuidV7()->toRfc4122()), $omar->organizationId(), Roles::none());

        $this->limit()->consume($mia);

        self::assertSame(1, $this->hitsOf($mia));
    }

    /** Fails closed: a counter that cannot be read or written never lets the call run unlimited. */
    #[Test]
    public function theCallIsRefusedWhenTheCounterStorageFails(): void
    {
        self::getContainer()->set(ValkeyLimiterStorage::class, new class implements StorageInterface {
            public function save(LimiterStateInterface $limiterState): void
            {
                throw new \RedisException('Connection refused');
            }

            public function fetch(string $limiterStateId): ?LimiterStateInterface
            {
                throw new \RedisException('Connection refused');
            }

            public function delete(string $limiterStateId): void
            {
                throw new \RedisException('Connection refused');
            }
        });

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessageIsOrContains('Connection refused');
        $this->limit()->consume(self::freshCaller());
    }

    private function limit(): AiCallLimit
    {
        $limit = self::getContainer()->get('test.assist_ai_call_limit');
        self::assertInstanceOf(AiCallLimit::class, $limit);

        return $limit;
    }

    private function hitsOf(ActorContext $caller): int
    {
        $window = self::getContainer()->get(ValkeyLimiterStorage::class)
            ->fetch('ai_calls_per_user-'.$caller->organizationId()->toString().':'.$caller->userId()->toString());
        self::assertInstanceOf(Window::class, $window);

        return $window->getHitCount();
    }

    private static function freshCaller(): ActorContext
    {
        return new ActorContext(new UserId(new UuidV7()->toRfc4122()), new OrganizationId(new UuidV7()->toRfc4122()), Roles::none());
    }
}
