<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Http;

use App\SharedKernel\Infrastructure\Http\ExtensionOrigins;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExtensionOriginsTest extends TestCase
{
    private const string FIRST = 'chrome-extension://firstextensionidaaaaaaaaaaaaaaaa';
    private const string SECOND = 'chrome-extension://secondextensionidbbbbbbbbbbbbbbb';

    #[Test]
    public function eachListedOriginIsAllowedWithTheSpacesAroundItTrimmed(): void
    {
        $origins = new ExtensionOrigins(' '.self::FIRST.' ,'.self::SECOND.',');

        self::assertTrue($origins->allows(self::FIRST));
        self::assertTrue($origins->allows(self::SECOND));
    }

    #[Test]
    public function onlyAnExactMatchIsAllowed(): void
    {
        $origins = new ExtensionOrigins(self::FIRST);

        foreach (['', ' ', strtoupper(self::FIRST), self::FIRST.'/', 'chrome-extension://firstextensionid', self::FIRST.','.self::SECOND, 'null', '*'] as $origin) {
            self::assertFalse($origins->allows($origin), $origin);
        }
    }

    #[Test]
    public function anUnsetEmptyOrBlankListAllowsNoOrigin(): void
    {
        foreach ([null, '', ' ', ',', ' , '] as $list) {
            $origins = new ExtensionOrigins($list);

            self::assertFalse($origins->allows(self::FIRST), var_export($list, true));
            self::assertFalse($origins->allows(''), var_export($list, true));
            self::assertNull($origins->allowedOriginOf(self::requestFrom([self::FIRST])), var_export($list, true));
        }
    }

    #[Test]
    public function aRequestsOriginIsAllowedOnlyWhenItSentExactlyOneListedOrigin(): void
    {
        $origins = new ExtensionOrigins(self::FIRST.','.self::SECOND);

        self::assertSame(self::SECOND, $origins->allowedOriginOf(self::requestFrom([self::SECOND])));
        self::assertNull($origins->allowedOriginOf(self::requestFrom([])));
        self::assertNull($origins->allowedOriginOf(self::requestFrom(['https://evil.example'])));
        self::assertNull($origins->allowedOriginOf(self::requestFrom([self::FIRST, self::SECOND])));
    }

    /**
     * @param list<string> $origins
     */
    private static function requestFrom(array $origins): Request
    {
        $request = Request::create('/oauth/token', 'POST');
        $request->headers->remove('Origin');
        foreach ($origins as $origin) {
            $request->headers->set('Origin', $origin, false);
        }

        return $request;
    }
}
