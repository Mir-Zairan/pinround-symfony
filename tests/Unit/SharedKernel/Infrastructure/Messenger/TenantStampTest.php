<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class TenantStampTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    #[Test]
    public function itCarriesTheOrganizationThroughTheTransportSerializer(): void
    {
        $serializer = new PhpSerializer();
        $envelope = new Envelope(new \stdClass(), [new TenantStamp(new OrganizationId(strtoupper(self::ORG_A)))]);

        $stamp = $serializer->decode($serializer->encode($envelope))->last(TenantStamp::class);

        self::assertInstanceOf(TenantStamp::class, $stamp);
        self::assertSame(self::ORG_A, $stamp->organizationId()->toString());
    }

    #[Test]
    public function aStoredStampWithAnInvalidIdIsRefusedWhenRead(): void
    {
        $serialized = serialize(new TenantStamp(new OrganizationId(self::ORG_A)));
        $tampered = str_replace(self::ORG_A, 'zzzzzzzz-0000-7000-8000-00000000000a', $serialized);
        $stamp = unserialize($tampered, ['allowed_classes' => [TenantStamp::class]]);
        self::assertInstanceOf(TenantStamp::class, $stamp);

        $this->expectException(\InvalidArgumentException::class);

        $stamp->organizationId();
    }
}
