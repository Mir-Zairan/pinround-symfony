<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Checklist;

use App\Review\Api\CheckItemAccessFacts;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Grants the attributes it was given on a check item and records every question.
 */
final class CountingAuthorizationChecker implements AuthorizationCheckerInterface
{
    /** @var list<string> */
    public array $askedAttributes = [];

    /** @var list<CheckItemAccessFacts> */
    public array $askedSubjects = [];

    /**
     * @param list<string> $grantedAttributes
     */
    public function __construct(private readonly array $grantedAttributes)
    {
    }

    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        if (!\is_string($attribute) || !$subject instanceof CheckItemAccessFacts) {
            throw new \LogicException('Only check item permissions are asked here.');
        }
        $this->askedAttributes[] = $attribute;
        $this->askedSubjects[] = $subject;

        return \in_array($attribute, $this->grantedAttributes, true);
    }
}
