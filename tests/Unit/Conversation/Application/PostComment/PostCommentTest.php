<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\PostComment;

use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Application\PostComment\PostComment;
use App\Conversation\Application\VisibleTickets;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Conversation\Application\InMemoryMessages;
use App\Tests\Unit\Conversation\Application\InMemoryPingClearing;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What PostComment hands to PingClearing (FR-PNG-05); the comment itself is covered by the thread's functional tests.
 */
final class PostCommentTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e601';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e604';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000e605';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e611';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e612';
    private const string POSTED_AT = '2027-03-15 10:20:00+00:00';

    private InMemoryPingClearing $pingClearing;
    private PostComment $postComment;

    protected function setUp(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set($acmeFoods);
        $this->pingClearing = new InMemoryPingClearing();
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::OMAR), 2);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::OTHER_TICKET), new UserId(self::OMAR), 4);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::LENA), 1);
        $this->postComment = new PostComment(new VisibleTickets(self::everyoneSeesTicketsForEveryone(), $tenantContext), new InMemoryMessages(), new RecordingConversationEvents(), $this->pingClearing);
    }

    #[Test]
    public function aCommentClearsTheAuthorsPingsOnTheTicketAtThePostTimeAndSaysHowMany(): void
    {
        $postedAt = new \DateTimeImmutable(self::POSTED_AT);

        $comment = $this->postComment->post(new TicketId(self::TICKET), self::ticketForEveryone(), new UserId(self::OMAR), 'Fixed in build 43.', $postedAt);

        self::assertSame(2, $comment->clearedPingCount);
        self::assertEquals([[self::ACME_FOODS, self::TICKET, self::OMAR, $postedAt]], $this->pingClearing->calls());
    }

    #[Test]
    public function aRefusedCommentClearsNothing(): void
    {
        try {
            $this->postComment->post(new TicketId(self::TICKET), self::ticketForEveryone(), new UserId(self::OMAR), " \t\n", new \DateTimeImmutable(self::POSTED_AT));
            self::fail('The comment was posted.');
        } catch (MessageBodyRefused $refusal) {
            self::assertSame('A message needs a body.', $refusal->getMessage());
        }

        self::assertSame([], $this->pingClearing->calls());
    }

    private static function ticketForEveryone(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA));
    }

    private static function everyoneSeesTicketsForEveryone(): TicketVisibilities
    {
        return new readonly class implements TicketVisibilities {
            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                return TicketVisibility::for($organizationId, $viewerId, [], false, null);
            }
        };
    }
}
