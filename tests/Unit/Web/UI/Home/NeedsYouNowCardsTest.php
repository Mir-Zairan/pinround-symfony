<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Home;

use App\Insight\Api\NeedsYouTicket;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Web\UI\Controller\HomeController;
use App\Web\UI\Home\NeedsYouNowCard;
use App\Web\UI\Home\NeedsYouNowCards;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use App\Workflow\Application\Turn\PlainVerbFor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Lena's "Needs you now" cards on a page whose time is 6 Nov 2026 12:00 UTC, a fixed day that is not today. Mia left
 * Acme Foods, so the member names have none for her.
 */
final class NeedsYouNowCardsTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f2a01';
    public const string LENA = '0197a3c0-0000-7000-8000-0000000f2a51';
    public const string OMAR = '0197a3c0-0000-7000-8000-0000000f2a52';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f2a53';
    public const string TO_DO_STEP = '0197a3c0-0000-7000-8000-0000000f2c01';
    public const string DOING_STEP = '0197a3c0-0000-7000-8000-0000000f2c02';
    private const string ANOTHER_ORGANIZATIONS_STEP = '0197a3c0-0000-7000-8000-0000000f2c99';

    /** @var list<list<string>> the user ids of each displayNamesOf call */
    private array $nameLookups = [];

    private int $stepListings = 0;

    /**
     * SHOP-1: Omar's question, Blocker; SHOP-2: a question from Mia, who left, so "Someone"; SHOP-3: Omar's build to
     * start (To do, assigned), Normal, which the Why line leaves out; SHOP-4: a check, Major, one day old.
     */
    #[Test]
    public function eachCardSaysTheTaskWhoIsBlockedTheUrgentPriorityAndTheWait(): void
    {
        $tickets = [
            self::ticket(1, 'answer', self::DOING_STEP, 'blocker', '2026-11-06 09:00:00', askerId: self::OMAR),
            self::ticket(2, 'answer', self::DOING_STEP, 'major', '2026-10-27 11:00:00', askerId: self::MIA),
            self::ticket(3, 'build', self::TO_DO_STEP, 'normal', '2026-11-04 11:00:00', assigneeId: self::LENA),
            self::ticket(4, 'check', self::DOING_STEP, 'major', '2026-11-05 11:00:00'),
        ];

        $cards = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $tickets, new \DateTimeImmutable(self::NOW));

        self::assertSame(
            [
                ['SHOP-1', '/t/SHOP-1', 'Answer Omar’s question', 'Open ticket', ['Omar is blocked until you answer', 'Blocker'], 0, 'today'],
                ['SHOP-2', '/t/SHOP-2', 'Answer the question', 'Open ticket', ['Someone is blocked until you answer', 'Major'], 10, 'waiting 10 days'],
                ['SHOP-3', '/t/SHOP-3', 'Start building', 'Open ticket', [], 2, 'waiting 2 days'],
                ['SHOP-4', '/t/SHOP-4', 'Check the new ticket', 'Check it', ['Major'], 1, 'waiting 1 day'],
            ],
            array_map(static fn (NeedsYouNowCard $card): array => [$card->key, $card->url, $card->task, $card->openLabel, $card->reasons, $card->waitDays, $card->waitText], $cards),
        );
        self::assertSame([[self::OMAR, self::MIA, self::LENA], 1], [$this->nameLookups[0] ?? [], $this->stepListings]);
        self::assertCount(1, $this->nameLookups);
    }

    #[Test]
    public function aWaitThatStartsAfterNowCountsNoDays(): void
    {
        $tickets = [self::ticket(1, 'build', self::DOING_STEP, 'normal', '2026-11-07 09:00:00')];

        $cards = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $tickets, new \DateTimeImmutable(self::NOW));

        self::assertSame([[0, 'today', 'Build it']], array_map(static fn (NeedsYouNowCard $card): array => [$card->waitDays, $card->waitText, $card->task], $cards));
        self::assertSame([], $this->nameLookups, 'Nobody to name, so no names are read.');
    }

    #[Test]
    public function noTicketsReadNothing(): void
    {
        self::assertSame([], $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], new \DateTimeImmutable(self::NOW)));
        self::assertSame([[], 0], [$this->nameLookups, $this->stepListings]);
    }

    #[Test]
    public function aTicketInAStepOfNoneOfTheOrganizationsStepsIsRefused(): void
    {
        $tickets = [self::ticket(1, 'build', self::DOING_STEP, 'normal', '2026-11-05 09:00:00'), self::ticket(2, 'build', self::ANOTHER_ORGANIZATIONS_STEP, 'normal', '2026-11-05 09:00:00')];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The step of the ticket SHOP-2 is not a step of the organization.');

        $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $tickets, new \DateTimeImmutable(self::NOW));
    }

    private static function ticket(int $number, string $kind, string $stepId, string $priority, string $waitingSince, ?string $askerId = null, ?string $assigneeId = null): NeedsYouTicket
    {
        return new NeedsYouTicket(
            new TicketId(\sprintf('0197a3c0-0000-7000-8000-0000000f2b%02d', $number)),
            'SHOP-'.$number,
            'Ticket '.$number,
            $kind,
            new \DateTimeImmutable($waitingSince.' UTC'),
            $stepId,
            $priority,
            null === $askerId ? null : new UserId($askerId),
            null === $assigneeId ? null : new UserId($assigneeId),
        );
    }

    private function cards(): NeedsYouNowCards
    {
        $routes = new RouteCollection();
        $routes->add(HomeController::TICKET_ROUTE, new Route('/t/{key}'));

        return new NeedsYouNowCards($this->memberNames(), $this->steps(), new PlainVerbFor(), new UrlGenerator($routes, new RequestContext()));
    }

    /**
     * Like the real one: it refuses more than MAX_USER_IDS ids, runs no query for none, and leaves out a person who
     * is no member (Mia).
     */
    private function memberNames(): MemberNameQueries
    {
        return new readonly class($this->recordNameLookup(...)) implements MemberNameQueries {
            /**
             * @param \Closure(list<string>): void $recordLookup
             */
            public function __construct(private \Closure $recordLookup)
            {
            }

            public function displayNamesOf(OrganizationId $organizationId, array $userIds): array
            {
                if (\count($userIds) > self::MAX_USER_IDS) {
                    throw new \InvalidArgumentException('Too many user ids.');
                }
                if ([] === $userIds) {
                    return [];
                }
                ($this->recordLookup)(array_map(static fn (UserId $userId): string => $userId->toString(), $userIds));
                $members = [NeedsYouNowCardsTest::LENA => 'Lena Berg', NeedsYouNowCardsTest::OMAR => 'Omar Haddad'];
                $names = [];
                foreach ($userIds as $userId) {
                    if (isset($members[$userId->toString()])) {
                        $names[$userId->toString()] = $members[$userId->toString()];
                    }
                }
                ksort($names);

                return $names;
            }
        };
    }

    private function steps(): StepQueries
    {
        return new readonly class($this->recordStepListing(...)) implements StepQueries {
            /**
             * @param \Closure(): void $recordListing
             */
            public function __construct(private \Closure $recordListing)
            {
            }

            public function stepsFor(TeamId $teamId): array
            {
                throw new \LogicException('Home reads no team\'s steps.');
            }

            public function moveTargetsFor(TeamId $teamId): array
            {
                throw new \LogicException('Home reads no move targets.');
            }

            public function listedSteps(): array
            {
                ($this->recordListing)();

                return [
                    new ListedStepView(NeedsYouNowCardsTest::TO_DO_STEP, 'To do', 'todo', 'To do', null, false, 'todo'),
                    new ListedStepView(strtoupper(NeedsYouNowCardsTest::DOING_STEP), 'In progress', 'doing', 'Doing', null, false, 'progress'),
                ];
            }

            public function groupOf(string $stepId): ?string
            {
                throw new \LogicException('Home reads the steps\' groups from the listed steps.');
            }
        };
    }

    /**
     * @param list<string> $userIds
     */
    private function recordNameLookup(array $userIds): void
    {
        $this->nameLookups[] = $userIds;
    }

    private function recordStepListing(): void
    {
        ++$this->stepListings;
    }
}
