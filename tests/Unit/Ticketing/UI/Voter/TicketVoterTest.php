<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\RoleTicketVisibilities;
use App\Ticketing\UI\Voter\TicketVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TicketVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000015a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000015a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000015a3';
    private const string SHOP_VIEWER = '0197a3c0-0000-7000-8000-0000000015a4';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000015a5';
    private const string RAJ_IN_SHOP = '0197a3c0-0000-7000-8000-0000000015a6';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000015a7';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000015a8';
    private const string TRIAGER = '0197a3c0-0000-7000-8000-0000000015a9';
    private const string OUTSIDER = '0197a3c0-0000-7000-8000-0000000015aa';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-0000000015ab';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000150a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000150b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000015d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000015d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000015d3';

    /**
     * Every role against every attribute, on the tickets of ticket(). Lena leads Shop; Omar is a member (developer)
     * and Mia a member (tester) of Shop; the Shop viewer is its viewer; Raj is a Manager with no team role, and Raj in
     * Shop a Manager who is also a member of Shop; Sara is an Admin with no team role; Ben reported every Acme Foods
     * ticket and has no role; the triager is Acme Foods' triager and has no role; the outsider has no role; the Beta
     * Bakery admin is an Admin of Beta Bakery.
     *
     * @return iterable<string, array{string, string, string, bool}> actor, attribute, ticket, granted
     */
    public static function matrix(): iterable
    {
        $everyAttribute = [TicketPermission::VIEW, TicketPermission::EDIT, TicketPermission::MOVE, TicketPermission::ASSIGN];
        $expectations = [
            'Lena' => [self::LENA, 'team-only Shop', [true, true, true, true]],
            'Lena on Mobile' => [self::LENA, 'Mobile', [true, false, false, false]],
            'Omar' => [self::OMAR, 'team-only Shop', [true, true, true, false]],
            'Mia' => [self::MIA, 'team-only Shop', [true, true, true, false]],
            'the Shop viewer' => [self::SHOP_VIEWER, 'team-only Shop', [true, false, false, false]],
            'Raj on a ticket for everyone' => [self::RAJ, 'Shop', [true, false, false, false]],
            'Raj' => [self::RAJ, 'team-only Shop', [false, false, false, false]],
            'Raj in Shop' => [self::RAJ_IN_SHOP, 'team-only Shop', [true, true, true, false]],
            'Sara' => [self::SARA, 'team-only Shop', [true, false, false, false]],
            'Sara with no team' => [self::SARA, 'team-only with no team', [true, false, false, false]],
            'Ben the outside reporter' => [self::BEN, 'team-only Shop', [true, false, false, false]],
            'the triager with no team' => [self::TRIAGER, 'team-only with no team', [true, false, false, false]],
            'the triager on Shop' => [self::TRIAGER, 'team-only Shop', [false, false, false, false]],
            'Lena with no team' => [self::LENA, 'team-only with no team', [false, false, false, false]],
            'the outsider' => [self::OUTSIDER, 'team-only Shop', [false, false, false, false]],
            'the outsider on a ticket for everyone' => [self::OUTSIDER, 'Shop', [true, false, false, false]],
            'the Beta Bakery admin on Shop' => [self::BETA_ADMIN, 'Shop', [false, false, false, false]],
            'the Beta Bakery admin on team-only Shop' => [self::BETA_ADMIN, 'team-only Shop', [false, false, false, false]],
            'the Beta Bakery admin on Checkout' => [self::BETA_ADMIN, 'Checkout', [true, true, true, true]],
        ];
        foreach ($expectations as $case => [$actor, $ticket, $grantedByAttribute]) {
            foreach ($everyAttribute as $index => $attribute) {
                yield $case.' '.$attribute => [$actor, $attribute, $ticket, $grantedByAttribute[$index]];
            }
        }
    }

    #[Test]
    #[DataProvider('matrix')]
    public function itDecidesTheRoleAndAttributeAsTheMatrixSays(string $actor, string $attribute, string $ticket, bool $isGranted): void
    {
        self::assertSame(
            $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $this->voter()->vote(self::tokenOf($actor), self::ticket($ticket), [$attribute]),
        );
    }

    #[Test]
    public function withoutAnActorEveryAttributeIsDenied(): void
    {
        $voter = $this->voter();

        foreach ([TicketPermission::VIEW, TicketPermission::EDIT, TicketPermission::MOVE, TicketPermission::ASSIGN] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), self::ticket('Shop'), [$attribute]), $attribute);
        }
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::ticket('Shop'), [TicketPermission::CREATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::SHOP), [TicketPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [TicketPermission::EDIT]));
    }

    private static function ticket(string $name): TicketVisibilityFacts
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $ben = new UserId(self::BEN);

        return match ($name) {
            'Shop' => new TicketVisibilityFacts($acmeFoods, TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::SHOP), $ben),
            'team-only Shop' => new TicketVisibilityFacts($acmeFoods, TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP), $ben),
            'Mobile' => new TicketVisibilityFacts($acmeFoods, TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::MOBILE), $ben),
            'team-only with no team' => new TicketVisibilityFacts($acmeFoods, TicketVisibilityFacts::TEAM_ONLY, null, $ben),
            'Checkout' => new TicketVisibilityFacts(new OrganizationId(self::BETA_BAKERY), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::CHECKOUT), new UserId(self::BETA_ADMIN)),
            default => throw new \LogicException('No ticket '.$name.'.'),
        };
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    /**
     * The Beta Bakery admin also leads Shop and Checkout: no Beta Bakery actor can hold a team of Acme Foods, so only
     * the organization check denies them the Acme Foods tickets.
     */
    private function voter(): TicketVoter
    {
        $shop = new TeamId(self::SHOP);
        $actor = static fn (string $userId, Roles $roles, string $organizationId = self::ACME_FOODS): ActorContext => new ActorContext(new UserId($userId), new OrganizationId($organizationId), $roles);

        return new TicketVoter(new FixedCurrentActor([
            self::LENA => $actor(self::LENA, new Roles(false, false, [$shop], [], [])),
            self::OMAR => $actor(self::OMAR, new Roles(false, false, [], [$shop], [])),
            self::MIA => $actor(self::MIA, new Roles(false, false, [], [$shop], [])),
            self::SHOP_VIEWER => $actor(self::SHOP_VIEWER, new Roles(false, false, [], [], [$shop])),
            self::RAJ => $actor(self::RAJ, new Roles(false, true, [], [], [])),
            self::RAJ_IN_SHOP => $actor(self::RAJ_IN_SHOP, new Roles(false, true, [], [$shop], [])),
            self::SARA => $actor(self::SARA, new Roles(true, false, [], [], [])),
            self::BEN => $actor(self::BEN, Roles::none()),
            self::TRIAGER => $actor(self::TRIAGER, Roles::none()),
            self::OUTSIDER => $actor(self::OUTSIDER, Roles::none()),
            self::BETA_ADMIN => $actor(self::BETA_ADMIN, new Roles(true, false, [$shop, new TeamId(self::CHECKOUT)], [], []), self::BETA_BAKERY),
        ]), new RoleTicketVisibilities(self::noMemberships(), self::triagerSettings()));
    }

    private static function noMemberships(): MembershipQueries
    {
        return new readonly class implements MembershipQueries {
            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('The voter decides with the actor\'s roles and reads no membership.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                throw new \LogicException('The voter decides with the actor\'s roles and reads no membership.');
            }
        };
    }

    private static function triagerSettings(): SettingsQueries
    {
        return new readonly class(self::ACME_FOODS, self::TRIAGER) implements SettingsQueries {
            public function __construct(private string $organizationId, private string $triagerId)
            {
            }

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                throw new \LogicException('The voter reads no step policy.');
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return $this->organizationId === $organizationId->toString() ? new UserId($this->triagerId) : null;
            }
        };
    }
}
