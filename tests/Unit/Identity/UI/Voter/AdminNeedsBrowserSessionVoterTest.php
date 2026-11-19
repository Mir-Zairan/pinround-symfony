<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Voter;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Application\AccountPermission;
use App\Identity\Application\RecentAuth;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogins;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyLoginAssurance;
use App\Identity\UI\Voter\AdminNeedsBrowserSessionVoter;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The voter with the app's own SymfonyLoginAssurance, so the token kinds are told apart as in a request; its two-step
 * answers, which this voter does not ask, come from stubs.
 */
final class AdminNeedsBrowserSessionVoterTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007a1';

    #[Test]
    public function aTokenActorIsDeniedEveryAdminAttributeInTheCodeOnAnySubject(): void
    {
        $adminAttributes = [...self::adminAttributesInTheCode(), OrganizationPermission::ADMIN, OrganizationPermission::INVITE];
        self::assertContains('ADMIN_AREA', $adminAttributes, 'no ADMIN_* attribute was found, so the prefix rule was not checked');
        $voter = $this->voter();

        foreach ($adminAttributes as $attribute) {
            foreach ([new OrganizationId(self::ACME_FOODS), null, 'any subject'] as $subject) {
                $vote = new Vote();
                self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenActor(), $subject, [$attribute], $vote), $attribute);
                self::assertSame(['Admin work needs a browser session, not a personal access token.'], $vote->reasons);
            }
        }
    }

    #[Test]
    public function itsDenyOutweighsAnAdminVoterThatGrantsUnderTheUnanimousStrategy(): void
    {
        $grantsEveryAdminAttribute = new class implements VoterInterface {
            public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
            {
                return self::ACCESS_GRANTED;
            }
        };
        $subject = new OrganizationId(self::ACME_FOODS);
        $withoutThisVoter = new AccessDecisionManager([$grantsEveryAdminAttribute], new UnanimousStrategy(false));
        $withThisVoter = new AccessDecisionManager([$grantsEveryAdminAttribute, $this->voter()], new UnanimousStrategy(false));

        foreach (['ADMIN_AREA', OrganizationPermission::ADMIN, OrganizationPermission::INVITE] as $attribute) {
            self::assertTrue($withoutThisVoter->decide(self::tokenActor(), [$attribute], $subject), 'the stand-in admin voter must grant on its own');
            self::assertFalse($withThisVoter->decide(self::tokenActor(), [$attribute], $subject), $attribute);
            self::assertTrue($withThisVoter->decide(self::browserSession(), [$attribute], $subject), 'a browser session must be left to the admin voter');
        }
    }

    #[Test]
    public function aBrowserSessionOrNobodyGetsAnAbstentionSoTheAdminVoterDecides(): void
    {
        $voter = $this->voter();

        foreach ([self::browserSession(), new NullToken()] as $token) {
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), ['ADMIN_AREA', 'ADMIN_AI', OrganizationPermission::ADMIN, OrganizationPermission::INVITE]));
        }
    }

    #[Test]
    public function aTokenActorGetsAnAbstentionOnEveryAttributeThatIsNoAdminOne(): void
    {
        $voter = $this->voter();

        $otherAttributes = ['TICKET_VIEW', AccountPermission::EDIT_OWN, 'TEAM_ADMIN_AREA', 'admin_area', 'ADMIN', OrganizationPermission::VIEW, OrganizationPermission::ACCEPT_INVITATION, 'org_admin', 'ORG_ADMIN_'];
        foreach ($otherAttributes as $attribute) {
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::tokenActor(), null, [$attribute]), $attribute);
        }
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::tokenActor(), null, [new \stdClass()]));
    }

    #[Test]
    public function oneAdminAttributeAmongOthersIsEnoughToDenyATokenActor(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenActor(), null, ['TICKET_VIEW', 'ADMIN_AI']));
    }

    #[Test]
    public function itSupportsTheAdminAttributesOnEverySubjectType(): void
    {
        $voter = $this->voter();

        self::assertTrue($voter->supportsAttribute('ADMIN_AREA'));
        self::assertTrue($voter->supportsAttribute(OrganizationPermission::ADMIN));
        self::assertTrue($voter->supportsAttribute(OrganizationPermission::INVITE));
        self::assertFalse($voter->supportsAttribute('TICKET_VIEW'));
        self::assertFalse($voter->supportsAttribute(OrganizationPermission::VIEW));
        self::assertTrue($voter->supportsType(OrganizationId::class));
        self::assertTrue($voter->supportsType('null'));
    }

    /**
     * Every string literal of the form 'ADMIN_…' in src: the admin attributes there are now and any added later.
     *
     * @return list<string>
     */
    public static function adminAttributesInTheCode(): array
    {
        $attributes = [];
        $sourceFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 5).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($sourceFiles as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            preg_match_all("/'(".AdminNeedsBrowserSessionVoter::ADMIN_ATTRIBUTE_PREFIX."[A-Z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            array_push($attributes, ...$matches[1]);
        }
        $attributes = array_values(array_unique($attributes));
        sort($attributes);

        return $attributes;
    }

    private function voter(): AdminNeedsBrowserSessionVoter
    {
        $twoStepLogins = self::createStub(TwoStepLogins::class);

        return new AdminNeedsBrowserSessionVoter(new SymfonyLoginAssurance(
            $twoStepLogins,
            new RecentAuth(self::createStub(SessionConfirmations::class), $twoStepLogins),
            new MockClock('2027-03-15 10:00:00 UTC'),
        ));
    }

    private static function tokenActor(): ApiTokenAuthentication
    {
        return new ApiTokenAuthentication(self::sara(), new ApiTokenAccess('0197a3c0-0000-7000-8000-0000000007f1', new OrganizationId(self::ACME_FOODS), ['read'], new \DateTimeImmutable('2027-01-31 09:30:00 UTC')), null);
    }

    private static function browserSession(): UsernamePasswordToken
    {
        return new UsernamePasswordToken(self::sara(), 'main', ['ROLE_USER']);
    }

    private static function sara(): SecurityUser
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000007c5'), new Email('sara@acme-foods.example'), 'Sara Holm', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
