<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\UI\Voter\TicketCreationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TicketCreationVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000016a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000016a2';
    private const string SHOP_VIEWER = '0197a3c0-0000-7000-8000-0000000016a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000016a4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000016a5';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000016a6';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-0000000016a7';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000160a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000160b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000016d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000016d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000016d3';

    /**
     * Lena leads Shop; Omar is a member of Shop; the Shop viewer is its viewer; Raj is a Manager and a member of Mobile;
     * Sara is an Admin and a viewer of Mobile; neither Raj nor Sara has a role in Shop. Ben has no role; the Beta Bakery
     * admin is an Admin of Beta Bakery and leads Checkout.
     *
     * @return iterable<string, array{string, string, bool}> actor, team, granted
     */
    public static function matrix(): iterable
    {
        foreach (['Lena' => self::LENA, 'Omar' => self::OMAR, 'the Shop viewer' => self::SHOP_VIEWER] as $name => $userId) {
            yield $name.' for Shop, their team' => [$userId, self::SHOP, true];
            yield $name.' for Mobile, a team of their organization with no role in it' => [$userId, self::MOBILE, false];
            yield $name.' for Checkout of Beta Bakery' => [$userId, self::CHECKOUT, false];
        }
        yield 'Raj, a Manager with no role in Shop, for Shop' => [self::RAJ, self::SHOP, false];
        yield 'Raj, a Manager and a member of Mobile, for Mobile' => [self::RAJ, self::MOBILE, true];
        yield 'Sara, an Admin with no role in Shop, for Shop' => [self::SARA, self::SHOP, false];
        yield 'Sara, an Admin and a viewer of Mobile, for Mobile' => [self::SARA, self::MOBILE, true];
        yield 'Sara for Checkout of Beta Bakery' => [self::SARA, self::CHECKOUT, false];
        yield 'Ben, with no role, for Shop' => [self::BEN, self::SHOP, false];
        yield 'Ben, with no role, for Mobile' => [self::BEN, self::MOBILE, false];
        yield 'the Beta Bakery admin for Checkout' => [self::BETA_ADMIN, self::CHECKOUT, true];
        yield 'the Beta Bakery admin for Shop of Acme Foods' => [self::BETA_ADMIN, self::SHOP, false];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function onlyATeamRoleOnTheTeamCreatesATicketForIt(string $actor, string $teamId, bool $isGranted): void
    {
        self::assertSame(
            $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $this->voter()->vote(self::tokenOf($actor), new TeamId($teamId), [TicketPermission::CREATE]),
        );
    }

    #[Test]
    public function withoutAnActorItIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), new TeamId(self::SHOP), [TicketPermission::CREATE]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::LENA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::SHOP), [TicketPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [TicketPermission::CREATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::SHOP, [TicketPermission::CREATE]));
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voter(): TicketCreationVoter
    {
        $shop = new TeamId(self::SHOP);
        $mobile = new TeamId(self::MOBILE);
        $actor = static fn (string $userId, Roles $roles, string $organizationId = self::ACME_FOODS): ActorContext => new ActorContext(new UserId($userId), new OrganizationId($organizationId), $roles);

        return new TicketCreationVoter(new FixedCurrentActor([
            self::LENA => $actor(self::LENA, new Roles(false, false, [$shop], [], [])),
            self::OMAR => $actor(self::OMAR, new Roles(false, false, [], [$shop], [])),
            self::SHOP_VIEWER => $actor(self::SHOP_VIEWER, new Roles(false, false, [], [], [$shop])),
            self::RAJ => $actor(self::RAJ, new Roles(false, true, [], [$mobile], [])),
            self::SARA => $actor(self::SARA, new Roles(true, false, [], [], [$mobile])),
            self::BEN => $actor(self::BEN, Roles::none()),
            self::BETA_ADMIN => $actor(self::BETA_ADMIN, new Roles(true, false, [new TeamId(self::CHECKOUT)], [], []), self::BETA_BAKERY),
        ]));
    }
}
