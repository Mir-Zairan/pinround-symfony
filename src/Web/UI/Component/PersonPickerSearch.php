<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibilities;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The person picker's search and rows, in filter mode (prototype "Person picker"; frontend.md "Live Components"): the
 * lead and members of $team whose first name holds the search, most open tickets assigned to them first (counted as
 * the viewer may see them, AssigneeTicketCounts), then by name, PAGE_SIZE at most, so a team of thousands never
 * reaches the browser whole. It reads nothing until open() runs, so the page that renders it pays no query; each live
 * request first asks OrganizationPermission::VIEW and TeamPermission::VIEW.
 *
 * Each row is a link to $pickUrl with the person in $pickQuery, so a pick is a plain link; with script it emits
 * $pickedEvent up to the parent component with the person's id instead.
 */
#[AsLiveComponent(name: 'PersonPickerSearch', template: '@Web/components/PersonPickerSearch.html.twig', method: 'post')]
final class PersonPickerSearch
{
    use DefaultActionTrait;

    public const int PAGE_SIZE = 20;
    public const int SEARCH_MAX_LENGTH = 100;
    public const string SEARCH_LABEL = 'Find a person';
    public const string NO_MATCHES = 'No one in this team matches.';
    public const string SEARCH_TOO_LONG = 'The search is too long. Use '.self::SEARCH_MAX_LENGTH.' characters or fewer.';
    public const string SEARCH_NOT_TEXT = 'The search is not valid text.';
    public const string NOT_OPEN = 'These people are not shown to this person here.';

    /** The team whose lead and members are listed, a canonical uuid. */
    #[LiveProp]
    public string $team = '';

    /** The person the page filters by, pressed in the list; empty for none. */
    #[LiveProp(updateFromParent: true)]
    public string $pressedPerson = '';

    /** The URL a row links to, without the person. */
    #[LiveProp(updateFromParent: true)]
    public string $pickUrl = '';

    /** The query parameter that carries the picked person in $pickUrl. */
    #[LiveProp]
    public string $pickQuery = '';

    /** The live event a row emits up with the picked person's id. */
    #[LiveProp]
    public string $pickedEvent = '';

    #[LiveProp]
    public bool $isOpened = false;

    /** The search as typed. */
    #[LiveProp(writable: true)]
    public string $search = '';

    /** @var array{rows: list<array{personId: string, name: string, openCount: int, url: string, isPressed: bool}>, matchCount: int}|null */
    private ?array $shownPeople = null;

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TeamPeopleQueries $teamPeopleQueries,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly AssigneeTicketCounts $assigneeTicketCounts,
    ) {
    }

    /**
     * @throws AccessDeniedException when the person may not see the team's people in the request's organization
     */
    #[PostHydrate]
    public function denyUnlessTeamIsShown(): void
    {
        $organizationId = $this->tenantContext->get();
        $isShown = null !== $organizationId
            && $this->security->isGranted(OrganizationPermission::VIEW, $organizationId)
            && ListFilter::isCanonicalUuid($this->team)
            && $this->security->isGranted(TeamPermission::VIEW, new TeamId($this->team));
        if (!$isShown) {
            throw new AccessDeniedException(self::NOT_OPEN);
        }
    }

    #[LiveAction]
    public function open(): void
    {
        $this->isOpened = true;
    }

    public function searchRefusal(): ?string
    {
        if (!mb_check_encoding($this->search, 'UTF-8') || str_contains($this->search, "\0")) {
            return self::SEARCH_NOT_TEXT;
        }
        if (mb_strlen($this->search, 'UTF-8') > self::SEARCH_MAX_LENGTH) {
            return self::SEARCH_TOO_LONG;
        }

        return null;
    }

    /**
     * Read once per render: the team's people from TeamPeopleQueries' cache, then one count query for those who match.
     *
     * @return array{rows: list<array{personId: string, name: string, openCount: int, url: string, isPressed: bool}>, matchCount: int}
     */
    public function shownPeople(): array
    {
        return $this->shownPeople ??= $this->readShownPeople();
    }

    /**
     * @return array{rows: list<array{personId: string, name: string, openCount: int, url: string, isPressed: bool}>, matchCount: int}
     */
    private function readShownPeople(): array
    {
        $nobody = ['rows' => [], 'matchCount' => 0];
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (!$this->isOpened || null !== $this->searchRefusal() || null === $actor) {
            return $nobody;
        }

        $searchText = mb_strtolower(trim($this->search), 'UTF-8');
        $matchingPeople = array_values(array_filter(
            $this->teamPeopleQueries->namedPossibleAssigneesOf(new TeamId($this->team)),
            static fn (PossibleAssignee $person): bool => '' === $searchText || str_contains(mb_strtolower($person->firstName, 'UTF-8'), $searchText),
        ));
        if ([] === $matchingPeople) {
            return $nobody;
        }

        $openCounts = $this->assigneeTicketCounts->openTicketsAssignedTo(
            $this->ticketVisibilities->of($actor->organizationId(), $actor->userId()),
            array_map(static fn (PossibleAssignee $person) => $person->userId, $matchingPeople),
        );
        usort($matchingPeople, static fn (PossibleAssignee $one, PossibleAssignee $other): int => [$openCounts[$other->userId->toString()] ?? 0, mb_strtolower($one->firstName, 'UTF-8'), $one->userId->toString()]
            <=> [$openCounts[$one->userId->toString()] ?? 0, mb_strtolower($other->firstName, 'UTF-8'), $other->userId->toString()]);

        return [
            'rows' => array_map(fn (PossibleAssignee $person): array => [
                'personId' => $person->userId->toString(),
                'name' => $person->firstName,
                'openCount' => $openCounts[$person->userId->toString()] ?? 0,
                'url' => $this->pickUrl.(str_contains($this->pickUrl, '?') ? '&' : '?').rawurlencode($this->pickQuery).'='.$person->userId->toString(),
                'isPressed' => $this->pressedPerson === $person->userId->toString(),
            ], \array_slice($matchingPeople, 0, self::PAGE_SIZE)),
            'matchCount' => \count($matchingPeople),
        ];
    }
}
