<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewerAndTesterChoiceTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    private const string MIA = '0190a000-0000-7000-8000-00000000000c';
    private const string RAJ = '0190a000-0000-7000-8000-00000000000d';
    private const string SARA = '0190a000-0000-7000-8000-00000000000e';

    #[Test]
    public function theLeadReviewsWhenAnotherDeveloperBuilt(): void
    {
        $team = $this->team([new TeamMemberLoad($this->id(self::OMAR))]);

        $chosen = new ReviewerChoice()->choose($this->id(self::OMAR), $team);

        self::assertTrue($chosen->userId->equals($this->id(self::LENA)));
        self::assertNull($chosen->overrideNote);
    }

    #[Test]
    public function theLeadReviewsATicketNobodyIsAssignedTo(): void
    {
        $chosen = new ReviewerChoice()->choose(null, $this->team([]));

        self::assertTrue($chosen->userId->equals($this->id(self::LENA)));
        self::assertNull($chosen->overrideNote);
    }

    #[Test]
    public function whenTheLeadBuiltTheDeveloperWithTheFewestOpenReviewsReviews(): void
    {
        $team = $this->team([
            new TeamMemberLoad($this->id(self::OMAR), openReviews: 3),
            new TeamMemberLoad($this->id(self::RAJ), openReviews: 1),
            new TeamMemberLoad($this->id(self::LENA), openReviews: 0),
        ]);

        $chosen = new ReviewerChoice()->choose($this->id(self::LENA), $team);

        self::assertTrue($chosen->userId->equals($this->id(self::RAJ)));
        self::assertNull($chosen->overrideNote);
    }

    #[Test]
    public function aTieInOpenReviewsGoesToTheLowestUserIdWhateverTheListOrder(): void
    {
        $team = $this->team([
            new TeamMemberLoad($this->id(self::RAJ), openReviews: 2),
            new TeamMemberLoad($this->id(self::OMAR), openReviews: 2),
        ]);

        $chosen = new ReviewerChoice()->choose($this->id(self::LENA), $team);

        self::assertTrue($chosen->userId->equals($this->id(self::OMAR)));
    }

    #[Test]
    public function theLeadReviewsOwnCodeWithAnOverrideNoteWhenNoOtherDeveloperExists(): void
    {
        $team = $this->team([new TeamMemberLoad($this->id(self::LENA))]);

        $chosen = new ReviewerChoice()->choose($this->id(self::LENA), $team);

        self::assertTrue($chosen->userId->equals($this->id(self::LENA)));
        self::assertSame('Lena reviews her own code: no other developer in the team', $chosen->overrideNote);
    }

    #[Test]
    public function theTesterWithTheFewestTicketsInTestingTests(): void
    {
        $team = $this->team([], [
            new TeamMemberLoad($this->id(self::MIA), ticketsInTesting: 4),
            new TeamMemberLoad($this->id(self::SARA), ticketsInTesting: 1),
        ]);

        self::assertTrue(new TesterChoice()->choose($team)->userId->equals($this->id(self::SARA)));
    }

    #[Test]
    public function aTieInTestingGoesToTheLowestUserIdWhateverTheListOrder(): void
    {
        $team = $this->team([], [
            new TeamMemberLoad($this->id(self::SARA), ticketsInTesting: 2),
            new TeamMemberLoad($this->id(self::MIA), ticketsInTesting: 2),
        ]);

        self::assertTrue(new TesterChoice()->choose($team)->userId->equals($this->id(self::MIA)));
    }

    #[Test]
    public function aTeamWithoutATesterSendsTestingToTheLead(): void
    {
        self::assertTrue(new TesterChoice()->choose($this->team([]))->userId->equals($this->id(self::LENA)));
    }

    /**
     * @param list<TeamMemberLoad> $developers
     * @param list<TeamMemberLoad> $testers
     */
    private function team(array $developers, array $testers = []): TeamFacts
    {
        return new TeamFacts($this->id(self::LENA), 'Lena', $developers, $testers);
    }

    private function id(string $value): UserId
    {
        return new UserId($value);
    }
}
