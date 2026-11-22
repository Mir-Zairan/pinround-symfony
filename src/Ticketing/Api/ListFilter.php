<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What the tickets list shows: the viewer's tickets, of one project or of every project, with or without Done, only
 * those assigned to one person when there is one (with the tickets waiting on that person when the list asks for
 * them), and only those that match a search when there is one.
 */
final readonly class ListFilter
{
    /** In characters; the list page refuses a longer search before any query. */
    public const int SEARCH_TEXT_MAX_LENGTH = 200;

    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param TicketVisibility $visibility    the viewer's predicate, from RoleTicketVisibilities::ofActor in a request
     * @param string|null      $projectId     a project's uuid, as the caller may not build Ticketing's ProjectId; null
     *                                        for every project
     * @param bool             $showsDone     true to add the Done group
     * @param string|null      $searchText    the search as the person typed it, already trimmed: valid UTF-8 without
     *                                        NUL, 1 to SEARCH_TEXT_MAX_LENGTH characters; null for no search
     * @param string|null      $assigneeId    a person's uuid: only the tickets assigned to them; null for any assignee
     *                                        or none
     * @param list<string>     $alsoTicketIds with an assignee, tickets shown as well though assigned to someone
     *                                        else: the ones waiting on the viewer's turn, for "Me"; canonical uuids
     *
     * @throws \InvalidArgumentException when the project, assignee or a ticket id is not a canonical uuid, tickets come
     *                                   without an assignee, or the search text is not one the list page lets through
     */
    public function __construct(
        public TicketVisibility $visibility,
        public ?string $projectId = null,
        public bool $showsDone = false,
        public ?string $searchText = null,
        public ?string $assigneeId = null,
        public array $alsoTicketIds = [],
    ) {
        if (null !== $projectId && !self::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('A list filter\'s project id must be a canonical lowercase uuid.');
        }
        if (null !== $assigneeId && !self::isCanonicalUuid($assigneeId)) {
            throw new \InvalidArgumentException('A list filter\'s assignee id must be a canonical lowercase uuid.');
        }
        if ([] !== $alsoTicketIds && null === $assigneeId) {
            throw new \InvalidArgumentException('A list filter takes tickets besides an assignee\'s only with an assignee.');
        }
        foreach ($alsoTicketIds as $ticketId) {
            if (!self::isCanonicalUuid($ticketId)) {
                throw new \InvalidArgumentException('A list filter\'s ticket ids must be canonical lowercase uuids.');
            }
        }
        if (null !== $searchText && !self::isSearchText($searchText)) {
            throw new \InvalidArgumentException('A list filter\'s search text must be trimmed, valid UTF-8 without NUL, and 1 to 200 characters.');
        }
    }

    /**
     * True for the one spelling of a uuid that ids take here: lowercase, hyphenated, nothing around it.
     */
    public static function isCanonicalUuid(string $value): bool
    {
        return 1 === preg_match(self::CANONICAL_UUID, $value);
    }

    /**
     * True for a search the list and TicketSearchQueries take: not empty, trimmed, valid UTF-8 without NUL, and at most
     * SEARCH_TEXT_MAX_LENGTH characters.
     */
    public static function isSearchText(string $searchText): bool
    {
        return '' !== $searchText
            && trim($searchText) === $searchText
            && mb_check_encoding($searchText, 'UTF-8')
            && !str_contains($searchText, "\0")
            && mb_strlen($searchText, 'UTF-8') <= self::SEARCH_TEXT_MAX_LENGTH;
    }
}
