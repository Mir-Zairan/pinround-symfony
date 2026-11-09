<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Domain\BlankStepName;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\DoneGroupStepRefused;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\InconsistentStepOrigin;
use App\Workflow\Domain\Step;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\StepOrigin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepTest extends TestCase
{
    private const string STEP_ID = '0197a3c0-1234-7abc-8def-0123456789ab';
    private const string ORGANIZATION_ID = '0197a3c0-1234-7abc-8def-0123456789ac';
    private const string TEAM_ID = '0197a3c0-1234-7abc-8def-0123456789ad';

    #[Test]
    public function aTeamStepKeepsItsTrimmedNameTeamAndGroup(): void
    {
        $step = $this->step(StepOrigin::Team, null, '  Ready to merge ', Group::Check, new TeamId(self::TEAM_ID));

        self::assertSame('Ready to merge', $step->name());
        self::assertSame(self::TEAM_ID, $step->teamId()?->toString());
        self::assertSame(self::ORGANIZATION_ID, $step->organizationId()->toString());
        self::assertSame(self::STEP_ID, $step->id()->toString());
        self::assertSame(Group::Check, $step->group());
        self::assertSame(StepOrigin::Team, $step->origin());
        self::assertNull($step->coreCode());
        self::assertSame(3, $step->position());
        self::assertTrue($step->isRemovable());
    }

    #[Test]
    public function aCompanyStepHasNoTeamAndCanBeRemoved(): void
    {
        $step = $this->step(StepOrigin::Company, null, 'Design check', Group::Check, null);

        self::assertNull($step->teamId());
        self::assertTrue($step->isRemovable());
    }

    #[Test]
    public function aCoreStepIsNotRemovable(): void
    {
        $step = $this->step(StepOrigin::Core, CoreStep::Review, 'Code review', Group::Check, null);

        self::assertFalse($step->isRemovable());
        self::assertSame(CoreStep::Review, $step->coreCode());
    }

    #[Test]
    public function theCoreDoneStepMayBeInTheDoneGroup(): void
    {
        $step = $this->step(StepOrigin::Core, CoreStep::Done, 'Done', Group::Done, null);

        self::assertSame(Group::Done, $step->group());
    }

    #[Test]
    public function aBlankNameIsRefused(): void
    {
        $this->expectException(BlankStepName::class);

        $this->step(StepOrigin::Company, null, " \t\n", Group::Doing, null);
    }

    #[Test]
    public function aCompanyStepCannotBeInTheDoneGroup(): void
    {
        $this->expectException(DoneGroupStepRefused::class);

        $this->step(StepOrigin::Company, null, 'Shipped', Group::Done, null);
    }

    #[Test]
    public function aTeamStepCannotBeInTheDoneGroup(): void
    {
        $this->expectException(DoneGroupStepRefused::class);

        $this->step(StepOrigin::Team, null, 'Shipped', Group::Done, new TeamId(self::TEAM_ID));
    }

    #[Test]
    public function aTeamStepNeedsATeam(): void
    {
        $this->expectException(InconsistentStepOrigin::class);

        $this->step(StepOrigin::Team, null, 'Ready to merge', Group::Check, null);
    }

    #[Test]
    public function aCompanyStepCannotHaveATeam(): void
    {
        $this->expectException(InconsistentStepOrigin::class);

        $this->step(StepOrigin::Company, null, 'Design check', Group::Check, new TeamId(self::TEAM_ID));
    }

    #[Test]
    public function aCoreStepNeedsACoreCode(): void
    {
        $this->expectException(InconsistentStepOrigin::class);

        $this->step(StepOrigin::Core, null, 'Code review', Group::Check, null);
    }

    #[Test]
    public function aCoreStepCannotHaveATeam(): void
    {
        $this->expectException(InconsistentStepOrigin::class);

        $this->step(StepOrigin::Core, CoreStep::Review, 'Code review', Group::Check, new TeamId(self::TEAM_ID));
    }

    #[Test]
    public function aCoreStepCannotLeaveItsGroup(): void
    {
        $this->expectException(InconsistentStepOrigin::class);

        $this->step(StepOrigin::Core, CoreStep::Review, 'Code review', Group::Doing, null);
    }

    #[Test]
    public function onlyACoreStepHasACoreCode(): void
    {
        $this->expectException(InconsistentStepOrigin::class);

        $this->step(StepOrigin::Company, CoreStep::Review, 'Design check', Group::Check, null);
    }

    private function step(StepOrigin $origin, ?CoreStep $coreCode, string $name, Group $group, ?TeamId $teamId): Step
    {
        return new Step(
            new StepId(self::STEP_ID),
            new OrganizationId(self::ORGANIZATION_ID),
            $teamId,
            $origin,
            $coreCode,
            $name,
            $group,
            3,
        );
    }
}
