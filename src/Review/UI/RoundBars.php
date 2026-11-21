<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Review\Application\OpenSharedRoundQueries;

/**
 * Builds the round bar for one viewer from the ticket's lowest open shared round, so a private draft never reaches it,
 * not even its owner's, as no unshared draft is a hand-off round. Reads one round row, then at most one query for whose
 * turn it is and one for a name the ticket's page did not read, whatever the number of rounds, screenshots and pins.
 */
final readonly class RoundBars
{
    public function __construct(
        private OpenSharedRoundQueries $openSharedRoundQueries,
        private RoundReadyFor $roundReadyFor,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @return RoundBarView|null null while no shared round of the ticket is open
     */
    public function forTicket(VisibleTicket $visible): ?RoundBarView
    {
        $openRound = $this->openSharedRoundQueries->lowestOpenSharedRoundOf($visible->ticket->id);
        if (null === $openRound) {
            return null;
        }

        return new RoundBarView($openRound->number, $this->roundReadyFor->readyForLineOf($visible, $openRound->kind, $openRound->ownerId));
    }
}
