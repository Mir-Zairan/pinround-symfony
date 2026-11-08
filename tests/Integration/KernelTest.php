<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Tests\Fixtures\Security\HackathonFlagEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The boot refusal of the hackathon flag (ADR-0012, Hackathon exception). Prod boots with debug off, as it runs, into a
 * build directory of this class's own, as DefaultConnectionMiddlewareTest explains; the flag is an env
 * parameter that each new container reads at runtime (prod in Kernel::boot()), so one prod build serves every case.
 */
final class KernelTest extends TestCase
{
    private static ?string $prodBuildDir = null;

    private ?HackathonFlagEnvironment $flagBefore = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$prodBuildDir) {
            new Filesystem()->remove(self::$prodBuildDir);
            self::$prodBuildDir = null;
        }
    }

    protected function tearDown(): void
    {
        $this->flagBefore?->restore();
        $this->flagBefore = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function flagValuesThatAreOn(): iterable
    {
        yield '1' => ['1'];
        yield 'true' => ['true'];
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function flagValuesThatAreOff(): iterable
    {
        yield 'unset' => [null];
        yield 'empty, as .env has it' => [''];
        yield '0' => ['0'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environmentsAllowingTheFlag(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    #[Test]
    #[DataProvider('flagValuesThatAreOn')]
    public function prodRefusesToBootWithTheFlagOnAndStaysRefusingOnTheNextTry(string $flagValue): void
    {
        $this->flagBefore = HackathonFlagEnvironment::set($flagValue);
        $kernel = new Kernel('prod', false);

        try {
            self::assertBootRefused(static fn () => $kernel->reboot(self::prodBuildDir()));
            self::assertBootRefused(static fn () => $kernel->boot());
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    #[DataProvider('flagValuesThatAreOff')]
    public function prodBootsWithTheFlagOff(?string $flagValue): void
    {
        $this->flagBefore = HackathonFlagEnvironment::set($flagValue);
        $kernel = new Kernel('prod', false);

        try {
            $kernel->reboot(self::prodBuildDir());

            self::assertFalse($kernel->getContainer()->getParameter('app.hackathon_skip_admin_mfa'));
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    #[DataProvider('environmentsAllowingTheFlag')]
    public function devAndTestBootWithTheFlagOn(string $environment): void
    {
        $this->flagBefore = HackathonFlagEnvironment::set('1');
        $kernel = new Kernel($environment, true);

        try {
            $kernel->boot();

            self::assertTrue($kernel->getContainer()->getParameter('app.hackathon_skip_admin_mfa'));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param \Closure(): void $boot
     */
    private static function assertBootRefused(\Closure $boot): void
    {
        $refusal = null;
        try {
            $boot();
        } catch (\RuntimeException $exception) {
            $refusal = $exception;
        }

        self::assertNotNull($refusal, 'The prod kernel booted with APP_HACKATHON_SKIP_ADMIN_MFA on.');
        self::assertSame(
            'APP_HACKATHON_SKIP_ADMIN_MFA is on, but the prod environment refuses it: it lets admins skip two-step login and is allowed only in dev and test (ADR-0012, Hackathon exception). Unset it and start again.',
            $refusal->getMessage(),
        );
    }

    private static function prodBuildDir(): string
    {
        return self::$prodBuildDir ??= sys_get_temp_dir().'/pinround-prod-kernel-'.bin2hex(random_bytes(6));
    }
}
