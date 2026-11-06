<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\LazyObjectState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LazyObjectStateTest extends TestCase
{
    #[Test]
    public function aLazyServiceIsNotBuiltUntilItIsUsedAndAskingDoesNotBuildIt(): void
    {
        $serviceClass = new class {
            public int $value = 1;
        }::class;
        /** @var \ArrayObject<int, true> $builds */
        $builds = new \ArrayObject();
        $service = new \ReflectionClass($serviceClass)->newLazyProxy(static function () use ($builds, $serviceClass): object {
            $builds->append(true);

            return new $serviceClass();
        });

        self::assertFalse(LazyObjectState::isBuilt($service));
        self::assertCount(0, $builds);

        self::assertSame(1, $service->value);

        self::assertTrue(LazyObjectState::isBuilt($service));
        self::assertCount(1, $builds);
    }

    #[Test]
    public function aServiceThatIsNotLazyIsBuilt(): void
    {
        self::assertTrue(LazyObjectState::isBuilt(new \ArrayObject()));
    }
}
