<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What the tickets list shows: the viewer's tickets, of one project or of every project, with or without Done.
 */
final readonly class ListFilter
{
    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param TicketVisibility $visibility the viewer's predicate, from RoleTicketVisibilities::ofActor in a request
     * @param string|null      $projectId  a project's uuid, as the caller may not build Ticketing's ProjectId; null
     *                                     for every project
     * @param bool             $showsDone  true to add the Done group
     *
     * @throws \InvalidArgumentException when the project id is not a canonical uuid
     */
    public function __construct(
        public TicketVisibility $visibility,
        public ?string $projectId = null,
        public bool $showsDone = false,
    ) {
        if (null !== $projectId && 1 !== preg_match(self::CANONICAL_UUID, $projectId)) {
            throw new \InvalidArgumentException('A list filter\'s project id must be a canonical lowercase uuid.');
        }
    }
}
