<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\FindTickets;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\NextTicketKey;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketMatches;
use App\Ticketing\Api\TicketSearchQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;

/**
 * The collaborators of FindTickets, each recording what it was asked. Omar of Acme Foods calls with a read token.
 * ProjectQueries knows SHOP only and matches its key exactly, as the real one does; TicketSearchQueries refuses what
 * the real one refuses, before it records the call.
 */
final class FindTicketsFakes implements CurrentApiToken, CurrentUser, TicketVisibilities, ProjectQueries, TicketSearchQueries
{
    public const string SHOP_PROJECT = '019a5f00-0000-7000-8000-00000000e8d1';

    public TicketMatches $matches;
    /** @var list<string> */
    public array $projectKeysAskedFor = [];
    /** @var list<array{string, string}> */
    public array $visibilitiesAskedFor = [];
    /** @var list<array{string, ?string, int}> */
    public array $searchesAskedFor = [];

    public function __construct()
    {
        $this->matches = new TicketMatches([], false);
    }

    public function tool(): FindTickets
    {
        return new FindTickets($this, $this, $this, $this, $this);
    }

    public function queryCount(): int
    {
        return \count($this->projectKeysAskedFor) + \count($this->visibilitiesAskedFor) + \count($this->searchesAskedFor);
    }

    public function access(): ?ApiTokenAccess
    {
        return new ApiTokenAccess(FindTicketsTest::OMARS_TOKEN, new OrganizationId(FindTicketsTest::ACME_FOODS), ['read'], new \DateTimeImmutable('2027-02-09 00:30:00 UTC'));
    }

    public function id(): ?UserId
    {
        return new UserId(FindTicketsTest::OMAR);
    }

    public function idOf(mixed $tokenUser): ?UserId
    {
        return null;
    }

    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
    {
        $this->visibilitiesAskedFor[] = [$organizationId->toString(), $viewerId->toString()];

        return TicketVisibility::for($organizationId, $viewerId, [], false, null);
    }

    public function openMatchesOf(TicketVisibility $viewer, string $searchText, ?string $projectId, int $limit): TicketMatches
    {
        if (!ListFilter::isSearchText($searchText) || (null !== $projectId && !ListFilter::isCanonicalUuid($projectId)) || $limit < 1 || $limit > self::MAX_MATCHES) {
            throw new \InvalidArgumentException('The search takes no such text, project or limit.');
        }
        $this->searchesAskedFor[] = [$searchText, $projectId, $limit];

        return $this->matches;
    }

    public function projectByKey(string $key): ?ProjectChoice
    {
        $this->projectKeysAskedFor[] = $key;

        return 'SHOP' === $key ? new ProjectChoice(self::SHOP_PROJECT, 'SHOP', 'Shop', new TeamId(FindTicketsTest::SHOP_TEAM)) : null;
    }

    public function projectsOf(TeamId $teamId, UserId $viewerId): array
    {
        throw new \LogicException('find_tickets never lists a team\'s projects.');
    }

    public function unlinkedProjectChoices(int $limit): array
    {
        throw new \LogicException('find_tickets never lists unlinked projects.');
    }

    public function projectChoicesOfTeams(array $teamIds): array
    {
        throw new \LogicException('find_tickets never lists the teams\' projects.');
    }

    public function nextTicketKeyOf(string $projectId): ?NextTicketKey
    {
        throw new \LogicException('find_tickets never reads the next ticket key.');
    }
}
