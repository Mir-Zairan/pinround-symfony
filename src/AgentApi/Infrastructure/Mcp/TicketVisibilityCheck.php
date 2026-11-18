<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Identity\Api\CurrentApiToken;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * TicketPermission VIEW for a tool whose voter grants the owner of an unshared draft without reading the ticket, as
 * RoundPermission and MediaPermission UPLOAD do: set_verdict, create_upload and add_screenshot ask it before they look
 * the draft up, so a ticket the caller may no longer see is not found, as get_round_notes answers it. Holds no state
 * (ADR-0008).
 */
final readonly class TicketVisibilityCheck
{
    public function __construct(
        private CurrentApiToken $currentApiToken,
        private TicketTurnQueries $ticketTurnQueries,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Reads the ticket in one query.
     *
     * @throws ToolRefusal     not found, for a ticket Ticketing does not read or TicketPermission VIEW denies
     * @throws \LogicException when the request has no token, or Ticketing cannot read the ticket's team
     */
    public function refuseUnlessVisible(TicketId $ticketId): void
    {
        $ticket = $this->ticketTurnQueries->factsOf($ticketId) ?? throw ToolRefusal::notFound();
        if (!$this->authorizationChecker->isGranted(TicketPermission::VIEW, TicketVisibilityFacts::ofSnapshot($this->callerOrganizationId(), $ticket))) {
            throw ToolRefusal::notFound();
        }
    }

    /**
     * @throws \LogicException when the request has no personal access token, which the api_token firewall never lets
     *                         through
     */
    public function callerOrganizationId(): OrganizationId
    {
        return ($this->currentApiToken->access() ?? throw new \LogicException('An agent tool ran without a personal access token.'))->organizationId;
    }
}
