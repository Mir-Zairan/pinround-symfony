<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Intake;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeAssist;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckRefused;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Api\IntakeSuggestion;
use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiRequesters;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Intake\IntakeJobQueue;
use App\Assist\Application\Intake\IntakeRequests;
use App\Assist\Application\Intake\IntakeSuggestionMapper;
use App\Assist\Application\IntakeAssistService;
use App\Assist\Application\Suggestions;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\Assist\Infrastructure\Uid\UuidV7IntakeCheckIds;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\Uid\UuidV7;

/**
 * "Check with AI" as the container wires it, as pinround_app in a transaction under Acme Foods that each test rolls
 * back, with the job queue on the in-memory async_ai transport and the check states on the test Valkey. Lena leads the
 * Shop team that owns SHOP and Vera is a viewer in it; Omar is in Acme Foods but in no team; Sara is an admin of Acme
 * Foods; Mia has an account but is no member; Raj leads Beta Bakery's own team. The clock is frozen in March 2027.
 */
final class IntakeAssistServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c9a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c9a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000c9a21';
    private const string BAKERY_TEAM = '0197a3c0-0000-7000-8000-0000000c9a22';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c9a41';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000c9a42';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c9a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c9a12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c9a13';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000c9a14';
    private const string VERA = '0197a3c0-0000-7000-8000-0000000c9a15';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000c9a16';
    private const string STORED_CHECK = '0197a3c0-0000-7000-8000-0000000c9a31';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string AI_ON_IN_THE_CLOUD = '{"on": true, "where": "cloud"}';
    private const string USAGE_LIMITER_PREFIX = 'ai_calls_per_user-';
    private const int CALL_LIMIT = 30;
    private const string FAILED_SENTENCE = 'The AI answer failed. Try again.';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::VERA => ['Vera', 'viewer', 'other'],
        ]);
        self::shopTeamRows()->add(self::BETA_BAKERY, self::BAKERY_TEAM, self::BAKERY_PROJECT, self::RAJ, [self::RAJ => ['Raj', 'lead', 'developer']]);
        foreach ([self::OMAR => 'Omar', self::MIA => 'Mia', self::SARA => 'Sara'] as $userId => $name) {
            self::shopTeamRows()->addUser($userId, $name);
        }
        self::transactions()->committedAs(self::ACME_FOODS, static function (): void {
            foreach ([self::OMAR => '{}', self::SARA => '{admin}'] as $userId => $orgRoles) {
                self::connection()->executeStatement(
                    "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $userId, $orgRoles],
                );
            }
            self::connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, '{}'::jsonb, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS],
            );
        });
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->forgetCheckStatesAndCountedCalls();
        self::removeSuggestions();
    }

    protected function tearDown(): void
    {
        $this->forgetCheckStatesAndCountedCalls();
        self::removeSuggestions();
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function aDraftOmarChecksQueuesOneJobWithIdsOnlyAndKeepsTheCheckQueuedForHim(): void
    {
        $checkId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());

        $sent = $this->asyncAi()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new GenerateIntakeSuggestion(self::ACME_FOODS, $checkId->toString(), self::OMAR), $sent[0]->getMessage());
        self::assertEquals(new TenantStamp(new OrganizationId(self::ACME_FOODS)), $sent[0]->last(TenantStamp::class));
        self::assertSame('2027-03-15 10:00:00', UuidV7::fromString($checkId->toString())->getDateTime()->format('Y-m-d H:i:s'));
        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(new OrganizationId(self::ACME_FOODS), $checkId, new UserId(self::OMAR)));
        self::assertSame([], $this->fakeProvider()->calls(), 'Queueing asked the AI.');
        self::assertSame(0, $this->countedCallsOf(self::OMAR), 'Queueing counted an AI call.');
    }

    /** ADR-0013: a viewer may report, so they may check a report too. */
    #[Test]
    public function aViewerOfTheShopTeamMayCheckADraft(): void
    {
        $checkId = $this->checkAs(self::VERA, self::AI_ON_IN_THE_CLOUD, self::draft());

        self::assertCount(1, $this->asyncAi()->getSent());
        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(new OrganizationId(self::ACME_FOODS), $checkId, new UserId(self::VERA)));
    }

    /**
     * Each text exactly at its cap, so a cap one too small refuses it.
     */
    #[Test]
    public function aDraftWithEveryTextAtItsCapIsQueued(): void
    {
        $draft = new DraftReport(self::SHOP_PROJECT, str_repeat('é', 200), str_repeat('d', 50_000), array_fill(0, 100, str_repeat('n', 1_000)));

        $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, $draft);

        self::assertCount(1, $this->asyncAi()->getSent());
    }

    /**
     * @return iterable<string, array{string, string, DraftReport, string, string}>
     */
    public static function refusedChecks(): iterable
    {
        $tooLong = 'draft_too_long';

        yield 'a draft that mentions security, on its way to cloud AI' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay does nothing.', ['The password field is empty.']), 'security_sensitive_draft_to_cloud', 'This report mentions security, so it stays out of cloud AI.'];
        yield 'AI off' => [self::OMAR, '{"on": false}', self::draft(), 'ai_off', 'Your admin turned AI help off.'];
        yield 'a draft that mentions security with AI off' => [self::OMAR, '{"on": false}', new DraftReport(self::SHOP_PROJECT, 'Password reset mail never comes', 'I asked twice.'), 'ai_off', 'Your admin turned AI help off.'];
        yield 'leads only, Omar leads no team' => [self::OMAR, '{"on": true, "where": "cloud", "who": "leads_only"}', self::draft(), 'leads_only', 'Your admin allows AI help for leads only.'];
        yield 'a person who is no member' => [self::MIA, self::AI_ON_IN_THE_CLOUD, self::draft(), 'not_a_member', 'AI help works only in your own organization.'];
        yield 'a member of another organization' => [self::RAJ, self::AI_ON_IN_THE_CLOUD, self::draft(), 'not_a_member', 'AI help works only in your own organization.'];
        yield 'a title one character over its cap' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, str_repeat('é', 201), ''), $tooLong, 'The title is too long for AI help: 200 characters at most.'];
        yield 'details one character over their cap' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, 'Pay', str_repeat('d', 50_001)), $tooLong, 'The details are too long for AI help: 50,000 characters at most.'];
        yield 'one pin too many' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, 'Pay', '', array_fill(0, 101, 'n')), $tooLong, 'Too many pins for AI help: 100 at most.'];
        yield 'one pin too many, the last not UTF-8, refused before the notes are read' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, "\xC3\x28", '', [...array_fill(0, 100, 'n'), "\xC3\x28"]), $tooLong, 'Too many pins for AI help: 100 at most.'];
        yield 'details over their cap and not UTF-8' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, 'Pay', str_repeat('d', 50_000)."\xC3\x28"), $tooLong, 'The details are too long for AI help: 50,000 characters at most.'];
        yield 'a pin note one character over its cap' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, 'Pay', '', ['ok', str_repeat('n', 1_001)]), $tooLong, 'A pin note is too long for AI help: 1,000 characters at most.'];
        yield 'a pin note that is not UTF-8' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(self::SHOP_PROJECT, 'Pay', '', ["\xC3\x28"]), 'unreadable_draft', 'This report has text AI help cannot read.'];
        yield 'a project id in upper case' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport(strtoupper(self::SHOP_PROJECT), 'Pay', ''), 'unknown_project', 'Choose a project first.'];
        yield 'no project id' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, new DraftReport('', 'Pay', ''), 'unknown_project', 'Choose a project first.'];
    }

    #[Test]
    #[DataProvider('refusedChecks')]
    public function aRefusedCheckSaysWhyAndQueuesKeepsAndCountsNothing(string $requesterId, string $aiJson, DraftReport $draft, string $expectedCode, string $expectedSentence): void
    {
        try {
            $this->checkAs($requesterId, $aiJson, $draft);
            self::fail('A refused check was queued.');
        } catch (IntakeCheckRefused $refusal) {
            self::assertSame([$expectedCode, $expectedSentence], [$refusal->refusalCode, $refusal->getMessage()]);
        }

        self::assertSame([], $this->asyncAi()->getSent());
        self::assertSame([], $this->checkStateKeys());
        self::assertSame([], $this->fakeProvider()->calls());
        self::assertSame(0, $this->countedCallsOf($requesterId));
    }

    #[Test]
    public function aPersonWithNoAiCallLeftIsRefusedWithTheLimitLineAndQueuesAndChangesNothing(): void
    {
        $this->useUpCallsOf(self::OMAR, self::CALL_LIMIT);

        try {
            $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
            self::fail('A check over the limit was queued.');
        } catch (IntakeCheckRefused $refusal) {
            self::assertSame(
                [IntakeCheckRefused::TOO_MANY_AI_REQUESTS, 'Too many AI requests. Try again in a minute.'],
                [$refusal->refusalCode, $refusal->getMessage()],
            );
        }

        self::assertSame([], $this->asyncAi()->getSent());
        self::assertSame([], $this->checkStateKeys());
        self::assertSame(self::CALL_LIMIT, $this->countedCallsOf(self::OMAR));
    }

    /**
     * Each open check keeps its draft on the state Valkey for up to an hour, so a person may have three at once.
     */
    #[Test]
    public function aFourthOpenCheckIsRefusedWithTheLimitLineAndQueuesAndKeepsNothingUntilOneEnds(): void
    {
        $checkIds = [];
        for ($check = 1; $check <= 3; ++$check) {
            $checkIds[] = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        }
        $statesBefore = $this->checkStates();
        self::assertCount(7, $statesBefore, 'Three states, three drafts and Omar\'s open checks.');

        try {
            $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
            self::fail('A fourth open check was queued.');
        } catch (IntakeCheckRefused $refusal) {
            self::assertSame(
                ['too_many_open_checks', 'You have 3 AI checks running. Wait for one to finish.'],
                [$refusal->refusalCode, $refusal->getMessage()],
            );
        }

        self::assertSame($statesBefore, $this->checkStates());
        self::assertCount(3, $this->asyncAi()->getSent());
        $this->checkAs(self::LENA, self::AI_ON_IN_THE_CLOUD, self::draft());
        $this->requests()->release(new OrganizationId(self::ACME_FOODS), $checkIds[0], new UserId(self::OMAR));
        $fourthId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(new OrganizationId(self::ACME_FOODS), $fourthId, new UserId(self::OMAR)));
        self::assertCount(5, $this->asyncAi()->getSent());
    }

    #[Test]
    public function aQueueThatFailsLeavesNoCheckStateSoNoDraftWaitsAndThePersonMayTryAgain(): void
    {
        $queueFailure = new \RuntimeException('The queue cannot be reached.');
        $failingQueue = new readonly class($queueFailure) implements IntakeJobQueue {
            public function __construct(private \RuntimeException $queueFailure)
            {
            }

            public function queue(GenerateIntakeSuggestion $generateIntakeSuggestion): void
            {
                throw $this->queueFailure;
            }
        };

        try {
            $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft(), $this->intakeAssistWith($failingQueue));
            self::fail('The queue failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame($queueFailure, $failure);
        }

        self::assertSame([], $this->checkStateKeys(), 'The state and its draft stayed for an hour.');
        $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        self::assertCount(1, $this->asyncAi()->getSent());
    }

    #[Test]
    public function aQueuedAndAFailedCheckAreShownToTheirRequester(): void
    {
        $queuedId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        $failedId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        $this->requests()->recordFailure(new OrganizationId(self::ACME_FOODS), $failedId, new UserId(self::OMAR), self::FAILED_SENTENCE);

        self::assertEquals(IntakeCheckView::queued(), $this->checkOfAs(self::OMAR, self::ACME_FOODS, $queuedId));
        self::assertEquals(IntakeCheckView::failed(self::FAILED_SENTENCE), $this->checkOfAs(self::OMAR, self::ACME_FOODS, $failedId));
    }

    /**
     * The page polls every 3 s: the stored suggestion and the membership without the roles, nothing more.
     */
    #[Test]
    public function aPollOfAQueuedCheckSendsTwoQueries(): void
    {
        $queuedId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        $intakeAssist = $this->intakeAssist();
        $debugData = $this->debugData();

        [$polled, $sentSql] = self::transactions()->rolledBackAs(self::ACME_FOODS, static function () use ($intakeAssist, $debugData, $queuedId): array {
            $debugData->reset();
            $polled = $intakeAssist->checkOf($queuedId, new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));

            return [$polled, self::sqlOf($debugData)];
        });

        self::assertEquals(IntakeCheckView::queued(), $polled);
        self::assertCount(2, $sentSql, implode("\n", $sentSql));
    }

    #[Test]
    public function aStoredSuggestionIsShownToItsRequesterAsDoneEvenWhileItsStateIsStillQueued(): void
    {
        $this->storeOmarsSuggestion();
        $this->requests()->open(new OrganizationId(self::ACME_FOODS), self::storedCheck(), new UserId(self::OMAR), self::draft());

        $done = $this->checkOfAs(self::OMAR, self::ACME_FOODS, self::storedCheck());

        self::assertNotNull($done);
        self::assertSame(IntakeCheckView::DONE, $done->status);
        self::assertEquals(self::suggestion(), $done->suggestion);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function othersThanTheRequester(): iterable
    {
        yield 'Lena, another member of Acme Foods' => [self::LENA, self::ACME_FOODS];
        yield 'Sara, an admin of Acme Foods' => [self::SARA, self::ACME_FOODS];
        yield 'Raj, under his own Beta Bakery' => [self::RAJ, self::BETA_BAKERY];
        yield 'Raj, naming Acme Foods' => [self::RAJ, self::ACME_FOODS];
        yield 'Mia, no member' => [self::MIA, self::ACME_FOODS];
    }

    #[Test]
    #[DataProvider('othersThanTheRequester')]
    public function anyoneButTheRequesterGetsNoCheckQueuedFailedOrDoneAndNothingChanges(string $viewerId, string $organizationId): void
    {
        $queuedId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        $failedId = $this->checkAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::draft());
        $this->requests()->recordFailure(new OrganizationId(self::ACME_FOODS), $failedId, new UserId(self::OMAR), self::FAILED_SENTENCE);
        $this->storeOmarsSuggestion();
        $statesBefore = $this->checkStates();
        $rowsBefore = $this->suggestionRows();
        self::assertCount(4, $statesBefore, 'Two states, the queued one\'s draft and Omar\'s open checks.');
        self::assertCount(1, $rowsBefore);

        foreach ([$queuedId, $failedId, self::storedCheck()] as $checkId) {
            self::assertNull($this->checkOfAs($viewerId, $organizationId, $checkId), $checkId->toString());
        }

        self::assertSame($statesBefore, $this->checkStates());
        self::assertSame($rowsBefore, $this->suggestionRows());
        self::assertNotNull($this->checkOfAs(self::OMAR, self::ACME_FOODS, self::storedCheck()), 'Omar must see his own, or the nulls prove nothing.');
    }

    #[Test]
    public function aCheckNobodyAskedForIsUnknownToEveryone(): void
    {
        $neverAsked = new IntakeCheckId('0197a3c0-0000-7000-8000-0000000c9a39');

        foreach ([self::OMAR, self::SARA] as $viewerId) {
            self::assertNull($this->checkOfAs($viewerId, self::ACME_FOODS, $neverAsked));
        }
    }

    #[Test]
    public function aRequesterWhoLeftTheOrganizationNoLongerGetsTheirCheck(): void
    {
        $this->storeOmarsSuggestion();
        $intakeAssist = $this->intakeAssist();

        $afterLeaving = self::transactions()->rolledBackAs(self::ACME_FOODS, static function () use ($intakeAssist): ?IntakeCheckView {
            self::connection()->executeStatement('DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?', [self::ACME_FOODS, self::OMAR]);

            return $intakeAssist->checkOf(self::storedCheck(), new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
        });

        self::assertNull($afterLeaving);
        self::assertNotNull($this->checkOfAs(self::OMAR, self::ACME_FOODS, self::storedCheck()), 'The rolled-back leave proves nothing if Omar sees nothing anyway.');
    }

    /**
     * Stores data.ai and checks the draft in one transaction under Acme Foods that is rolled back; the job and the check
     * state stay, as they are not in the database.
     */
    private function checkAs(string $requesterId, string $aiJson, DraftReport $draft, ?IntakeAssist $intakeAssist = null): IntakeCheckId
    {
        $intakeAssist ??= $this->intakeAssist();

        return self::transactions()->rolledBackAs(self::ACME_FOODS, static function () use ($intakeAssist, $requesterId, $aiJson, $draft): IntakeCheckId {
            self::connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb) WHERE organization_id = ?", [$aiJson, self::ACME_FOODS]);

            return $intakeAssist->suggest($draft, new UserId($requesterId), new OrganizationId(self::ACME_FOODS));
        });
    }

    private function checkOfAs(string $viewerId, string $organizationId, IntakeCheckId $checkId): ?IntakeCheckView
    {
        $intakeAssist = $this->intakeAssist();

        return self::transactions()->rolledBackAs($organizationId, static fn (): ?IntakeCheckView => $intakeAssist->checkOf($checkId, new UserId($viewerId), new OrganizationId($organizationId)));
    }

    private function storeOmarsSuggestion(): void
    {
        $suggestions = self::getContainer()->get('test.assist_suggestions');
        \assert($suggestions instanceof Suggestions);
        self::transactions()->committedAs(self::ACME_FOODS, static fn () => $suggestions->addIntake(
            self::storedCheck(),
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            self::suggestion(),
            new \DateTimeImmutable(self::NOW),
        ));
    }

    private static function suggestion(): IntakeSuggestion
    {
        return IntakeSuggestionMapper::fromCheckedData(
            ['summary' => 'Pay does nothing.', 'type' => 'Bug', 'priority' => 'Major', 'duplicates' => ['SHOP-7'], 'missing' => false, 'risky' => true],
            'meta-llama/Llama-3.1-8B-Instruct',
            'intake.v1',
        );
    }

    /**
     * @return list<array<string, mixed>> every ast_suggestion row of Acme Foods, as the migrator sees it
     */
    private function suggestionRows(): array
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = self::migrator()->transactional(static fn (Connection $migrator): array => $migrator->fetchAllAssociative('SELECT * FROM ast_suggestion ORDER BY id'));
        } finally {
            self::tenantContext()->clear();
        }

        return $rows;
    }

    private function intakeAssist(): IntakeAssist
    {
        $intakeAssist = self::getContainer()->get('test.assist_intake_assist');
        \assert($intakeAssist instanceof IntakeAssist);

        return $intakeAssist;
    }

    private function intakeAssistWith(IntakeJobQueue $intakeJobQueue): IntakeAssistService
    {
        $requesters = self::getContainer()->get('test.assist_ai_requesters');
        \assert($requesters instanceof AiRequesters);
        $aiTaskAdmission = self::getContainer()->get('test.assist_ai_task_admission');
        \assert($aiTaskAdmission instanceof AiTaskAdmission);
        $suggestions = self::getContainer()->get('test.assist_suggestions');
        \assert($suggestions instanceof Suggestions);

        return new IntakeAssistService($requesters, $aiTaskAdmission, $this->limit(), $this->requests(), $intakeJobQueue, $suggestions, new UuidV7IntakeCheckIds(new MockClock(self::NOW)));
    }

    private function requests(): IntakeRequests
    {
        $requests = self::getContainer()->get('test.assist_intake_requests');
        \assert($requests instanceof IntakeRequests);

        return $requests;
    }

    private function limit(): AiCallLimit
    {
        $limit = self::getContainer()->get('test.assist_ai_call_limit');
        \assert($limit instanceof AiCallLimit);

        return $limit;
    }

    private function useUpCallsOf(string $userId, int $calls): void
    {
        $caller = new ActorContext(new UserId($userId), new OrganizationId(self::ACME_FOODS), Roles::none());
        for ($call = 1; $call <= $calls; ++$call) {
            $this->limit()->consume($caller);
        }
    }

    private function countedCallsOf(string $userId): int
    {
        $window = self::getContainer()->get(ValkeyLimiterStorage::class)->fetch(self::USAGE_LIMITER_PREFIX.self::ACME_FOODS.':'.$userId);
        if (null === $window) {
            return 0;
        }
        self::assertInstanceOf(Window::class, $window);

        return $window->getHitCount();
    }

    /**
     * @return list<string> the check state, draft and open-check keys of both organizations, sorted
     */
    private function checkStateKeys(): array
    {
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        $keys = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            foreach (['intake_request', 'intake_draft', 'intake_open'] as $prefix) {
                $found = $stateValkey->keys($prefix.':'.$organizationId.':*');
                $keys = [...$keys, ...(\is_array($found) ? $found : [])];
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, mixed> each key's raw value (an open-check set's members with their scores) by key
     */
    private function checkStates(): array
    {
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        $states = [];
        foreach ($this->checkStateKeys() as $key) {
            $states[$key] = \Redis::REDIS_ZSET === $stateValkey->type($key) ? $stateValkey->zRange($key, 0, -1, ['withscores' => true]) : $stateValkey->get($key);
        }

        return $states;
    }

    private function forgetCheckStatesAndCountedCalls(): void
    {
        $keys = $this->checkStateKeys();
        if ([] !== $keys) {
            self::getContainer()->get('state_valkey.connection')->del($keys);
        }
        foreach ([self::LENA, self::OMAR, self::MIA, self::SARA, self::VERA, self::RAJ] as $userId) {
            self::getContainer()->get(ValkeyLimiterStorage::class)->delete(self::USAGE_LIMITER_PREFIX.self::ACME_FOODS.':'.$userId);
        }
    }

    private function asyncAi(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ai');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function fakeProvider(): FakeProvider
    {
        return self::getContainer()->get(FakeProvider::class);
    }

    private static function draft(): DraftReport
    {
        return new DraftReport(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay on the last step does nothing.', ['The button.']);
    }

    private static function storedCheck(): IntakeCheckId
    {
        return new IntakeCheckId(self::STORED_CHECK);
    }

    /** ast_suggestion is read-and-add for the app; the migrator owns it, and row-level security applies to it too. */
    private static function removeSuggestions(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::tenantContext()->set(new OrganizationId($organizationId));
            try {
                self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ast_suggestion WHERE organization_id = ?', [$organizationId]));
            } finally {
                self::tenantContext()->clear();
            }
        }
    }

    private static function removeRows(): void
    {
        self::removeSuggestions();
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::SARA, self::VERA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::entityManager()->getConnection();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
