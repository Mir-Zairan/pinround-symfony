<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Identity\Api\UserQueries;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\UI\TicketListController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
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
 * The people are the lead and members of the viewer's first team by id (viewers cannot be assignees): "Me" for a lead
 * or member of it, then each other one's first name while the team has at most MAX_NAMED_PEOPLE, else a "Person ▾"
 * chip, the person picker's hook. Two queries at most: the team's people, then the names it shows.
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

    /** A project key, as in TicketListController::PROJECT_QUERY; empty for every project. */
    #[LiveProp]
    public string $project = '';

    /** The assignee's canonical uuid; empty for everyone. */
    #[LiveProp]
    public string $person = '';

    #[LiveProp]
    public bool $showsDone = false;

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
        private readonly TeamQueries $teamQueries,
        private readonly UserQueries $userQueries,
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
     * @param string $person a canonical uuid, or empty for everyone
     *
     * @throws BadRequestHttpException when $person is neither
     */
    #[LiveAction]
    public function filterByPerson(#[LiveArg] string $person): void
    {
        if ('' !== $person && !ListFilter::isCanonicalUuid($person)) {
            throw new BadRequestHttpException('That person is not valid.');
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
                $this->peopleChips['pickerChip'] = ['label' => self::PERSON_PICKER_LABEL, 'isPressed' => true];
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
        $teamId = null === $actor ? null : ($actor->roles()->teamIds()[0] ?? null);
        if (null === $actor || null === $teamId) {
            return $chips;
        }

        $teamPeople = $this->teamQueries->possibleAssigneesOf([$teamId])[$teamId->toString()] ?? [];
        $viewerId = $actor->userId();
        if (self::contains($teamPeople, $viewerId)) {
            $chips['viewerChip'] = $this->chipOf('Me', $viewerId);
        }
        $otherPeople = array_values(array_filter($teamPeople, static fn (UserId $userId): bool => !$userId->equals($viewerId)));
        if (\count($teamPeople) <= self::MAX_NAMED_PEOPLE) {
            $names = $this->userQueries->byIds($otherPeople);
            foreach ($otherPeople as $userId) {
                $name = $names[$userId->toString()] ?? null;
                if (null !== $name) {
                    $chips['personChips'][] = $this->chipOf($name->firstName(), $userId);
                }
            }

            return $chips;
        }

        $pickedPerson = $this->pickedPersonAmong($otherPeople);
        $pickedName = null === $pickedPerson ? null : ($this->userQueries->byIds([$pickedPerson])[$pickedPerson->toString()] ?? null)?->firstName();
        $chips['pickerChip'] = ['label' => $pickedName ?? self::PERSON_PICKER_LABEL, 'isPressed' => '' !== $this->person && !($chips['viewerChip']['isPressed'] ?? false)];

        return $chips;
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
     * Only a person of the team is looked up by name: users are global, so an id from the URL alone is never named.
     *
     * @param list<UserId> $otherPeople
     */
    private function pickedPersonAmong(array $otherPeople): ?UserId
    {
        foreach ($otherPeople as $userId) {
            if ($this->person === $userId->toString()) {
                return $userId;
            }
        }

        return null;
    }

    /**
     * @param list<UserId> $userIds
     */
    private static function contains(array $userIds, UserId $userId): bool
    {
        foreach ($userIds as $candidate) {
            if ($candidate->equals($userId)) {
                return true;
            }
        }

        return false;
    }
}
