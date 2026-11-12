<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\UI\TicketListController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The tickets list's toolbar (FR-TKT-05, FR-TKT-06, FR-TKT-07; frontend.md "Live Components"): the search field, the
 * "Assigned to" chips and "Show done". It owns the list's filters; after each change it re-renders and sends
 * LIST_CHANGED_EVENT with the list's new URL, which the page's ticket-list controller loads into the list frame and
 * the address bar. Every chip is a link to that URL and the search is a GET form, so the toolbar works without script.
 *
 * The people are the lead and members of the page's team, $team (viewers cannot be assignees): "Me" for a lead
 * or member of it, then each other one's first name while the team has at most MAX_NAMED_PEOPLE, else a "Person ▾"
 * chip that opens the person picker (Web's PersonPickerSearch in a Dialog), whose rows emit PERSON_PICKED_EVENT. The
 * picker reads its people and counts in its own live request once opened, so the page pays nothing for it. The team's
 * people and names are one cached read (TeamPeopleQueries): no query when warm, two after a change of the team. A
 * person filter set by a chip or the picker must be a lead or member of the team.
 */
#[AsLiveComponent(name: 'TicketFilters', template: '@Ticketing/components/TicketFilters.html.twig', method: 'post')]
final class TicketFilters
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const string LIST_CHANGED_EVENT = 'ticket-filters:changed';
    public const int MAX_NAMED_PEOPLE = 4;
    public const string SEARCH_PLACEHOLDER = 'Search by key or words in the title';
    public const string PEOPLE_LABEL = 'Assigned to';
    public const string PERSON_PICKER_LABEL = 'Person ▾';
    public const string PERSON_PICKER_TITLE = 'Show tickets of…';
    public const string PERSON_PICKER_SUB = 'Sorted by open tickets assigned to them.';
    /** The live event the person picker's rows emit up, with the picked person's id. */
    public const string PERSON_PICKED_EVENT = 'personPicked';
    public const string NO_SUCH_PERSON = 'No one in this team has that id.';

    /** A project key, as in TicketListController::PROJECT_QUERY; empty for every project. */
    #[LiveProp]
    public string $project = '';

    /** The assignee's canonical uuid; empty for everyone. */
    #[LiveProp]
    public string $person = '';

    #[LiveProp]
    public bool $showsDone = false;

    /** The team whose people the chips show, as TicketListController::railTeamOf chose it; empty for none. */
    #[LiveProp]
    public string $team = '';

    /** The team picked with TicketListController::TEAM_QUERY, kept in the list's URLs; empty when none was. */
    #[LiveProp]
    public string $pickedTeam = '';

    /** The search as typed, untrimmed; the list URL carries it trimmed. */
    #[LiveProp(writable: true)]
    public string $q = '';

    /** True after a chip, so the page adds a history entry; typing in the search only replaces the current one. */
    private bool $addsHistoryEntry = false;

    /** @var array{viewerChip: array<string, mixed>|null, personChips: list<array<string, mixed>>, pickerChip: array<string, mixed>|null}|null */
    private ?array $peopleChips = null;

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TeamPeopleQueries $teamPeopleQueries,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Every live request asks the list's own permission before an action runs (ADR-0013).
     *
     * @throws AccessDeniedException when the person may not open the tickets list in the request's organization
     */
    #[PostHydrate]
    public function denyUnlessListIsOpen(): void
    {
        $organizationId = $this->tenantContext->get();
        if (null === $organizationId || !$this->security->isGranted(OrganizationPermission::VIEW, $organizationId)) {
            throw new AccessDeniedException('The tickets list is not open to this person here.');
        }
    }

    /**
     * A chip's action, and the person picker's PERSON_PICKED_EVENT.
     *
     * @param string $person the canonical uuid of a lead or member of $team, or empty for everyone
     *
     * @throws BadRequestHttpException when $person is not a canonical uuid
     * @throws NotFoundHttpException   when $person is no lead or member of $team
     */
    #[LiveAction]
    #[LiveListener(self::PERSON_PICKED_EVENT)]
    public function filterByPerson(#[LiveArg] string $person): void
    {
        if ('' !== $person && !ListFilter::isCanonicalUuid($person)) {
            throw new BadRequestHttpException('That person is not valid.');
        }
        if ('' !== $person && !$this->isPersonOfTheTeam($person)) {
            throw new NotFoundHttpException(self::NO_SUCH_PERSON);
        }
        $this->person = $person;
        $this->addsHistoryEntry = true;
    }

    #[LiveAction]
    public function toggleDone(): void
    {
        $this->showsDone = !$this->showsDone;
        $this->addsHistoryEntry = true;
    }

    #[PreReRender]
    public function sendListUrl(): void
    {
        if (null !== $this->searchRefusal()) {
            return;
        }
        $this->dispatchBrowserEvent(self::LIST_CHANGED_EVENT, [
            'url' => $this->listUrl(),
            'addsHistoryEntry' => $this->addsHistoryEntry,
        ]);
    }

    /**
     * @return string|null why the list would refuse the search, shown under the field instead of reloading the list
     */
    public function searchRefusal(): ?string
    {
        return TicketListController::searchRefusalOf(trim($this->q));
    }

    /**
     * @param array<string, string|null> $changes query values to set; null drops one
     */
    public function listUrl(array $changes = []): string
    {
        $query = [
            TicketListController::TEAM_QUERY => '' === $this->pickedTeam ? null : $this->pickedTeam,
            TicketListController::PROJECT_QUERY => '' === $this->project ? null : $this->project,
            TicketListController::PERSON_QUERY => '' === $this->person ? null : $this->person,
            TicketListController::DONE_QUERY => $this->showsDone ? '1' : null,
            TicketListController::SEARCH_QUERY => '' === trim($this->q) || null !== $this->searchRefusal() ? null : trim($this->q),
            ...$changes,
        ];

        return $this->urlGenerator->generate(TicketListController::ROUTE, array_filter($query, static fn (?string $value): bool => null !== $value));
    }

    /**
     * @return array<string, string> the filters the search form sends beside q
     */
    public function searchFormFields(): array
    {
        return array_filter([
            TicketListController::TEAM_QUERY => $this->pickedTeam,
            TicketListController::PROJECT_QUERY => $this->project,
            TicketListController::PERSON_QUERY => $this->person,
            TicketListController::DONE_QUERY => $this->showsDone ? '1' : '',
        ], static fn (string $value): bool => '' !== $value);
    }

    /**
     * Each chip with its label, person id, URL and whether it is pressed; read once per render.
     *
     * @return array{viewerChip: array<string, mixed>|null, personChips: list<array<string, mixed>>, pickerChip: array<string, mixed>|null}
     */
    public function peopleChips(): array
    {
        if (null === $this->peopleChips) {
            $this->peopleChips = $this->readPeopleChips();
            // A person from the URL whom no chip names still shows as the active filter, on the picker chip.
            $isPersonOnAChip = [] !== array_filter(
                [$this->peopleChips['viewerChip'], ...$this->peopleChips['personChips']],
                static fn (?array $chip): bool => null !== $chip && true === $chip['isPressed'],
            );
            if ('' !== $this->person && !$isPersonOnAChip && null === $this->peopleChips['pickerChip']) {
                $this->peopleChips['pickerChip'] = ['label' => self::PERSON_PICKER_LABEL, 'isPressed' => true, 'opensPicker' => false];
            }
        }

        return $this->peopleChips;
    }

    /**
     * @return array{viewerChip: array<string, mixed>|null, personChips: list<array<string, mixed>>, pickerChip: array<string, mixed>|null}
     */
    private function readPeopleChips(): array
    {
        $chips = ['viewerChip' => null, 'personChips' => [], 'pickerChip' => null];
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor || !ListFilter::isCanonicalUuid($this->team)) {
            return $chips;
        }
        $teamId = new TeamId($this->team);

        $teamPeople = $this->teamPeopleQueries->namedPossibleAssigneesOf($teamId);
        $viewerId = $actor->userId();
        $otherPeople = [];
        foreach ($teamPeople as $person) {
            if ($person->userId->equals($viewerId)) {
                $chips['viewerChip'] = $this->chipOf('Me', $viewerId);
            } else {
                $otherPeople[] = $person;
            }
        }
        if (\count($teamPeople) <= self::MAX_NAMED_PEOPLE) {
            foreach ($otherPeople as $person) {
                $chips['personChips'][] = $this->chipOf($person->firstName, $person->userId);
            }

            return $chips;
        }

        $pickedName = $this->pickedPersonAmong($otherPeople)?->firstName;
        $chips['pickerChip'] = [
            'label' => $pickedName ?? self::PERSON_PICKER_LABEL,
            'isPressed' => '' !== $this->person && !($chips['viewerChip']['isPressed'] ?? false),
            'opensPicker' => true,
        ];

        return $chips;
    }

    /**
     * From the team's cached people, so no query when warm; users are global, so an id alone never makes a person of it.
     */
    private function isPersonOfTheTeam(string $person): bool
    {
        if (!ListFilter::isCanonicalUuid($this->team)) {
            return false;
        }
        foreach ($this->teamPeopleQueries->namedPossibleAssigneesOf(new TeamId($this->team)) as $teamPerson) {
            if ($person === $teamPerson->userId->toString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{label: string, personId: string, url: string, isPressed: bool}
     */
    private function chipOf(string $label, UserId $userId): array
    {
        return [
            'label' => $label,
            'personId' => $userId->toString(),
            'url' => $this->listUrl([TicketListController::PERSON_QUERY => $userId->toString()]),
            'isPressed' => $this->person === $userId->toString(),
        ];
    }

    /**
     * Only a person of the team is named: users are global, so an id from the URL alone is never named.
     *
     * @param list<PossibleAssignee> $otherPeople
     */
    private function pickedPersonAmong(array $otherPeople): ?PossibleAssignee
    {
        foreach ($otherPeople as $person) {
            if ($this->person === $person->userId->toString()) {
                return $person;
            }
        }

        return null;
    }
}
