<?php

declare(strict_types=1);

namespace App\SharedKernel\UI\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The base of every voter that decides on a subject (ADR-0013). It abstains only for an attribute it does not own or a
 * subject that is not an instance of its subject class; for its own attribute and subject it denies unless grants()
 * says yes. supports() and voteOnAttribute() are final, so a subclass cannot turn a refusal into an abstention, which
 * the unanimous strategy would let another voter's grant overrule.
 *
 * @template TSubject of object
 *
 * @extends Voter<string, TSubject>
 */
abstract class DenyByDefaultVoter extends Voter
{
    /**
     * @return list<string> the attributes this voter decides
     */
    abstract protected function attributes(): array;

    /**
     * @return class-string<TSubject> the subject class this voter decides on, subclasses included
     */
    abstract protected function subjectClass(): string;

    /**
     * The rule: true grants, false denies. Called only for one of attributes() and an instance of subjectClass().
     *
     * @param TSubject $subject
     */
    abstract protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool;

    final public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, $this->attributes(), true);
    }

    final public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, $this->subjectClass(), true);
    }

    final protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && \is_object($subject) && is_a($subject, $this->subjectClass());
    }

    final protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->grants($attribute, $subject, $token, $vote);
    }
}
