<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Application\MediaTargetAuthorizers;
use App\Review\UI\Media\RoundMediaTarget;
use App\Ticketing\UI\Media\NewTicketMediaTarget;
use App\Ticketing\UI\Media\TicketMediaTarget;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MediaTargetAuthorizers as the container builds it: every service implementing MediaTargetAuthorizer reaches it
 * through the _instanceof tag app.media_target_authorizer (config/services.yaml), with no line per authorizer. Without
 * the tag the list is empty and every upload is refused, which no other test of Media would tell apart from a denial.
 */
final class MediaTargetAuthorizersWiringTest extends KernelTestCase
{
    #[Test]
    public function theNewTicketRoundAndTicketTargetsReachTheAuthorizersThroughTheTagAsTheOnlyKinds(): void
    {
        $authorizers = self::getContainer()->get('test.media_target_authorizers');
        self::assertInstanceOf(MediaTargetAuthorizers::class, $authorizers);

        // The list is private state; the wiring is what this test pins, so it reads it as it is built.
        $byKind = new \ReflectionProperty(MediaTargetAuthorizers::class, 'byKind')->getValue($authorizers);
        self::assertIsArray($byKind);

        $authorizerClassByKind = array_map(get_debug_type(...), $byKind);
        // The tag gives no order; sorting keeps each kind paired with its class, which canonicalizing would not.
        ksort($authorizerClassByKind);

        self::assertSame(
            [NewTicketMediaTarget::KIND => NewTicketMediaTarget::class, RoundMediaTarget::KIND => RoundMediaTarget::class, TicketMediaTarget::KIND => TicketMediaTarget::class],
            $authorizerClassByKind,
        );
    }
}
