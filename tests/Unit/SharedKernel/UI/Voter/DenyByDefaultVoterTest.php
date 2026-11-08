<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI\Voter;

use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DenyByDefaultVoterTest extends TestCase
{
    private const string OPEN = 'THING_OPEN';
    private const string CLOSE = 'THING_CLOSE';

    #[Test]
    public function itAbstainsForAnAttributeItDoesNotOwn(): void
    {
        $voter = self::voterGranting(self::OPEN, self::CLOSE);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new \LogicException(), ['TEAM_VIEW']));
        self::assertFalse($voter->supportsAttribute('TEAM_VIEW'));
        self::assertTrue($voter->supportsAttribute(self::OPEN));
    }

    #[Test]
    public function itAbstainsForASubjectOfAnotherType(): void
    {
        $voter = self::voterGranting(self::OPEN);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new \stdClass(), [self::OPEN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), \LogicException::class, [self::OPEN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, [self::OPEN]));
        self::assertFalse($voter->supportsType(\stdClass::class));
        self::assertFalse($voter->supportsType('string'));
    }

    #[Test]
    public function itDecidesOnASubclassOfItsSubjectClass(): void
    {
        $voter = self::voterGranting(self::OPEN);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), new \DomainException(), [self::OPEN]));
        self::assertTrue($voter->supportsType(\DomainException::class));
    }

    #[Test]
    public function itDeniesItsOwnAttributeAndSubjectWhenTheRuleSaysNo(): void
    {
        $voter = self::voterGranting(self::OPEN);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new \LogicException(), [self::CLOSE]));
    }

    #[Test]
    public function itGrantsWhenTheRuleSaysYes(): void
    {
        $voter = self::voterGranting(self::OPEN);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), new \LogicException(), [self::OPEN]));
    }

    /**
     * A voter owning OPEN and CLOSE on \LogicException whose rule grants exactly the given attributes.
     *
     * @return DenyByDefaultVoter<\LogicException>
     */
    private static function voterGranting(string ...$grantedAttributes): DenyByDefaultVoter
    {
        return new /** @extends DenyByDefaultVoter<\LogicException> */ class(array_values($grantedAttributes)) extends DenyByDefaultVoter {
            /**
             * @param list<string> $grantedAttributes
             */
            public function __construct(private readonly array $grantedAttributes)
            {
            }

            protected function attributes(): array
            {
                return ['THING_OPEN', 'THING_CLOSE'];
            }

            protected function subjectClass(): string
            {
                return \LogicException::class;
            }

            protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
            {
                return \in_array($attribute, $this->grantedAttributes, true);
            }
        };
    }
}
