<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The hub refuses a token whose `aud` is not its resource_identifier, so the app signs both its publisher and its
 * subscriber tokens with the same variable the Caddyfile pins the hub's resource_identifier to: MERCURE_PUBLIC_URL.
 */
final class MercureAudienceConfigTest extends TestCase
{
    private const string AUDIENCE = '%env(MERCURE_PUBLIC_URL)%';
    private const string RESOURCE_IDENTIFIER = 'resource_identifier {$MERCURE_PUBLIC_URL}';

    #[Test]
    public function theTokensAndTheHubAgreeOnTheAudience(): void
    {
        self::assertSame([], self::problemsIn(self::read('config/packages/mercure.yaml'), self::read('docker/frankenphp/Caddyfile')));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function brokenConfigs(): iterable
    {
        $mercureYaml = self::read('config/packages/mercure.yaml');
        $caddyfile = self::read('docker/frankenphp/Caddyfile');

        yield 'the tokens are signed for the issuer' => [
            str_replace("aud: '".self::AUDIENCE."'", "aud: '%env(MERCURE_JWT_ISSUER)%'", $mercureYaml),
            $caddyfile,
            ['the publisher tokens do not carry aud MERCURE_PUBLIC_URL', 'the subscriber tokens do not carry aud MERCURE_PUBLIC_URL'],
        ];
        yield 'the subscriber claims lose the shared anchor' => [
            str_replace('claims: *claims', "claims: { iss: '%env(MERCURE_JWT_ISSUER)%' }", $mercureYaml),
            $caddyfile,
            ['the subscriber tokens do not carry aud MERCURE_PUBLIC_URL'],
        ];
        yield 'the hub takes the audience from each request' => [
            $mercureYaml,
            str_replace(self::RESOURCE_IDENTIFIER, '', $caddyfile),
            ['the Caddyfile hub does not pin resource_identifier to MERCURE_PUBLIC_URL'],
        ];
        yield 'the hub pins another variable' => [
            $mercureYaml,
            str_replace(self::RESOURCE_IDENTIFIER, 'resource_identifier {$MERCURE_JWT_ISSUER}', $caddyfile),
            ['the Caddyfile hub does not pin resource_identifier to MERCURE_PUBLIC_URL'],
        ];
    }

    /**
     * @param list<string> $expectedProblems
     */
    #[Test]
    #[DataProvider('brokenConfigs')]
    public function aBrokenConfigIsCaught(string $mercureYaml, string $caddyfile, array $expectedProblems): void
    {
        self::assertSame($expectedProblems, self::problemsIn($mercureYaml, $caddyfile));
    }

    /**
     * @return list<string>
     */
    private static function problemsIn(string $mercureYaml, string $caddyfile): array
    {
        $problems = [];
        $config = Yaml::parse($mercureYaml);
        foreach (['publisher', 'subscriber'] as $side) {
            if (self::AUDIENCE !== self::valueAt($config, ['mercure', 'hubs', 'default', $side, 'claims', 'aud'])) {
                $problems[] = \sprintf('the %s tokens do not carry aud MERCURE_PUBLIC_URL', $side);
            }
        }

        // The hub's own mercure block, up to its first closing brace at the block's indent.
        if (1 !== preg_match('#^\tmercure \{\n(?<block>.*?)^\t\}#ms', $caddyfile, $hub)
            || 1 !== preg_match('#^\t\t'.preg_quote(self::RESOURCE_IDENTIFIER, '#').'$#m', $hub['block'])) {
            $problems[] = 'the Caddyfile hub does not pin resource_identifier to MERCURE_PUBLIC_URL';
        }

        return $problems;
    }

    /**
     * @param list<string> $path
     */
    private static function valueAt(mixed $config, array $path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($config) || !\array_key_exists($key, $config)) {
                return null;
            }
            $config = $config[$key];
        }

        return $config;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
