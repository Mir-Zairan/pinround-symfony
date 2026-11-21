<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\MyReports;

use App\Insight\Api\MyReport;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\OpenQuestion;
use App\Ticketing\Api\OpenQuestionQueries;
use App\Ticketing\Api\PinProgress;
use App\Ticketing\Api\PinProgressQueries;
use App\Web\UI\Controller\HomeController;
use App\Web\UI\MyReports\ReportCard;
use App\Web\UI\MyReports\ReportCards;
use App\Web\UI\MyReports\ReportStage;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Mia's reports on a page whose time is 6 Nov 2026 12:00 UTC, a fixed day that is not today. Lena left Acme Foods, so
 * the member names have none for her.
 */
final class ReportCardsTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f1a01';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f1a51';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f1a52';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f1a53';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-0000000f1c01';
    private const string DOING_STEP = '0197a3c0-0000-7000-8000-0000000f1c02';
    private const string WAITING_STEP = '0197a3c0-0000-7000-8000-0000000f1c03';
    private const string CHECKING_STEP = '0197a3c0-0000-7000-8000-0000000f1c04';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000f1c05';
    private const string ANOTHER_ORGANIZATIONS_STEP = '0197a3c0-0000-7000-8000-0000000f1c99';

    /** @var list<list<string>> the user ids of each displayNamesOf call */
    private array $nameLookups = [];

    private int $stepListings = 0;

    /** @var list<array{string, list<string>}> the asked person's id and the ticket ids of each openQuestionsTo call */
    private array $questionLookups = [];

    /** @var list<list<string>> the ticket ids of each pinProgressOf call */
    private array $pinProgressLookups = [];

    /** @var array<string, OpenQuestion> the open question of a ticket, by ticket id, and to whom it is in askedIds */
    private array $openQuestions = [];

    /** @var array<string, string> the asked person's id, by ticket id */
    private array $askedIds = [];

    /** @var array<string, PinProgress> by ticket id */
    private array $pinProgress = [];

    #[Test]
    public function eachReportGetsTheCardOfItsGroupAndStep(): void
    {
        $reports = [
            self::report(1, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::OMAR, 'build']),
            self::report(2, self::DOING_STEP, '2026-10-01 09:00:00', turn: [self::MIA, 'answer'], isQuestionToReporter: true),
            self::report(3, self::WAITING_STEP, '2026-10-20 12:00:00', turnLabel: 'Customer'),
            self::report(4, self::DONE_STEP, '2026-11-05 09:00:00', turn: [self::OMAR, 'build'], closedAt: '2026-11-05 09:00:00'),
            self::report(5, self::CHECKING_STEP, '2026-11-01 09:00:00', turn: [self::LENA, 'test']),
            self::report(6, self::TO_DO_STEP, '2026-11-05 09:00:00', turn: [self::OMAR, 'answer']),
        ];

        $cards = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $reports, new \DateTimeImmutable(self::NOW));

        self::assertSame([self::ticketIdOf(1), self::ticketIdOf(2), self::ticketIdOf(3), self::ticketIdOf(4), self::ticketIdOf(5), self::ticketIdOf(6)], array_keys($cards));
        self::assertSame(
            [
                ['SHOP-1', 'Ticket 1', '/t/SHOP-1', 'changed recently', false, ReportStage::Building, false, 'Omar Haddad', 'Omar builds it', false],
                ['SHOP-2', 'Ticket 2', '/t/SHOP-2', 'updated 1 Oct', false, ReportStage::Building, true, 'Mia Lund', 'A question waits for your answer', true],
                ['SHOP-3', 'Ticket 3', '/t/SHOP-3', 'no change for 17 days', true, ReportStage::Building, true, null, 'Waiting on Customer', false],
                ['SHOP-4', 'Ticket 4', '/t/SHOP-4', 'changed recently', false, ReportStage::Done, false, null, 'Done', false],
                ['SHOP-5', 'Ticket 5', '/t/SHOP-5', 'updated 1 Nov', false, ReportStage::Checking, false, null, 'Someone tests it', false],
                ['SHOP-6', 'Ticket 6', '/t/SHOP-6', 'changed recently', false, ReportStage::Reported, true, 'Omar Haddad', 'Omar answers a question', false],
            ],
            array_values(array_map(static fn (ReportCard $card): array => [
                $card->key, $card->title, $card->url, $card->sideText, $card->isStuck, $card->stage, $card->isWaiting, $card->avatarName, $card->nowText, $card->isAnswerOffered,
            ], $cards)),
        );
    }

    #[Test]
    public function theTurnPeoplesNamesAreReadOnceForThePageAndTheStepsOnce(): void
    {
        $reports = [
            self::report(1, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::OMAR, 'build']),
            self::report(2, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::OMAR, 'test']),
            self::report(3, self::DOING_STEP, '2026-11-05 09:00:00', turnLabel: 'Customer'),
            self::report(4, self::CHECKING_STEP, '2026-11-05 09:00:00', turn: [self::LENA, 'test']),
        ];

        $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $reports, new \DateTimeImmutable(self::NOW));

        self::assertSame([[self::OMAR, self::LENA]], $this->nameLookups);
        self::assertSame(1, $this->stepListings);
        self::assertSame([], $this->questionLookups, 'no report needs Mia, so no question is read');
        self::assertSame([[self::ticketIdOf(1), self::ticketIdOf(2), self::ticketIdOf(3), self::ticketIdOf(4)]], $this->pinProgressLookups);
    }

    /**
     * SHOP-1 and SHOP-2 wait on Mia's answer: Omar asked SHOP-1's question; Lena, who left, asked SHOP-2's. SHOP-3's
     * question is to Omar, and SHOP-4 is done with a stale question to Mia, so neither is read.
     */
    #[Test]
    public function aQuestionThatWaitsOnTheViewerIsQuotedWithItsAskersNameAndAvatar(): void
    {
        $this->openQuestions = [
            self::ticketIdOf(1) => new OpenQuestion(new UserId(self::OMAR), "Which order?\nThe one from May?"),
            self::ticketIdOf(2) => new OpenQuestion(new UserId(self::LENA), 'Which browser?'),
            self::ticketIdOf(3) => new OpenQuestion(new UserId(self::MIA), 'Is it fixed?'),
        ];
        $this->askedIds = [self::ticketIdOf(1) => self::MIA, self::ticketIdOf(2) => self::MIA, self::ticketIdOf(3) => self::OMAR];
        $reports = [
            self::report(1, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::MIA, 'answer'], isQuestionToReporter: true),
            self::report(2, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::MIA, 'answer'], isQuestionToReporter: true),
            self::report(3, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::OMAR, 'answer']),
            self::report(4, self::DONE_STEP, '2026-11-05 09:00:00', turn: [self::MIA, 'answer'], isQuestionToReporter: true, closedAt: '2026-11-05 09:00:00'),
        ];

        $cards = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $reports, new \DateTimeImmutable(self::NOW));

        self::assertSame(
            [
                ['Omar Haddad', 'Omar asks: “Which order? The one from May?”'],
                [null, 'Someone asks: “Which browser?”'],
                ['Omar Haddad', 'Omar answers a question'],
                [null, 'Done'],
            ],
            array_values(array_map(static fn (ReportCard $card): array => [$card->avatarName, $card->nowText], $cards)),
        );
        self::assertSame([[self::MIA, [self::ticketIdOf(1), self::ticketIdOf(2)]]], $this->questionLookups);
        self::assertSame([[self::MIA, self::OMAR, self::LENA]], $this->nameLookups, 'the askers are named in the turn people\'s batch');
    }

    #[Test]
    public function aQuestionWithNoVisibleTextKeepsThePlainLineAndTheTurnPersonsAvatar(): void
    {
        $this->openQuestions = [self::ticketIdOf(1) => new OpenQuestion(new UserId(self::OMAR), " \n\t ")];
        $this->askedIds = [self::ticketIdOf(1) => self::MIA];
        $report = self::report(1, self::DOING_STEP, '2026-11-05 09:00:00', turn: [self::MIA, 'answer'], isQuestionToReporter: true);

        $card = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [$report], new \DateTimeImmutable(self::NOW))[self::ticketIdOf(1)];

        self::assertSame(['Mia Lund', 'A question waits for your answer'], [$card->avatarName, $card->nowText]);
    }

    #[Test]
    public function aReportWithAChecklistShowsItsPinProgressAndOneWithoutShowsNone(): void
    {
        $this->pinProgress = [self::ticketIdOf(1) => new PinProgress(1, 2), self::ticketIdOf(3) => new PinProgress(0, 1)];
        $reports = [
            self::report(1, self::CHECKING_STEP, '2026-11-05 09:00:00'),
            self::report(2, self::DOING_STEP, '2026-11-05 09:00:00'),
            self::report(3, self::DONE_STEP, '2026-11-05 09:00:00', closedAt: '2026-11-05 09:00:00'),
        ];

        $cards = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $reports, new \DateTimeImmutable(self::NOW));

        self::assertSame(['1 of 2 pins OK', null, '0 of 1 pin OK'], array_values(array_map(static fn (ReportCard $card): ?string => $card->pinProgressText, $cards)));
    }

    #[Test]
    public function aStepIsFoundWhateverTheCaseOfItsId(): void
    {
        $report = self::report(1, strtoupper(self::CHECKING_STEP), '2026-11-05 09:00:00');

        $cards = $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [$report], new \DateTimeImmutable(self::NOW));

        self::assertSame(ReportStage::Checking, $cards[self::ticketIdOf(1)]->stage);
    }

    #[Test]
    public function anEmptyPageReadsNothing(): void
    {
        self::assertSame([], $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [], new \DateTimeImmutable(self::NOW)));
        self::assertSame([[], 0, [], []], [$this->nameLookups, $this->stepListings, $this->questionLookups, $this->pinProgressLookups]);
    }

    #[Test]
    public function aReportInAStepOfNoneOfTheOrganizationsStepsIsRefused(): void
    {
        $reports = [
            self::report(1, self::DOING_STEP, '2026-11-05 09:00:00'),
            self::report(2, self::ANOTHER_ORGANIZATIONS_STEP, '2026-11-05 09:00:00'),
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The step of the report SHOP-2 is not a step of the organization.');

        $this->cards()->cardsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $reports, new \DateTimeImmutable(self::NOW));
    }

    private function cards(): ReportCards
    {
        $routes = new RouteCollection();
        $routes->add(HomeController::TICKET_ROUTE, new Route('/t/{key}'));

        return new ReportCards($this->memberNames(), $this->steps(), $this->openQuestions(), $this->pinProgress(), new UrlGenerator($routes, new RequestContext()));
    }

    /**
     * Like the real one: it refuses more than MAX_USER_IDS ids, runs no query for none, and leaves out a person who
     * is no member (Lena).
     */
    private function memberNames(): MemberNameQueries
    {
        return new readonly class($this->recordNameLookup(...)) implements MemberNameQueries {
            /**
             * @param \Closure(list<string>): void $recordLookup
             */
            public function __construct(private \Closure $recordLookup)
            {
            }

            public function displayNamesOf(OrganizationId $organizationId, array $userIds): array
            {
                if (\count($userIds) > self::MAX_USER_IDS) {
                    throw new \InvalidArgumentException('Too many user ids.');
                }
                if ([] === $userIds) {
                    return [];
                }
                ($this->recordLookup)(array_map(static fn (UserId $userId): string => $userId->toString(), $userIds));
                $members = [ReportCardsTest::omar() => 'Omar Haddad', ReportCardsTest::mia() => 'Mia Lund'];
                $names = [];
                foreach ($userIds as $userId) {
                    if (isset($members[$userId->toString()])) {
                        $names[$userId->toString()] = $members[$userId->toString()];
                    }
                }
                ksort($names);

                return $names;
            }
        };
    }

    /**
     * The organization's steps, the checking one stored with an uppercase id.
     */
    private function steps(): StepQueries
    {
        return new readonly class($this->recordStepListing(...)) implements StepQueries {
            /**
             * @param \Closure(): void $recordListing
             */
            public function __construct(private \Closure $recordListing)
            {
            }

            public function stepsFor(TeamId $teamId): array
            {
                throw new \LogicException('My reports reads no team\'s steps.');
            }

            public function moveTargetsFor(TeamId $teamId): array
            {
                throw new \LogicException('My reports reads no move targets.');
            }

            public function listedSteps(): array
            {
                ($this->recordListing)();

                return [
                    new ListedStepView(ReportCardsTest::toDoStep(), 'Backlog', 'todo', 'To do', null, false),
                    new ListedStepView(ReportCardsTest::doingStep(), 'In progress', 'doing', 'Doing', null, false),
                    new ListedStepView(ReportCardsTest::waitingStep(), 'On hold', 'wait', 'Waiting', null, false),
                    new ListedStepView(strtoupper(ReportCardsTest::checkingStep()), 'In test', 'check', 'Checking', null, false),
                    new ListedStepView(ReportCardsTest::doneStep(), 'Done', 'done', 'Done', null, false),
                ];
            }

            public function groupOf(string $stepId): ?string
            {
                throw new \LogicException('My reports reads the steps\' groups from the listed steps.');
            }
        };
    }

    /**
     * Like the real one: it refuses more than MAX_TICKET_IDS ids, runs no query for none, and leaves out a question to
     * someone else.
     */
    private function openQuestions(): OpenQuestionQueries
    {
        return new readonly class($this->answerQuestionLookup(...)) implements OpenQuestionQueries {
            /**
             * @param \Closure(string, list<string>): array<string, OpenQuestion> $answerLookup
             */
            public function __construct(private \Closure $answerLookup)
            {
            }

            public function openQuestionsTo(UserId $askedId, array $ticketIds): array
            {
                if (\count($ticketIds) > self::MAX_TICKET_IDS) {
                    throw new \InvalidArgumentException('Too many ticket ids.');
                }
                if ([] === $ticketIds) {
                    return [];
                }

                return ($this->answerLookup)($askedId->toString(), array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds));
            }
        };
    }

    /**
     * Like the real one: it refuses more than MAX_TICKET_IDS ids and runs no query for none.
     */
    private function pinProgress(): PinProgressQueries
    {
        return new readonly class($this->answerPinProgressLookup(...)) implements PinProgressQueries {
            /**
             * @param \Closure(list<string>): array<string, PinProgress> $answerLookup
             */
            public function __construct(private \Closure $answerLookup)
            {
            }

            public function pinProgressOf(array $ticketIds): array
            {
                if (\count($ticketIds) > self::MAX_TICKET_IDS) {
                    throw new \InvalidArgumentException('Too many ticket ids.');
                }
                if ([] === $ticketIds) {
                    return [];
                }

                return ($this->answerLookup)(array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds));
            }
        };
    }

    /**
     * Records the lookup and answers the open questions of the tickets that are to $askedId.
     *
     * @param list<string> $ticketIds
     *
     * @return array<string, OpenQuestion>
     */
    private function answerQuestionLookup(string $askedId, array $ticketIds): array
    {
        $this->questionLookups[] = [$askedId, $ticketIds];
        $questions = [];
        foreach ($ticketIds as $ticketId) {
            if (isset($this->openQuestions[$ticketId]) && ($this->askedIds[$ticketId] ?? null) === $askedId) {
                $questions[$ticketId] = $this->openQuestions[$ticketId];
            }
        }

        return $questions;
    }

    /**
     * @param list<string> $ticketIds
     *
     * @return array<string, PinProgress>
     */
    private function answerPinProgressLookup(array $ticketIds): array
    {
        $this->pinProgressLookups[] = $ticketIds;

        return array_intersect_key($this->pinProgress, array_flip($ticketIds));
    }

    /**
     * @param list<string> $userIds
     */
    private function recordNameLookup(array $userIds): void
    {
        $this->nameLookups[] = $userIds;
    }

    private function recordStepListing(): void
    {
        ++$this->stepListings;
    }

    public static function omar(): string
    {
        return self::OMAR;
    }

    public static function mia(): string
    {
        return self::MIA;
    }

    public static function toDoStep(): string
    {
        return self::TO_DO_STEP;
    }

    public static function doingStep(): string
    {
        return self::DOING_STEP;
    }

    public static function waitingStep(): string
    {
        return self::WAITING_STEP;
    }

    public static function checkingStep(): string
    {
        return self::CHECKING_STEP;
    }

    public static function doneStep(): string
    {
        return self::DONE_STEP;
    }

    /**
     * A report of Mia's made on 1 Sep 2026; times are UTC.
     *
     * @param array{string, string}|null $turn the turn person's id and the turn's kind
     */
    private static function report(int $number, string $stepId, string $updatedAt, ?array $turn = null, ?string $turnLabel = null, bool $isQuestionToReporter = false, ?string $closedAt = null): MyReport
    {
        return new MyReport(
            new TicketId(self::ticketIdOf($number)),
            'SHOP-'.$number,
            'Ticket '.$number,
            $stepId,
            new \DateTimeImmutable('2026-09-01 09:00:00 UTC'),
            new \DateTimeImmutable($updatedAt.' UTC'),
            null === $closedAt ? null : new \DateTimeImmutable($closedAt.' UTC'),
            null === $turn && null === $turnLabel ? null : 'doing',
            null === $turn ? null : new UserId($turn[0]),
            $turnLabel,
            null === $turn ? (null === $turnLabel ? null : 'input') : $turn[1],
            $isQuestionToReporter,
        );
    }

    private static function ticketIdOf(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000f1b%02d', $number);
    }
}
