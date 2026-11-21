<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The boot check of the demo switch (ADR-0049). It only constructs kernels, so no container is built.
 */
final class KernelTest extends TestCase
{
    private const string DEMO_SWITCH = 'PINROUND_DEMO_SKIP_2FA';

    private mixed $envBefore;
    private mixed $serverBefore;

    protected function setUp(): void
    {
        $this->envBefore = $_ENV[self::DEMO_SWITCH] ?? null;
        $this->serverBefore = $_SERVER[self::DEMO_SWITCH] ?? null;
    }

    protected function tearDown(): void
    {
        self::restore($_ENV, $this->envBefore);
        self::restore($_SERVER, $this->serverBefore);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function onValues(): iterable
    {
        yield '1' => ['1'];
        yield 'true' => ['true'];
        yield 'on' => ['on'];
        yield 'yes in capitals' => ['YES'];
        yield 'a number other than 0' => ['2'];
    }

    /**
     * @return iterable<string, array{string, string}> the environment, the switch's value
     */
    public static function refusedBoots(): iterable
    {
        foreach (['prod', 'test', 'staging'] as $environment) {
            foreach (self::onValues() as $name => [$value]) {
                yield $environment.', '.$name => [$environment, $value];
            }
        }
    }

    #[Test]
    #[DataProvider('refusedBoots')]
    public function theSwitchOnOutsideDevStopsTheBootWithAPlainMessage(string $environment, string $value): void
    {
        $_ENV[self::DEMO_SWITCH] = $value;

        $this->expectExceptionObject(new \RuntimeException('PINROUND_DEMO_SKIP_2FA is on, but APP_ENV is not dev. The switch skips two-step login, so it works only on a local demo in dev. Turn it off (PINROUND_DEMO_SKIP_2FA=0) or run with APP_ENV=dev.'));

        new Kernel($environment, false);
    }

    /**
     * A web server or container sets it in $_SERVER only; Dotenv sets both.
     */
    #[Test]
    public function theSwitchOnInTheServerVariablesOnlyStopsTheBootToo(): void
    {
        unset($_ENV[self::DEMO_SWITCH]);
        $_SERVER[self::DEMO_SWITCH] = 'true';

        $this->expectExceptionMessageIsOrContains(Kernel::DEMO_SWITCH_OUTSIDE_DEV);

        new Kernel('prod', false);
    }

    #[Test]
    #[DataProvider('onValues')]
    public function theSwitchOnInDevBoots(string $value): void
    {
        $_ENV[self::DEMO_SWITCH] = $value;

        self::assertSame('dev', new Kernel('dev', true)->getEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function offValues(): iterable
    {
        yield 'empty, as .env has it' => [''];
        yield '0' => ['0'];
        yield 'false' => ['false'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
    }

    #[Test]
    #[DataProvider('offValues')]
    public function theSwitchOffBootsInEveryEnvironment(string $value): void
    {
        $_ENV[self::DEMO_SWITCH] = $value;

        self::assertSame('prod', new Kernel('prod', false)->getEnvironment());
        self::assertSame('test', new Kernel('test', true)->getEnvironment());
        self::assertSame('dev', new Kernel('dev', true)->getEnvironment());
    }

    /**
     * @param array<mixed> $variables
     */
    private static function restore(array &$variables, mixed $before): void
    {
        if (null === $before) {
            unset($variables[self::DEMO_SWITCH]);
        } else {
            $variables[self::DEMO_SWITCH] = $before;
        }
    }
}
