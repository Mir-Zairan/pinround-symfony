<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\ListMyTickets;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\UserQueries;
use App\Insight\Api\MyTicketQueries;
use App\Insight\Api\MyTickets;
use App\Insight\Api\TicketViewer;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Workflow\Api\StepQueries;
use App\Workflow\Application\Turn\PlainNowNext;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;

/**
 * The collaborators of ListMyTickets, each recording what it was asked, with Workflow's real Now sentences. Omar of
 * Acme Foods calls with a read token; he is in the Shop team and is the triager. UserQueries leaves an unknown id out
 * and sorts by id, as the real one does.
 */
final class ListMyTicketsFakes implements CurrentApiToken, CurrentUser, TicketVisibilities, MyTicketQueries, StepQueries, UserQueries
{
    public ?ApiTokenAccess $access;
    public ?UserId $userId;
    public MyTickets $myTickets;
    /** @var list<array{string, string}> */
    public array $visibilitiesAskedFor = [];
    /** @var list<array{TicketViewer, int}> */
    public array $myTicketsAskedFor = [];
    /** @var list<string> */
    public array $teamsAskedForSteps = [];
    public int $listedStepReads = 0;
    /** @var list<list<string>> */
    public array $namesAskedFor = [];

    public function __construct()
    {
        $this->access = new ApiTokenAccess(ListMyTicketsTest::OMARS_TOKEN, new OrganizationId(ListMyTicketsTest::ACME_FOODS), ['read'], new \DateTimeImmutable('2027-02-09 00:30:00 UTC'));
        $this->userId = new UserId(ListMyTicketsTest::OMAR);
        $this->myTickets = new MyTickets([], false);
    }

    public function tool(): ListMyTickets
    {
        return new ListMyTickets($this, $this, $this, $this, $this, $this, new PlainNowNext(new ReviewerChoice(), new TesterChoice()));
    }

    public function access(): ?ApiTokenAccess
    {
        return $this->access;
    }

    public function id(): ?UserId
    {
        return $this->userId;
    }

    public function idOf(mixed $tokenUser): ?UserId
    {
        return null;
    }

    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
    {
        $this->visibilitiesAskedFor[] = [$organizationId->toString(), $viewerId->toString()];

        return TicketVisibility::for($organizationId, $viewerId, [new TeamId(ListMyTicketsTest::SHOP_TEAM)], false, $viewerId);
    }

    public function myTicketsOf(TicketViewer $viewer, int $limit): MyTickets
    {
        $this->myTicketsAskedFor[] = [$viewer, $limit];

        return $this->myTickets;
    }

    public function stepsFor(TeamId $teamId): array
    {
        $this->teamsAskedForSteps[] = $teamId->toString();

        return ListMyTicketsTest::stepsOf($teamId);
    }

    public function moveTargetsFor(TeamId $teamId): array
    {
        throw new \LogicException('list_my_tickets never reads move targets.');
    }

    public function listedSteps(): array
    {
        ++$this->listedStepReads;

        return ListMyTicketsTest::listedSteps();
    }

    public function groupOf(string $stepId): ?string
    {
        throw new \LogicException('list_my_tickets never reads one step\'s group.');
    }

    public function idByEmail(string $email): ?UserId
    {
        throw new \LogicException('list_my_tickets never looks a user up by email.');
    }

    public function byIds(array $userIds): array
    {
        $wanted = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->namesAskedFor[] = $wanted;
        $found = [];
        foreach (ListMyTicketsTest::knownUsers() as $user) {
            if (\in_array($user->id->toString(), $wanted, true)) {
                $found[$user->id->toString()] = $user;
            }
        }
        ksort($found);

        return $found;
    }

    public function timeZonesOf(array $userIds): array
    {
        throw new \LogicException('list_my_tickets never reads time zones.');
    }

    public function emailsOf(array $userIds): array
    {
        throw new \LogicException('list_my_tickets never reads email addresses.');
    }
}
