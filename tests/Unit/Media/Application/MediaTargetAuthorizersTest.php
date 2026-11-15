<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Application\MediaTargetAuthorizers;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaTargetAuthorizersTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1701';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1702';

    #[Test]
    public function theAuthorizerOfTheKindDecidesAndNoOtherIsAsked(): void
    {
        $tickets = new RecordingMediaTargetAuthorizer('ticket');
        $tickets->allow(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'SHOP-12');
        $sketches = new RecordingMediaTargetAuthorizer('sketch');
        $authorizers = new MediaTargetAuthorizers([$sketches, $tickets]);

        self::assertTrue($authorizers->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'ticket', 'SHOP-12'));
        self::assertFalse($authorizers->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'ticket', 'SHOP-13'));
        self::assertCount(2, $tickets->asked());
        self::assertSame([], $sketches->asked());
    }

    #[Test]
    public function aKindNoAuthorizerDecidesIsRefused(): void
    {
        $tickets = new RecordingMediaTargetAuthorizer('ticket');
        $tickets->allow(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'SHOP-12');
        $authorizers = new MediaTargetAuthorizers([$tickets]);

        self::assertFalse($authorizers->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'Ticket', 'SHOP-12'));
        self::assertFalse($authorizers->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), '', 'SHOP-12'));
        self::assertSame([], $tickets->asked());
    }

    #[Test]
    public function noAuthorizerAtAllRefusesEveryKind(): void
    {
        self::assertFalse(new MediaTargetAuthorizers([])->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'ticket', 'SHOP-12'));
    }

    #[Test]
    public function twoAuthorizersOfOneKindAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Two media target authorizers decide the kind "ticket".');

        new MediaTargetAuthorizers([new RecordingMediaTargetAuthorizer('ticket'), new RecordingMediaTargetAuthorizer('ticket')]);
    }
}
