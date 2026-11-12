<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Workflow\Api\WorkflowPermission;
use App\Workflow\UI\Voter\WorkflowVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class WorkflowVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000015a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000015a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000015a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000015a4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000015a5';
    private const string ADMIN_WHO_LEADS = '0197a3c0-0000-7000-8000-0000000015a6';
    private const string BETA_LEAD = '0197a3c0-0000-7000-8000-0000000015a7';
    private const string NOBODY = '0197a3c0-0000-7000-8000-0000000015a8';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000150a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000150b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000015d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000015d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000015d3';

    #[Test]
    public function withoutAnActorItIsDenied(): void
    {
        $voter = $this->voter(StepPolicy::Team, StepPolicy::Team);

        foreach ([WorkflowPermission::STEP_CHANGE_TEAM, WorkflowPermission::RULES_CHANGE_TEAM] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new TeamId(self::SHOP), [$attribute]));
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::NOBODY, self::SHOP, $attribute));
        }
    }

    #[Test]
    public function underLockedTheLeadChangesNeitherTheTeamStepsNorTheRules(): void
    {
        $voter = $this->voter(StepPolicy::Locked, StepPolicy::Team);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::RULES_CHANGE_TEAM));
    }

    #[Test]
    public function underExtendTheLeadChangesTheStepsOfTheirOwnTeamOnlyAndNoRules(): void
    {
        $voter = $this->voter(StepPolicy::Extend, StepPolicy::Team);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::RULES_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::MOBILE, WorkflowPermission::STEP_CHANGE_TEAM));
    }

    #[Test]
    public function underTeamTheLeadChangesTheStepsAndRulesOfTheirOwnTeamOnly(): void
    {
        $voter = $this->voter(StepPolicy::Team, StepPolicy::Team);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::RULES_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::MOBILE, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::MOBILE, WorkflowPermission::RULES_CHANGE_TEAM));
    }

    #[Test]
    public function noOrgRoleAndNoOtherTeamRoleChangesTheTeamStepsOrRules(): void
    {
        $voter = $this->voter(StepPolicy::Team, StepPolicy::Team);

        foreach ([self::SARA, self::RAJ, self::OMAR, self::MIA] as $userId) {
            foreach ([WorkflowPermission::STEP_CHANGE_TEAM, WorkflowPermission::RULES_CHANGE_TEAM] as $attribute) {
                self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, $userId, self::SHOP, $attribute), $userId.' '.$attribute);
            }
        }
    }

    #[Test]
    public function anAdminWhoLeadsTheTeamChangesItAsItsLead(): void
    {
        $voter = $this->voter(StepPolicy::Team, StepPolicy::Team);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::ADMIN_WHO_LEADS, self::MOBILE, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::ADMIN_WHO_LEADS, self::MOBILE, WorkflowPermission::RULES_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::ADMIN_WHO_LEADS, self::SHOP, WorkflowPermission::STEP_CHANGE_TEAM));
    }

    #[Test]
    public function aTeamOfAnotherOrganizationIsDenied(): void
    {
        $voter = $this->voter(StepPolicy::Team, StepPolicy::Team);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::CHECKOUT, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::SARA, self::CHECKOUT, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::BETA_LEAD, self::SHOP, WorkflowPermission::STEP_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::BETA_LEAD, self::SHOP, WorkflowPermission::RULES_CHANGE_TEAM));
    }

    #[Test]
    public function theStepPolicyIsTheOneOfTheActorsOrganization(): void
    {
        $voter = $this->voter(StepPolicy::Locked, StepPolicy::Team);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::BETA_LEAD, self::CHECKOUT, WorkflowPermission::RULES_CHANGE_TEAM));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::SHOP, WorkflowPermission::STEP_CHANGE_TEAM));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter(StepPolicy::Team, StepPolicy::Team);
        $token = self::tokenOf(self::LENA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::SHOP), [OrganizationPermission::ADMIN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [WorkflowPermission::STEP_CHANGE_TEAM]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::SHOP, [WorkflowPermission::RULES_CHANGE_TEAM]));
    }

    /**
     * Only a lead of the team can be granted, so the step policy is not read for anyone else.
     */
    #[Test]
    public function forSomeoneWhoDoesNotLeadTheTeamTheStepPolicyIsNotRead(): void
    {
        $voter = $this->voterReading(new readonly class implements SettingsQueries {
            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                throw new \LogicException('The step policy was read for someone who does not lead the team.');
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return null;
            }
        });

        foreach ([[self::LENA, self::MOBILE], [self::OMAR, self::SHOP], [self::SARA, self::SHOP], [self::BETA_LEAD, self::SHOP]] as [$userId, $teamId]) {
            foreach ([WorkflowPermission::STEP_CHANGE_TEAM, WorkflowPermission::RULES_CHANGE_TEAM] as $attribute) {
                self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, $userId, $teamId, $attribute), $userId.' '.$attribute);
            }
        }
    }

    private function decide(WorkflowVoter $voter, string $userId, string $teamId, string $attribute): int
    {
        return $voter->vote(self::tokenOf($userId), new TeamId($teamId), [$attribute]);
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voter(StepPolicy $acmeFoodsPolicy, StepPolicy $betaBakeryPolicy): WorkflowVoter
    {
        return $this->voterReading(new readonly class([self::ACME_FOODS => $acmeFoodsPolicy, self::BETA_BAKERY => $betaBakeryPolicy]) implements SettingsQueries {
            /**
             * @param array<string, StepPolicy> $stepPolicyByOrganization
             */
            public function __construct(private array $stepPolicyByOrganization)
            {
            }

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                return $this->stepPolicyByOrganization[$organizationId->toString()] ?? StepPolicy::Extend;
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return null;
            }
        });
    }

    /**
     * Acme Foods: Lena leads Shop and has no org role; Omar is a member and Mia a viewer of Shop; Raj is a Manager and
     * a viewer of Shop; Sara is an Admin with no team role; another Admin leads Mobile. Beta Bakery: its lead leads
     * Checkout and has no org role. Nobody has no actor.
     */
    private function voterReading(SettingsQueries $settingsQueries): WorkflowVoter
    {
        $shop = new TeamId(self::SHOP);
        $actor = static fn (string $userId, string $organizationId, Roles $roles): ActorContext => new ActorContext(new UserId($userId), new OrganizationId($organizationId), $roles);

        return new WorkflowVoter(new FixedCurrentActor([
            self::LENA => $actor(self::LENA, self::ACME_FOODS, new Roles(false, false, [$shop], [], [])),
            self::OMAR => $actor(self::OMAR, self::ACME_FOODS, new Roles(false, false, [], [$shop], [])),
            self::MIA => $actor(self::MIA, self::ACME_FOODS, new Roles(false, false, [], [], [$shop])),
            self::RAJ => $actor(self::RAJ, self::ACME_FOODS, new Roles(false, true, [], [], [$shop])),
            self::SARA => $actor(self::SARA, self::ACME_FOODS, new Roles(true, false, [], [], [])),
            self::ADMIN_WHO_LEADS => $actor(self::ADMIN_WHO_LEADS, self::ACME_FOODS, new Roles(true, false, [new TeamId(self::MOBILE)], [], [])),
            self::BETA_LEAD => $actor(self::BETA_LEAD, self::BETA_BAKERY, new Roles(false, false, [new TeamId(self::CHECKOUT)], [], [])),
        ]), $settingsQueries);
    }
}
