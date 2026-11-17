<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\UI\Voter\AiSettingsVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * AdminAreaVoterTest proves the admin login rule; the ADMIN_AI rows of voter_matrix_rows.php prove it end to end.
 */
final class AiSettingsVoterTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000008a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000080a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000080b';

    #[Test]
    public function whoeverMayEnterTheAdminPagesOfTheOrganizationMayChangeItsAiSettings(): void
    {
        $voter = new AiSettingsVoter(self::adminAreaGrantedFor(self::ACME_FOODS));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::sara(), new OrganizationId(self::ACME_FOODS), [AdminPermission::AI]));
    }

    #[Test]
    public function whoeverIsRefusedTheAdminPagesIsRefusedTheAiSettings(): void
    {
        $voter = new AiSettingsVoter(self::adminAreaGrantedFor(null));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::sara(), new OrganizationId(self::ACME_FOODS), [AdminPermission::AI]));
    }

    #[Test]
    public function itAsksAboutTheAdminPagesOfTheSameOrganization(): void
    {
        $voter = new AiSettingsVoter(self::adminAreaGrantedFor(self::ACME_FOODS));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::sara(), new OrganizationId(self::BETA_BAKERY), [AdminPermission::AI]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnASubjectThatIsNoOrganization(): void
    {
        $voter = new AiSettingsVoter(self::adminAreaGrantedFor(self::ACME_FOODS));
        $acmeFoods = new OrganizationId(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::sara(), $acmeFoods, [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::sara(), $acmeFoods, [OrganizationPermission::ADMIN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::sara(), null, [AdminPermission::AI]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::sara(), new TeamId(self::ACME_FOODS), [AdminPermission::AI]));
    }

    private static function sara(): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser(self::SARA, null), 'main');
    }

    /**
     * Grants ADMIN_AREA on the organization with the given id only (none when null); any other ask is a test bug.
     */
    private static function adminAreaGrantedFor(?string $organizationId): AccessDecisionManagerInterface
    {
        return new readonly class($organizationId) implements AccessDecisionManagerInterface {
            public function __construct(private ?string $organizationId)
            {
            }

            /**
             * @param array<mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, ?AccessDecision $accessDecision = null): bool
            {
                if ([AdminPermission::AREA] !== $attributes || !$object instanceof OrganizationId) {
                    throw new \LogicException('The voter asks only whether the admin pages of an organization may be entered.');
                }

                return null !== $this->organizationId && $object->equals(new OrganizationId($this->organizationId));
            }
        };
    }
}
