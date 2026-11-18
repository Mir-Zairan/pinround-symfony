<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI;

use App\SharedKernel\UI\CspConnectSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CspConnectSourcesTest extends TestCase
{
    #[Test]
    public function eachOriginIsKeptOnceInTheOrderItWasFirstAllowed(): void
    {
        $cspConnectSources = new CspConnectSources();

        $cspConnectSources->allow('http://localhost:11434');
        $cspConnectSources->allow('https://pinround.example');
        $cspConnectSources->allow('http://localhost:11434');

        self::assertSame(['http://localhost:11434', 'https://pinround.example'], $cspConnectSources->origins());
    }

    #[Test]
    public function resetForgetsEveryOrigin(): void
    {
        $cspConnectSources = new CspConnectSources();
        $cspConnectSources->allow('http://localhost:11434');

        $cspConnectSources->reset();

        self::assertSame([], $cspConnectSources->origins());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeOrigins(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['localhost:11434'];
        yield 'wildcard' => ['http://*:11434'];
        yield 'bare wildcard' => ['*'];
        yield 'a second directive' => ['http://localhost:11434; script-src *'];
        yield 'a second source' => ['http://localhost:11434 https://evil.example'];
        yield 'a path' => ['http://localhost:11434/api'];
        yield 'a keyword' => ["'unsafe-inline'"];
        yield 'another scheme' => ['ws://localhost:11434'];
        yield 'uppercase host' => ['http://LOCALHOST:11434'];
        yield 'a trailing newline' => ["http://localhost:11434\n"];
    }

    #[Test]
    #[DataProvider('unsafeOrigins')]
    public function anOriginThatCouldWidenThePolicyIsRefusedAndNotKept(string $origin): void
    {
        $cspConnectSources = new CspConnectSources();

        try {
            $cspConnectSources->allow($origin);
            self::fail('The origin was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A connect-src origin must be http or https with a plain lowercase host and no path.', $refused->getMessage());
        }
        self::assertSame([], $cspConnectSources->origins());
    }
}
