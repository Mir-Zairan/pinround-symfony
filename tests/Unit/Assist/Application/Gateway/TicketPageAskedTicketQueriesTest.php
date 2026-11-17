<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\Gateway\AskedTicket;
use App\Assist\Application\Gateway\TicketPageAskedTicketQueries;
use App\Assist\Application\PromptTicket;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TicketPageAskedTicketQueries with Ticketing's ports as doubles: the viewer's predicate is the real TicketVisibility
 * built from each person's teams, and pageOf answers under it as the real query does, unless a test makes it hand back
 * a page its SQL let through and the predicate refuses, which the second guard must still turn away.
 */
final class TicketPageAskedTicketQueriesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a7a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a7a2';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a7b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a7b2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a7c1';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000a7d1';
    private const string KEY = 'SHOP-7';
    private const string TICKET_TEXT = 'Pressing Pay on the last checkout step does nothing.';

    /** @var list<array{string, TicketVisibility}> the key and the viewer's predicate of each pageOf call */
    private array $pageReads = [];

    #[Test]
    public function aTicketForEveryoneComesBackWithItsTitleAndTextReadUnderTheViewersPredicate(): void
    {
        $askedTicket = $this->queries(self::page(TicketVisibilityFacts::FOR_EVERYONE))->visibleTicketOf(new TicketId(self::TICKET), self::omar());

        self::assertEquals(new AskedTicket(new PromptTicket('Checkout fails', self::TICKET_TEXT), false), $askedTicket);
        self::assertCount(1, $this->pageReads);
        [$key, $predicate] = $this->pageReads[0];
        self::assertSame([self::KEY, self::OMAR, self::ACME_FOODS], [$key, $predicate->viewerId()->toString(), $predicate->organizationId()->toString()]);
    }

    #[Test]
    public function aTicketWithNoTextGivesThePromptNoText(): void
    {
        $askedTicket = $this->queries(self::page(TicketVisibilityFacts::FOR_EVERYONE, description: ''))->visibleTicketOf(new TicketId(self::TICKET), self::omar());

        self::assertNotNull($askedTicket);
        self::assertNull($askedTicket->promptTicket->text);
    }

    #[Test]
    public function aTeamOnlyTicketLenasTeamOwnsComesBackMarkedTeamOnly(): void
    {
        $askedTicket = $this->queries(self::page(TicketVisibilityFacts::TEAM_ONLY))->visibleTicketOf(new TicketId(self::TICKET), self::lena());

        self::assertNotNull($askedTicket);
        self::assertTrue($askedTicket->isTeamOnly);
    }

    #[Test]
    public function aTicketIdTheOrganizationDoesNotHaveIsNullWithoutReadingAPage(): void
    {
        $askedTicket = $this->queries(self::page(TicketVisibilityFacts::FOR_EVERYONE), key: null)->visibleTicketOf(new TicketId(self::TICKET), self::omar());

        self::assertNull($askedTicket);
        self::assertSame([], $this->pageReads);
    }

    #[Test]
    public function aTeamOnlyTicketOmarIsNotInIsNullAsAnUnknownOne(): void
    {
        $askedTicket = $this->queries(self::page(TicketVisibilityFacts::TEAM_ONLY))->visibleTicketOf(new TicketId(self::TICKET), self::omar());

        self::assertNull($askedTicket);
        self::assertCount(1, $this->pageReads);
    }

    /**
     * @return iterable<string, array{TicketPageView}>
     */
    public static function pagesThePredicateRefuses(): iterable
    {
        yield 'team-only of a team Omar is not in' => [self::page(TicketVisibilityFacts::TEAM_ONLY)];
        yield 'for everyone in another organization' => [self::page(TicketVisibilityFacts::FOR_EVERYONE, organizationId: self::BETA_BAKERY)];
    }

    #[Test]
    #[DataProvider('pagesThePredicateRefuses')]
    public function aPageTheViewersPredicateRefusesIsNullEvenWhenThePageReadLetItThrough(TicketPageView $leakedPage): void
    {
        $askedTicket = $this->queries($leakedPage, isPageReadLeaking: true)->visibleTicketOf(new TicketId(self::TICKET), self::omar());

        self::assertCount(1, $this->pageReads, 'The page was not read, so the second guard was never reached.');
        self::assertNull($askedTicket);
    }

    /**
     * @param bool $isPageReadLeaking pageOf hands back the page whatever the predicate says, as a broken SQL predicate would
     */
    private function queries(TicketPageView $page, ?string $key = self::KEY, bool $isPageReadLeaking = false): TicketPageAskedTicketQueries
    {
        $keys = self::createStub(TicketKeyQueries::class);
        $keys->method('keyOf')->willReturnCallback(static fn (TicketId $ticketId): ?string => self::TICKET === $ticketId->toString() ? $key : null);

        $visibilities = self::createStub(TicketVisibilities::class);
        $visibilities->method('of')->willReturnCallback(static fn (OrganizationId $organizationId, UserId $viewerId): TicketVisibility => TicketVisibility::for(
            $organizationId,
            $viewerId,
            self::LENA === $viewerId->toString() ? [new TeamId(self::SHOP_TEAM)] : [],
            false,
            null,
        ));

        $ticketQueries = self::createStub(TicketQueries::class);
        $ticketQueries->method('pageOf')->willReturnCallback(function (string $askedKey, TicketVisibility $viewer) use ($page, $isPageReadLeaking): ?TicketPageView {
            $this->pageReads[] = [$askedKey, $viewer];
            if ($askedKey !== $page->key) {
                return null;
            }

            return $isPageReadLeaking || $viewer->isVisible($page->visibilityFacts) ? $page : null;
        });

        return new TicketPageAskedTicketQueries($keys, $visibilities, $ticketQueries);
    }

    private static function page(string $visibility, string $description = self::TICKET_TEXT, string $organizationId = self::ACME_FOODS): TicketPageView
    {
        $shopTeam = new TeamId(self::SHOP_TEAM);
        $lena = new UserId(self::LENA);

        return new TicketPageView(
            new TicketId(self::TICKET),
            self::KEY,
            'Checkout fails',
            $description,
            'bug',
            'major',
            null,
            null,
            [],
            new \DateTimeImmutable('2027-03-15 09:00:00+00:00'),
            'Shop',
            'To do',
            'todo',
            'To do',
            'Lena',
            null,
            new TicketVisibilityFacts(new OrganizationId($organizationId), $visibility, $shopTeam, $lena),
            new TicketTurnSnapshot(new TicketId(self::TICKET), '0197a3c0-0000-7000-8000-00000000a7e1', '0197a3c0-0000-7000-8000-00000000a7e2', 'todo', 'todo', $shopTeam, $lena, 'Lena', [$lena], [], $visibility, 'major', null, $lena),
            [self::LENA => 'Lena'],
            1,
        );
    }

    private static function omar(): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none());
    }

    private static function lena(): ActorContext
    {
        return new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [], []));
    }
}
