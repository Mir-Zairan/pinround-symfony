<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Api\MutePermission;
use App\Notification\UI\Voter\MuteVoter;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\UI\Voter\TicketVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * MutePermission MUTE decided by the app's access decision manager with all its voters: whoever may see a ticket may
 * mute it, and nobody else, so a mute never tells a person a ticket they may not see exists.
 */
final class MuteVoterTest extends VisibilityLeakCast
{
    #[Test]
    #[DataProvider('viewers')]
    public function theViewerMayMuteTheTeamOnlyTicketOnlyWhenTheyMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertSame($seesTeamOnly, self::decidedMute($viewer, self::teamOnlyTicket()));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theViewerMayMuteTheNoTeamTicketOnlyWhenTheyMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertSame($seesNoTeamTicket, self::decidedMute($viewer, self::noTeamTicket()));
    }

    #[Test]
    public function nobodyLoggedInMayMuteATicket(): void
    {
        $accessDecision = new AccessDecision();
        $isGranted = self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): bool => self::accessDecisionManager()->decide(new NullToken(), [MutePermission::MUTE], self::teamOnlyTicket(), $accessDecision));

        self::assertFalse($isGranted);
        self::assertSame([MuteVoter::class], self::decidingVotersOf($accessDecision), 'MuteVoter itself denies a NullToken.');
    }

    private static function decidedMute(string $viewer, TicketVisibilityFacts $ticket): bool
    {
        $accessDecision = new AccessDecision();
        $isGranted = self::asViewer($viewer, static fn (TokenInterface $token): bool => self::accessDecisionManager()->decide($token, [MutePermission::MUTE], $ticket, $accessDecision));

        // The VIEW that MuteVoter asks for records its votes in the same decision, and after them comes MUTE's own.
        $decidingVoters = self::decidingVotersOf($accessDecision);
        self::assertSame(MuteVoter::class, end($decidingVoters), 'MuteVoter casts the last vote, the one on MUTE.');
        self::assertSame([MuteVoter::class], array_values(array_diff($decidingVoters, [TicketVoter::class])), 'Only MuteVoter decides MUTE on a ticket; TicketVoter answers only the VIEW it asks.');

        return $isGranted;
    }

    /**
     * @return list<string>
     */
    private static function decidingVotersOf(AccessDecision $accessDecision): array
    {
        return array_values(array_map(
            static fn (Vote $vote): string => $vote->voter,
            array_filter($accessDecision->votes, static fn (Vote $vote): bool => VoterInterface::ACCESS_ABSTAIN !== $vote->result),
        ));
    }

    private static function accessDecisionManager(): AccessDecisionManagerInterface
    {
        return self::getContainer()->get('security.access.decision_manager');
    }
}
