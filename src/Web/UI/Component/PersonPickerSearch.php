<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use App\Insight\Api\WaitingTurnCounts;
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
use App\Web\UI\TicketViewers;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The person picker's search and rows (PersonPicker; prototype "Person picker"; frontend.md "Live Components"): the
 * lead and members of $team whose first name holds the search, then by name, PAGE_SIZE at most, so a team of thousands
 * never reaches the browser whole. In Filter mode the most open tickets assigned to them come first (AssigneeTicketCounts),
 * in Assign mode the fewest open tickets of $team waiting on them (WaitingTurnCounts); both count only what the viewer
 * may see in $projectId. It reads nothing until open() runs, so the page that renders it pays no query; each live
 * request first asks OrganizationPermission::VIEW and TeamPermission::VIEW.
 *
 * No prop takes updates from the parent: a parent's re-render would otherwise send this component a request of its
 * own, with its reads, on every keystroke of the parent's search. The pressed person comes with open() instead.
 * In Filter mode each row is a button that emits $pickedEvent up to the parent component with the person's id; in
 * Assign mode it submits the form $pickForm with the person's id as $pickField.
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
    public const string PRESSED_PERSON_NOT_VALID = 'That person is not valid.';

    #[LiveProp]
    public PersonPickerMode $mode = PersonPickerMode::Filter;

    /** The team whose lead and members are listed, a canonical uuid. */
    #[LiveProp]
    public string $team = '';

    /** The project whose tickets are counted, as ListFilter's projectId; empty for every project. */
    #[LiveProp]
    public string $projectId = '';

    /** The live event a row emits up with the picked person's id. */
    #[LiveProp]
    public string $pickedEvent = '';

    /** The id of the form, outside the dialog, that an Assign row submits; empty in Filter mode. */
    #[LiveProp]
    public string $pickForm = '';

    /** The field of $pickForm an Assign row sends the person's id as; empty in Filter mode. */
    #[LiveProp]
    public string $pickField = '';

    /** The person the page filters by as open() was last called, pressed in the list; empty for none. */
    #[LiveProp]
    public string $pressedPerson = '';

    #[LiveProp]
    public bool $isOpened = false;

    /** The search as typed. */
    #[LiveProp(writable: true)]
    public string $search = '';

    /** @var array{rows: list<array{personId: string, name: string, count: int, isPressed: bool}>, matchCount: int}|null */
    private ?array $shownPeople = null;

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TeamPeopleQueries $teamPeopleQueries,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly AssigneeTicketCounts $assigneeTicketCounts,
        private readonly WaitingTurnCounts $waitingTurnCounts,
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

    /**
     * @param string $pressedPerson the canonical uuid of the person the page filters by now, or empty for none
     *
     * @throws BadRequestHttpException when $pressedPerson is neither, and the picker stays as it was
     */
    #[LiveAction]
    public function open(#[LiveArg] string $pressedPerson = ''): void
    {
        if ('' !== $pressedPerson && !ListFilter::isCanonicalUuid($pressedPerson)) {
            throw new BadRequestHttpException(self::PRESSED_PERSON_NOT_VALID);
        }
        $this->pressedPerson = $pressedPerson;
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
     * @return array{rows: list<array{personId: string, name: string, count: int, isPressed: bool}>, matchCount: int}
     *
     * @throws \LogicException in Assign mode when the waiting counts leave out a person they were asked about
     */
    public function shownPeople(): array
    {
        return $this->shownPeople ??= $this->readShownPeople();
    }

    /**
     * @return array{rows: list<array{personId: string, name: string, count: int, isPressed: bool}>, matchCount: int}
     */
    private function readShownPeople(): array
    {
        $nobody = ['rows' => [], 'matchCount' => 0];
        if (!$this->isOpened || null !== $this->searchRefusal()) {
            return $nobody;
        }
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor) {
            return $nobody;
        }

        $searchText = mb_strtolower(trim($this->search), 'UTF-8');
        /** @var list<array{person: PossibleAssignee, personId: string, sortName: string}> $matchingPeople */
        $matchingPeople = [];
        foreach ($this->teamPeopleQueries->namedPossibleAssigneesOf(new TeamId($this->team)) as $person) {
            $sortName = mb_strtolower($person->firstName, 'UTF-8');
            if ('' === $searchText || str_contains($sortName, $searchText)) {
                $matchingPeople[] = ['person' => $person, 'personId' => $person->userId->toString(), 'sortName' => $sortName];
            }
        }
        if ([] === $matchingPeople) {
            return $nobody;
        }

        $visibility = $this->ticketVisibilities->of($actor->organizationId(), $actor->userId());
        $userIds = array_map(static fn (array $match) => $match['person']->userId, $matchingPeople);
        $projectId = '' === $this->projectId ? null : $this->projectId;
        if (PersonPickerMode::Assign === $this->mode) {
            $waitingCounts = $this->waitingTurnCounts->waitingCountsOf(
                TicketViewers::of($visibility),
                $userIds,
                new TeamId($this->team),
                $projectId,
            );
            $sortedPeople = array_map(static fn (array $match): array => [
                ...$match,
                'count' => ($waitingCounts[$match['personId']] ?? throw new \LogicException('The waiting counts leave out a person of the team.'))->tickets,
            ], $matchingPeople);
            usort($sortedPeople, static fn (array $one, array $other): int => [$one['count'], $one['sortName'], $one['personId']]
                <=> [$other['count'], $other['sortName'], $other['personId']]);
        } else {
            $openCounts = $this->assigneeTicketCounts->openTicketsAssignedTo($visibility, $userIds, $projectId);
            $sortedPeople = array_map(static fn (array $match): array => [...$match, 'count' => $openCounts[$match['personId']] ?? 0], $matchingPeople);
            usort($sortedPeople, static fn (array $one, array $other): int => [$other['count'], $one['sortName'], $one['personId']]
                <=> [$one['count'], $other['sortName'], $other['personId']]);
        }

        return [
            'rows' => array_map(fn (array $match): array => [
                'personId' => $match['personId'],
                'name' => $match['person']->firstName,
                'count' => $match['count'],
                'isPressed' => $this->pressedPerson === $match['personId'],
            ], \array_slice($sortedPeople, 0, self::PAGE_SIZE)),
            'matchCount' => \count($sortedPeople),
        ];
    }
}
