<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What a page needs to render the ticket page's move-on verb forms: the ticket, the version the move must find, and the
 * id of the form token the move checks.
 */
final readonly class MoveOnForm
{
    /**
     * @param int              $version     the ticket's version as read, so a move made meanwhile is refused
     * @param string           $csrfTokenId for csrf_token() in the template
     * @param list<MoveOnVerb> $verbs       never empty, in the policy's order
     */
    public function __construct(
        public string $ticketKey,
        public int $version,
        public string $csrfTokenId,
        public array $verbs,
    ) {
    }
}
