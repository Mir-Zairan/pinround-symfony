<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The image's one HEALTHCHECK probes /health/live through Caddy, and the prod stage turns Caddy's admin API off
 * through the Caddyfile's `admin` option (operations.md, Health).
 */
final class ContainerHealthcheckConfigTest extends TestCase
{
    private const string CADDY_ADMIN_OPTION = 'admin {$CADDY_ADMIN:localhost:2019}';

    #[Test]
    public function theDockerfileAndCaddyfileProbeTheAppAndTurnTheAdminApiOffInProd(): void
    {
        self::assertSame([], self::problemsIn(self::read('Dockerfile'), self::read('docker/frankenphp/Caddyfile')));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function brokenConfigs(): iterable
    {
        $dockerfile = self::read('Dockerfile');
        $caddyfile = self::read('docker/frankenphp/Caddyfile');

        yield 'prod keeps the admin API' => [
            str_replace("ENV CADDY_ADMIN=off\n", '', $dockerfile),
            $caddyfile,
            ['the prod stage does not set ENV CADDY_ADMIN=off'],
        ];
        yield 'the healthcheck reads the admin metrics again' => [
            (string) preg_replace('#CMD curl .*$#m', 'CMD curl -fsS http://localhost:2019/metrics -o /dev/null || exit 1', $dockerfile),
            $caddyfile,
            ['the HEALTHCHECK does not probe /health/live', 'the HEALTHCHECK calls the admin API'],
        ];
        yield 'a second healthcheck' => [
            $dockerfile."HEALTHCHECK CMD true\n",
            $caddyfile,
            ['the Dockerfile has 2 HEALTHCHECK instructions, not 1'],
        ];
        yield 'the Caddyfile ignores CADDY_ADMIN' => [
            $dockerfile,
            str_replace(self::CADDY_ADMIN_OPTION, '', $caddyfile),
            ['the Caddyfile does not read CADDY_ADMIN for its admin option'],
        ];
    }

    /**
     * @param list<string> $expectedProblems
     */
    #[Test]
    #[DataProvider('brokenConfigs')]
    public function aBrokenConfigIsCaught(string $dockerfile, string $caddyfile, array $expectedProblems): void
    {
        self::assertSame($expectedProblems, self::problemsIn($dockerfile, $caddyfile));
    }

    /**
     * @return list<string>
     */
    private static function problemsIn(string $dockerfile, string $caddyfile): array
    {
        $problems = [];
        if (1 !== preg_match('#^FROM \S+ AS prod$(?<stage>.*?)(?=^FROM |\z)#ms', $dockerfile, $prod)
            || 1 !== preg_match('#^ENV CADDY_ADMIN=off$#m', $prod['stage'])) {
            $problems[] = 'the prod stage does not set ENV CADDY_ADMIN=off';
        }

        // An instruction with its continuation lines.
        $healthchecks = preg_match_all('#^HEALTHCHECK(?:[^\n]*\\\\\n)*[^\n]*$#m', $dockerfile, $matches);
        if (1 !== $healthchecks) {
            $problems[] = \sprintf('the Dockerfile has %d HEALTHCHECK instructions, not 1', $healthchecks);
        } else {
            if (!str_contains($matches[0][0], '/health/live')) {
                $problems[] = 'the HEALTHCHECK does not probe /health/live';
            }
            if (str_contains($matches[0][0], ':2019')) {
                $problems[] = 'the HEALTHCHECK calls the admin API';
            }
        }

        if (1 !== preg_match('#^\{(?<global>.*?)^\}#ms', $caddyfile, $global)
            || !str_contains($global['global'], self::CADDY_ADMIN_OPTION)) {
            $problems[] = 'the Caddyfile does not read CADDY_ADMIN for its admin option';
        }

        return $problems;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
