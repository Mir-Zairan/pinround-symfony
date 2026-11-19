<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Voter;

use App\Identity\Api\LoginAssurance;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Review\Api\ReportDraftPermission;
use App\Review\Api\ReportDraftView;
use App\Review\UI\Voter\ReportDraftVoter;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Mia owns the draft. Lena leads Shop, Sara is an Admin; all of Acme Foods. A login on the api_token firewall stands for
 * a personal access token: the real LoginAssurance tells it by the token's class, which this double cannot build
 * around an in-memory user.
 */
final class ReportDraftVoterTest extends TestCase
{
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8101';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8102';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000f8103';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f810a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f810b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000f81d1';

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributes(): iterable
    {
        yield 'view' => [ReportDraftPermission::VIEW];
        yield 'edit' => [ReportDraftPermission::EDIT];
        yield 'send' => [ReportDraftPermission::SEND];
    }

    #[Test]
    #[DataProvider('attributes')]
    public function theOwnerInABrowserSessionIsGrantedAndALeadAndAnAdminAreNot(string $attribute): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::sessionOf(self::MIA), self::miasDraft(), [$attribute]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::sessionOf(self::LENA), self::miasDraft(), [$attribute]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::sessionOf(self::SARA), self::miasDraft(), [$attribute]));
    }

    /**
     * ADR-0039: an agent may prepare and change a draft with the owner's token, but only the person's click sends it.
     */
    #[Test]
    public function theOwnersTokenMayViewAndEditButNeverSendAndSaysWhy(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $token = self::apiTokenOf(self::MIA);
        $vote = new Vote();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, self::miasDraft(), [ReportDraftPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, self::miasDraft(), [ReportDraftPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, self::miasDraft(), [ReportDraftPermission::SEND], $vote));
        self::assertSame([ReportDraftVoter::TOKEN_REFUSAL_REASON], $vote->reasons);
    }

    #[Test]
    public function nobodyLoggedInAnotherTenantAndNoTenantAreRefused(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::ACME_FOODS)->vote(new NullToken(), self::miasDraft(), [ReportDraftPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::BETA_BAKERY)->vote(self::sessionOf(self::MIA), self::miasDraft(), [ReportDraftPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(null)->vote(self::sessionOf(self::MIA), self::miasDraft(), [ReportDraftPermission::VIEW]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::sessionOf(self::MIA), self::miasDraft(), ['ROUND_VIEW']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::sessionOf(self::MIA), null, [ReportDraftPermission::VIEW]));
    }

    private static function miasDraft(): ReportDraftView
    {
        $at = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        return new ReportDraftView('0197a3c0-0000-7000-8000-0000000f8111', new UserId(self::MIA), 'SHOP', 'Total is doubled', '', [], 1, $at, $at);
    }

    private function voterUnder(?string $tenantId): ReportDraftVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);

        return new ReportDraftVoter(new FixedCurrentActor([
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [], [])),
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
        ]), $tenantContext, self::loginAssurance());
    }

    private static function sessionOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function apiTokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'api_token');
    }

    private static function loginAssurance(): LoginAssurance
    {
        return new readonly class implements LoginAssurance {
            public function isPasswordSession(mixed $token): bool
            {
                return $token instanceof UsernamePasswordToken && 'main' === $token->getFirewallName();
            }

            public function isTwoStepLoginOn(mixed $token): bool
            {
                return false;
            }

            public function hasConfirmedTwoStepLoginWithin(mixed $token, int $seconds): bool
            {
                return false;
            }

            public function isTokenAuthenticated(mixed $token): bool
            {
                return $token instanceof UsernamePasswordToken && 'api_token' === $token->getFirewallName();
            }
        };
    }
}
