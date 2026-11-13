<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Application\Turn\LoadBasedTurnPeopleChoice;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoadBasedTurnPeopleChoiceTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000001a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000001b';
    private const string MIA = '0190a000-0000-7000-8000-00000000001c';
    private const string RAJ = '0190a000-0000-7000-8000-00000000001d';

    #[Test]
    public function theLeadIsStoredAsReviewerOfAnotherDevelopersCode(): void
    {
        $team = self::team([new TeamMemberLoad(new UserId(self::LENA)), new TeamMemberLoad(new UserId(self::OMAR))], []);

        self::assertSame(self::LENA, self::choice()->reviewerToStore(new UserId(self::OMAR), $team)?->toString());
    }

    /**
     * Omar has the lower user id, so only the loads make Mia the reviewer.
     */
    #[Test]
    public function theLeastBusyOtherDeveloperIsStoredAsReviewerOfTheLeadsCode(): void
    {
        $team = self::team([
            new TeamMemberLoad(new UserId(self::LENA)),
            new TeamMemberLoad(new UserId(self::OMAR), openReviews: 2),
            new TeamMemberLoad(new UserId(self::MIA), openReviews: 1),
        ], []);

        self::assertSame(self::MIA, self::choice()->reviewerToStore(new UserId(self::LENA), $team)?->toString());
    }

    /**
     * The lead reviewing their own code carries an override note; storing the lead would drop that note from later moves.
     */
    #[Test]
    public function theLeadReviewingTheirOwnCodeIsNotStored(): void
    {
        $team = self::team([new TeamMemberLoad(new UserId(self::LENA))], []);

        self::assertNotNull(new ReviewerChoice()->choose(new UserId(self::LENA), $team)->overrideNote);
        self::assertNull(self::choice()->reviewerToStore(new UserId(self::LENA), $team));
    }

    /**
     * Mia has the lower user id, so only the loads make Raj the tester.
     */
    #[Test]
    public function theLeastBusyTesterIsStored(): void
    {
        $team = self::team([], [
            new TeamMemberLoad(new UserId(self::MIA), ticketsInTesting: 1),
            new TeamMemberLoad(new UserId(self::RAJ), ticketsInTesting: 0),
        ]);

        self::assertSame(self::RAJ, self::choice()->testerToStore($team)?->toString());
    }

    /**
     * Without a tester the Testing turn falls back to the lead, who is not stored as the tester.
     */
    #[Test]
    public function aTeamWithoutATesterStoresNoTester(): void
    {
        $team = self::team([new TeamMemberLoad(new UserId(self::LENA))], []);

        self::assertSame(self::LENA, new TesterChoice()->choose($team)->userId->toString());
        self::assertNull(self::choice()->testerToStore($team));
    }

    private static function choice(): LoadBasedTurnPeopleChoice
    {
        return new LoadBasedTurnPeopleChoice(new ReviewerChoice(), new TesterChoice());
    }

    /**
     * @param list<TeamMemberLoad> $developers
     * @param list<TeamMemberLoad> $testers
     */
    private static function team(array $developers, array $testers): TeamFacts
    {
        return new TeamFacts(new UserId(self::LENA), 'Lena', $developers, $testers);
    }
}
