<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\Tool\GetTicket;
use App\Conversation\Api\ThreadQueries;
use App\Conversation\Api\ThreadView;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\SavedTurn;
use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TeamLoads;
use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistView;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundView;
use App\Review\Api\SharedRoundNotes;
use App\Review\Api\TicketRounds;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPosition;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\SavedTicketPageTurns;
use App\Ticketing\Application\TicketPeopleRoles;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Application\Turn\LoadBasedTurnPeopleChoice;
use App\Workflow\Application\Turn\OrderedTurnRules;
use App\Workflow\Application\Turn\PlainNowNext;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The collaborators of GetTicket and of the Ticketing turn read it maps (SavedTicketPageTurns), each recording what it was asked, with Workflow's real turn rules,
 * people choice and Now and Next sentences. Omar of Acme Foods calls with a read token. The ticket is the one
 * GetTicketTest::snapshot() describes; pageOf finds it only by its exact key, as the real read does, and the voter
 * grants every attribute but the denied ones. UserQueries leaves an unknown id out and sorts by id, as the real one does.
 */
final class GetTicketFakes implements CurrentApiToken, CurrentUser, TicketTurnQueries, TicketVisibilities, TicketQueries, TeamLoadQueries, RuleQueries, UserQueries, ThreadQueries, AuthorizationCheckerInterface
{
    public ?TicketTurnSnapshot $snapshot;
    public ?TicketPageView $page;
    public ?SavedTurn $savedTurn;
    public ThreadView $thread;
    public ?ChecklistView $checklist = null;
    public bool $isReviewBeforeTest = true;
    /** @var list<string> */
    public array $deniedAttributes = [];
    /** @var array<string, string> by user id string, the people Identity knows */
    public array $knownNames;

    /** @var list<string> the keys pageOf was asked for */
    public array $pagesAskedFor = [];
    /** @var list<string> */
    public array $threadsAskedFor = [];
    /** @var list<list<string>> */
    public array $namesAskedFor = [];
    /** @var list<string> */
    public array $attributesAsked = [];
    /** @var list<string> */
    public array $roundFactsAskedFor = [];
    public int $loadReads = 0;

    public function __construct()
    {
        $this->snapshot = GetTicketTest::snapshot();
        $this->page = GetTicketTest::page($this->snapshot);
        $this->savedTurn = new SavedTurn(new UserId(GetTicketTest::OMAR), null, 'build');
        $this->thread = new ThreadView([], false);
        $this->knownNames = GetTicketTest::knownNames();
    }

    public function tool(): GetTicket
    {
        $fakes = $this;

        return new GetTicket(
            new TicketVisibilityCheck($this, $this, $this),
            $this,
            new readonly class($fakes) implements TicketKeyQueries {
                public function __construct(private GetTicketFakes $fakes)
                {
                }

                public function keyOf(TicketId $ticketId): ?string
                {
                    throw new \LogicException('get_ticket never looks a key up by its id.');
                }

                public function idOf(string $key): ?TicketId
                {
                    return null !== $this->fakes->snapshot && GetTicketTest::KEY === $key ? $this->fakes->snapshot->ticketId : null;
                }
            },
            $this,
            $this,
            $this,
            $this->ticketPageTurns(),
            $this,
            new readonly class($fakes) implements ChecklistQueries {
                public function __construct(private GetTicketFakes $fakes)
                {
                }

                public function accessFactsOf(string $itemId): ?CheckItemAccessFacts
                {
                    throw new \LogicException('get_ticket never reads one checklist item.');
                }

                public function forTicket(TicketId $ticketId): ?ChecklistView
                {
                    return $this->fakes->checklist;
                }
            },
            new readonly class($fakes) implements RoundQueries {
                public function __construct(private GetTicketFakes $fakes)
                {
                }

                public function accessFactsOf(string $roundId): RoundAccessFacts
                {
                    $this->fakes->roundFactsAskedFor[] = $roundId;

                    return new RoundAccessFacts(new OrganizationId(GetTicketTest::ACME_FOODS), $roundId, new TicketId(GetTicketTest::TICKET), new UserId(GetTicketTest::MIA), false, false);
                }

                public function forTicket(TicketId $ticketId, UserId $viewer): TicketRounds
                {
                    throw new \LogicException('get_ticket never reads the rounds.');
                }

                public function draftOf(string $roundId, UserId $owner): ?RoundView
                {
                    throw new \LogicException('get_ticket never reads a draft.');
                }

                public function draftScreenshotOf(TicketId $ticketId, string $screenshotId): ?RoundAccessFacts
                {
                    throw new \LogicException('get_ticket never reads a screenshot.');
                }

                public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes
                {
                    throw new \LogicException('get_ticket never reads round notes.');
                }

                public function sharedRoundNotes(TicketId $ticketId): ?SharedRoundNotes
                {
                    throw new \LogicException('get_ticket never reads round notes.');
                }
            },
            $this,
        );
    }

    public function ticketPageTurns(): SavedTicketPageTurns
    {
        return new SavedTicketPageTurns(
            $this,
            $this,
            $this,
            new OrderedTurnRules(new ReviewerChoice(), new TesterChoice()),
            new PlainNowNext(new ReviewerChoice(), new TesterChoice()),
            new TicketPeopleRoles(new LoadBasedTurnPeopleChoice(new ReviewerChoice(), new TesterChoice())),
        );
    }

    public function access(): ApiTokenAccess
    {
        return new ApiTokenAccess(GetTicketTest::OMARS_TOKEN, new OrganizationId(GetTicketTest::ACME_FOODS), ['read'], new \DateTimeImmutable('2027-02-09 00:30:00 UTC'));
    }

    public function id(): UserId
    {
        return new UserId(GetTicketTest::OMAR);
    }

    public function idOf(mixed $tokenUser): ?UserId
    {
        return null;
    }

    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        return null !== $this->snapshot && $this->snapshot->ticketId->equals($ticketId) ? $this->snapshot : null;
    }

    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
    {
        return TicketVisibility::for($organizationId, $viewerId, [new TeamId(GetTicketTest::SHOP_TEAM)], false, null);
    }

    public function visibleTicketIdsOf(TicketVisibility $viewer, array $ticketIds): array
    {
        throw new \LogicException('get_ticket never checks a list of tickets.');
    }

    public function list(ListFilter $filter, ?Cursor $cursor = null): array
    {
        throw new \LogicException('get_ticket never reads the tickets list.');
    }

    public function pageOf(string $key, TicketVisibility $viewer): ?TicketPageView
    {
        $this->pagesAskedFor[] = $key;

        return null !== $this->page && $this->page->key === $key && $viewer->isVisible($this->page->visibilityFacts) ? $this->page : null;
    }

    public function positionOf(string $key, ListFilter $filter): ?TicketPosition
    {
        throw new \LogicException('get_ticket never reads a list position.');
    }

    public function waitingTurnsOf(array $userIds, TicketId $excludedTicketId): array
    {
        throw new \LogicException('get_ticket reads the waiting turns with the saved turn.');
    }

    public function loadsAndSavedTurnOf(array $userIds, TicketId $ticketId): TeamLoads
    {
        ++$this->loadReads;

        return new TeamLoads([], $this->savedTurn);
    }

    public function rulesFor(TeamId $teamId): RuleSetView
    {
        return new RuleSetView(true, true, $this->isReviewBeforeTest);
    }

    public function idByEmail(string $email): ?UserId
    {
        throw new \LogicException('get_ticket never looks a user up by email.');
    }

    public function byIds(array $userIds): array
    {
        $wanted = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->namesAskedFor[] = $wanted;
        $found = [];
        foreach ($this->knownNames as $userKey => $name) {
            if (\in_array($userKey, $wanted, true)) {
                $found[$userKey] = new UserView(new UserId($userKey), $name);
            }
        }
        ksort($found);

        return $found;
    }

    public function timeZonesOf(array $userIds): array
    {
        throw new \LogicException('get_ticket never reads time zones.');
    }

    public function emailsOf(array $userIds): array
    {
        throw new \LogicException('get_ticket never reads email addresses.');
    }

    public function threadOf(TicketId $ticketId): ThreadView
    {
        $this->threadsAskedFor[] = $ticketId->toString();

        return $this->thread;
    }

    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        \assert(\is_string($attribute));
        $this->attributesAsked[] = $attribute;
        if ($subject instanceof TicketVisibilityFacts && !$subject->organizationId->equals(new OrganizationId(GetTicketTest::ACME_FOODS))) {
            return false;
        }

        return !\in_array($attribute, $this->deniedAttributes, true);
    }
}
