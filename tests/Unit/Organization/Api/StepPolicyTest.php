<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Api;

use App\Organization\Api\StepPolicy;
use App\Organization\Domain\OrgSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepPolicyTest extends TestCase
{
    #[Test]
    public function itHasACaseForEveryStoredStepPolicyAndNoOther(): void
    {
        self::assertEqualsCanonicalizing(
            OrgSettings::STEP_POLICIES,
            array_map(static fn (StepPolicy $stepPolicy): string => $stepPolicy->value, StepPolicy::cases()),
        );
    }
}
