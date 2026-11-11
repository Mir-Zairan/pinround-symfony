<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\UI\Voter\TicketVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The ticket page's gate, TicketPermission VIEW, decided by the app's access decision manager with all its voters: the
 * outside reporter sees the team-only ticket and the outsider with no link does not; the triager sees the team-only
 * ticket with no team and the manager without a team role does not.
 */
final class TicketVoterViewLeakTest extends VisibilityLeakCast
{
    #[Test]
    #[DataProvider('viewers')]
    public function theViewerMayViewTheTeamOnlyTicketOnlyWhenTheCastSays(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertSame($seesTeamOnly, self::decidedView($viewer, self::teamOnlyTicket()));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theViewerMayViewTheNoTeamTicketOnlyWhenTheCastSays(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertSame($seesNoTeamTicket, self::decidedView($viewer, self::noTeamTicket()));
    }

    private static function decidedView(string $viewer, TicketVisibilityFacts $ticket): bool
    {
        $accessDecision = new AccessDecision();
        $isGranted = self::asViewer($viewer, static fn (TokenInterface $token): bool => self::accessDecisionManager()->decide($token, [TicketPermission::VIEW], $ticket, $accessDecision));

        $decidingVoters = array_values(array_map(
            static fn (Vote $vote): string => $vote->voter,
            array_filter($accessDecision->votes, static fn (Vote $vote): bool => VoterInterface::ACCESS_ABSTAIN !== $vote->result),
        ));
        self::assertSame([TicketVoter::class], $decidingVoters, 'Only TicketVoter decides VIEW on a ticket.');

        return $isGranted;
    }

    private static function accessDecisionManager(): AccessDecisionManagerInterface
    {
        return self::getContainer()->get('security.access.decision_manager');
    }
}
