<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\IssuedOAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssuedOAuthAuthorizationCodeTest extends TestCase
{
    private const string CODE_ID = '0197a3c0-0000-7000-8000-000000000e02';

    #[Test]
    public function thePlainCodeIsNeverDumpedButTheIdIs(): void
    {
        $issued = self::issue();

        $dump = print_r($issued, true);

        self::assertStringNotContainsString($issued->reveal(), $dump);
        self::assertStringContainsString(self::CODE_ID, $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    #[Test]
    public function anIssuedCodeIsNeverSerialized(): void
    {
        $issued = self::issue();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('An issued OAuth authorization code is never serialized.');

        serialize($issued);
    }

    private static function issue(): IssuedOAuthAuthorizationCode
    {
        return OAuthAuthorizationCode::issue(
            self::CODE_ID,
            new OrganizationId('0197a3c0-0000-7000-8000-000000000e11'),
            new UserId('0197a3c0-0000-7000-8000-000000000e21'),
            OAuthClient::register(null, ['https://claude.example/callback'], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable('2027-03-15 08:00:00 UTC')),
            'https://claude.example/callback',
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'S256',
            [ApiTokenScope::Read],
            new HmacApiTokenHashing('unit-test-pepper-of-at-least-32-bytes'),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        );
    }
}
