<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssuedApiTokenTest extends TestCase
{
    #[Test]
    public function thePlainTokenIsNeverDumpedButThePrefixIs(): void
    {
        $issued = ApiToken::issue(
            '0197a3c0-0000-7000-8000-000000000b01',
            new OrganizationId('0197a3c0-0000-7000-8000-000000000b11'),
            new UserId('0197a3c0-0000-7000-8000-000000000b21'),
            'Claude Code',
            [ApiTokenScope::Read],
            new HmacApiTokenHashing('unit-test-pepper-of-at-least-32-bytes'),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        );

        $dump = print_r($issued, true);

        self::assertStringNotContainsString($issued->reveal(), $dump);
        self::assertStringContainsString($issued->token()->prefix().'…', $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    #[Test]
    public function anIssuedTokenIsNeverSerialized(): void
    {
        $issued = ApiToken::issue(
            '0197a3c0-0000-7000-8000-000000000b02',
            new OrganizationId('0197a3c0-0000-7000-8000-000000000b11'),
            new UserId('0197a3c0-0000-7000-8000-000000000b21'),
            'Claude Code',
            [ApiTokenScope::Read],
            new HmacApiTokenHashing('unit-test-pepper-of-at-least-32-bytes'),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An issued API token is never serialized.');

        serialize($issued);
    }
}
