<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Team;

use App\Ticketing\Api\ProjectSummary;
use App\Web\UI\Team\ProjectTicketsLink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProjectTicketsLinkTest extends TestCase
{
    #[Test]
    public function theLinkFiltersTheTicketsListByTheProjectsKey(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('ticketing_tickets', ['project' => 'SHOP'])
            ->willReturn('/tickets?project=SHOP');

        self::assertSame('/tickets?project=SHOP', new ProjectTicketsLink($urlGenerator)->urlOf(new ProjectSummary('p1', 'SHOP', 'Web shop', 4)));
    }

    #[Test]
    public function thereIsNoLinkWhileTheTicketsRouteDoesNotExist(): void
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willThrowException(new RouteNotFoundException());

        self::assertNull(new ProjectTicketsLink($urlGenerator)->urlOf(new ProjectSummary('p1', 'SHOP', 'Web shop', 4)));
    }
}
