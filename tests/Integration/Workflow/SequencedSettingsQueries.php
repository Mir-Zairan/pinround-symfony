<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Answers the step policy from a fixed list, one per read, and counts the reads.
 */
final class SequencedSettingsQueries implements SettingsQueries
{
    public int $reads = 0;

    /**
     * @param list<StepPolicy> $answers
     */
    public function __construct(private readonly array $answers)
    {
    }

    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
    {
        return $this->answers[$this->reads++] ?? throw new \LogicException('The step policy was read more often than the test expects.');
    }
}
