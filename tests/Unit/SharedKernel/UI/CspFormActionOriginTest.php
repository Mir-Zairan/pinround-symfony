<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI;

use App\SharedKernel\UI\CspFormActionOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CspFormActionOriginTest extends TestCase
{
    #[Test]
    public function aRequestNoPageMarkedAllowsNone(): void
    {
        self::assertNull(CspFormActionOrigin::allowedOn(Request::create('/')));
    }

    #[Test]
    public function theOriginAPageAllowedIsReadBackFromItsRequestOnly(): void
    {
        $consentRequest = Request::create('/oauth/authorize');
        CspFormActionOrigin::allowOn($consentRequest, 'http://127.0.0.1:53127');

        self::assertSame('http://127.0.0.1:53127', CspFormActionOrigin::allowedOn($consentRequest));
        self::assertNull(CspFormActionOrigin::allowedOn(Request::create('/me/security')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeOrigins(): iterable
    {
        yield 'wildcard host' => ['https://*.example'];
        yield 'wildcard port' => ['http://127.0.0.1:*'];
        yield 'path' => ['https://claude.example/cb'];
        yield 'second source' => ['https://claude.example https://evil.example'];
        yield 'directive injection' => ["https://claude.example; script-src 'unsafe-inline'"];
        yield 'scheme only' => ['https:'];
        yield 'IPv6 host' => ['http://[::1]:53127'];
        yield 'capitals' => ['https://Claude.example'];
        yield 'trailing newline' => ["https://claude.example\n"];
    }

    #[Test]
    #[DataProvider('unsafeOrigins')]
    public function anUnsafeOriginIsRefusedAndNothingIsAllowed(string $origin): void
    {
        $request = Request::create('/oauth/authorize');

        try {
            CspFormActionOrigin::allowOn($request, $origin);
            self::fail('The origin was allowed.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A form-action origin must be http or https with a plain lowercase host and no path.', $refusal->getMessage());
        }

        self::assertNull(CspFormActionOrigin::allowedOn($request));
    }

    #[Test]
    #[DataProvider('unsafeOrigins')]
    public function anUnsafeValueARouteOrAnotherListenerPutUnderTheAttributeIsIgnored(string $origin): void
    {
        $request = Request::create('/oauth/authorize');
        $request->attributes->set('_csp_form_action_origin', $origin);

        self::assertNull(CspFormActionOrigin::allowedOn($request));
    }
}
