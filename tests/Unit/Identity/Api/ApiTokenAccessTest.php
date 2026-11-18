<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Api;

use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiTokenAccessTest extends TestCase
{
    #[Test]
    public function anExpiryGivenInAnotherZoneIsKeptAsTheSameInstantInUtc(): void
    {
        $access = new ApiTokenAccess('019a5f00-0000-7000-8000-00000000e611', new OrganizationId('019a5f00-0000-7000-8000-00000000e601'), ['read'], new \DateTimeImmutable('2027-02-09 00:30:00 Europe/Berlin'));

        self::assertSame('2027-02-08T23:30:00+00:00', $access->expiresAt->format(\DATE_ATOM));
        self::assertSame('UTC', $access->expiresAt->getTimezone()->getName());
    }
}
