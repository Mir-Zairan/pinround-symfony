<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application\Turn;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TurnRowResolver;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\ReleasePlacement;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnRowResolverTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-0000000001a1';
    private const string OMAR = '0190a000-0000-7000-8000-0000000001a2';
    private const string MIA = '0190a000-0000-7000-8000-0000000001a3';
    private const string SARA = '0190a000-0000-7000-8000-0000000001a4';
    private const string SHOP_TEAM = '0190a000-0000-7000-8000-0000000001b1';
    private const string SHOP_PROJECT = '0190a000-0000-7000-8000-0000000001c1';
    private const string TICKET = '0190a000-0000-7000-8000-0000000001d1';

    #[Test]
    public function aLabelWaiterGetsTheLabelAndNoPerson(): void
    {
        $row = $this->resolver(new Turn(null, 'Customer', NeedsYouKind::Input))->rowFor($this->facts(new TicketState('step', 'input', 'wait', null, reporterId: $this->id(self::MIA))));

        self::assertNotNull($row);
        self::assertSame([null, 'Customer', NeedsYouKind::Input], [$row->turnUserId, $row->turnLabel, $row->kind]);
    }

    #[Test]
    public function aPersonsTurnKeepsNoLabelEvenWhenTheTurnCarriesOne(): void
    {
        $row = $this->resolver(new Turn($this->id(self::OMAR), 'Customer', NeedsYouKind::Input))->rowFor($this->facts($this->ticket()));

        self::assertNotNull($row);
        self::assertNotNull($row->turnUserId);
        self::assertSame([self::OMAR, null], [$row->turnUserId->toString(), $row->turnLabel]);
    }

    #[Test]
    public function aDoneTicketGetsNoRowEvenWithAQuestionOpenAndTheRulesAreNotAsked(): void
    {
        $rules = new FixedTurnRules(new Turn($this->id(self::MIA), null, NeedsYouKind::Answer));
        $ticket = new TicketState('step', 'done', 'done', null, askedUserId: $this->id(self::MIA), reporterId: $this->id(self::MIA));

        self::assertNull(new TurnRowResolver($rules, new RecordingNeedsYouScore())->rowFor($this->facts($ticket)));
        self::assertSame(0, $rules->timesAsked);
    }

    #[Test]
    public function aTicketThatIsNobodysTurnGetsNoRow(): void
    {
        $ticket = new TicketState('step', 'done', 'doing', null, reporterId: $this->id(self::MIA));

        self::assertNull($this->resolver(Turn::nobody())->rowFor($this->facts($ticket)));
    }

    #[Test]
    public function theCheckerIsTheCheckUserOnlyWhileTheTicketNeedsACheck(): void
    {
        $resolver = $this->resolver(new Turn($this->id(self::OMAR), null, NeedsYouKind::Build));
        $needingACheck = $resolver->rowFor($this->facts($this->ticket(isCheckNeeded: true, checkerId: $this->id(self::SARA))));
        $checked = $resolver->rowFor($this->facts($this->ticket(isCheckNeeded: false, checkerId: $this->id(self::SARA))));
        $withoutAChecker = $resolver->rowFor($this->facts($this->ticket(isCheckNeeded: true)));

        self::assertNotNull($needingACheck?->checkUserId);
        self::assertSame(self::SARA, $needingACheck->checkUserId->toString());
        self::assertNotNull($checked);
        self::assertNull($checked->checkUserId);
        self::assertNotNull($withoutAChecker);
        self::assertNull($withoutAChecker->checkUserId);
    }

    /**
     * @return iterable<string, array{?string, ?string, ReleasePlacement}>
     */
    public static function releasesAndTheirPlacement(): iterable
    {
        yield 'no release' => [null, '2027.1', ReleasePlacement::NoRelease];
        yield 'no release and no current one' => [null, null, ReleasePlacement::NoRelease];
        yield 'the current release' => ['2027.1', '2027.1', ReleasePlacement::InCurrentRelease];
        yield 'another release' => ['2027.2', '2027.1', ReleasePlacement::InOtherRelease];
        yield 'a release while the organization has no current one' => ['2027.2', null, ReleasePlacement::InOtherRelease];
    }

    #[Test]
    #[DataProvider('releasesAndTheirPlacement')]
    public function theScoreIsAskedWithTheKindPriorityPlacementAndRiskFlag(?string $release, ?string $currentRelease, ReleasePlacement $placement): void
    {
        $score = new RecordingNeedsYouScore();
        $resolver = new TurnRowResolver(new FixedTurnRules(new Turn($this->id(self::SARA), null, NeedsYouKind::Check)), $score);

        $row = $resolver->rowFor($this->facts($this->ticket(priority: 'major', release: $release), $currentRelease, isRiskCheck: true));

        self::assertSame([NeedsYouKind::Check, 'major', $placement, true], $score->lastAsked);
        self::assertSame(RecordingNeedsYouScore::SCORE, $row?->baseScore);
    }

    private function ticket(bool $isCheckNeeded = false, ?UserId $checkerId = null, ?string $priority = null, ?string $release = null): TicketState
    {
        return new TicketState(
            'step',
            'progress',
            'doing',
            new TeamId(self::SHOP_TEAM),
            assigneeId: $this->id(self::OMAR),
            reporterId: $this->id(self::MIA),
            isCheckNeeded: $isCheckNeeded,
            checkerId: $checkerId,
            priority: $priority,
            release: $release,
        );
    }

    private function facts(TicketState $ticket, ?string $currentRelease = null, bool $isRiskCheck = false): TicketTurnFacts
    {
        return new TicketTurnFacts(new TicketId(self::TICKET), self::SHOP_PROJECT, $ticket, new TeamFacts($this->id(self::LENA), 'Lena'), 'all', $currentRelease, $isRiskCheck);
    }

    private function resolver(Turn $turn): TurnRowResolver
    {
        return new TurnRowResolver(new FixedTurnRules($turn), new RecordingNeedsYouScore());
    }

    private function id(string $value): UserId
    {
        return new UserId($value);
    }
}
