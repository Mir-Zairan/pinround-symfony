<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Api;

use App\Organization\Api\MemberJob;
use App\Organization\Domain\Job;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MemberJobTest extends TestCase
{
    #[Test]
    public function itHasACaseForEveryJobAndNoOther(): void
    {
        self::assertEqualsCanonicalizing(
            array_map(static fn (Job $job): string => $job->value, Job::cases()),
            array_map(static fn (MemberJob $memberJob): string => $memberJob->value, MemberJob::cases()),
        );
    }
}
