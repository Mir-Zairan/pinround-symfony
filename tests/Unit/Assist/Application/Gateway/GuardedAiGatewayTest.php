<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\AiCallStatus;
use App\Assist\Application\AiDraft;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\Gateway\AskedTicket;
use App\Assist\Application\Gateway\GuardedAiGateway;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Application\PromptTicket;
use App\Assist\Application\UnknownAiProvider;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Prompt\OutputValidator;
use App\Assist\Infrastructure\Prompt\PromptBuilder;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\Intake\WordListDraftReportChecks;
use App\Ticketing\Domain\Intake\CheckReasons;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GuardedAiGateway with the real prompt builder, output validator and policy evaluator, and doubles of the ports that
 * behave as the real ones do. Omar is in the Shop team and drafts reports in the SHOP project, where he may see SHOP-3
 * and SHOP-5, for everyone; SHOP-6 is team-only in Pay, which he is not in, so it is never his candidate.
 */
final class GuardedAiGatewayTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a8a1';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a8b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a8b2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a8c1';
    private const string PAY_TEAM = '0197a3c0-0000-7000-8000-00000000a8c2';
    private const string CHECKOUT_TICKET = '0197a3c0-0000-7000-8000-00000000a8d1';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-00000000a8d2';
    private const string TICKET_TEXT = 'Pressing Pay on the last checkout step does nothing.';
    private const string SUMMARY = 'The Pay button on the last checkout step does nothing. People cannot finish an order.';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000a8e1';
    private const string INTAKE_JSON = '{"summary": "Pay does nothing.", "type": "Bug", "priority": "Major", "duplicates": [], "missing": false, "risky": false}';
    /** Names one sent candidate, one ticket of the organization that was not sent, and one that does not exist. */
    private const string INTAKE_JSON_WITH_DUPLICATES = '{"summary": "Pay does nothing.", "type": "Bug", "priority": "Major", "duplicates": ["SHOP-6", "SHOP-5", "SHOP-99"], "missing": false, "risky": false}';

    private StoredAiSettings $aiSettings;
    private ListedAskedTickets $tickets;
    private CountingAiCallLimit $limit;
    private ScriptedProvider $provider;
    private RecordingMetering $metering;
    private ListedIntakeCandidates $candidates;

    protected function setUp(): void
    {
        $this->tickets = new ListedAskedTickets();
        $this->tickets->add(new TicketId(self::CHECKOUT_TICKET), new AskedTicket(new PromptTicket('Checkout fails', self::TICKET_TEXT), false), [self::LENA, self::OMAR]);
        $this->tickets->add(new TicketId(self::TEAM_ONLY_TICKET), new AskedTicket(new PromptTicket('Card numbers in the log', self::TICKET_TEXT), true), [self::LENA, self::OMAR]);
        $this->candidates = new ListedIntakeCandidates(new OrganizationId(self::ACME_FOODS));
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-3', 'Basket total is wrong'), self::facts(TicketVisibilityFacts::FOR_EVERYONE, self::PAY_TEAM));
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-5', 'Pay button does nothing'), self::facts(TicketVisibilityFacts::FOR_EVERYONE, null));
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-6', 'Refunds stay pending'), self::facts(TicketVisibilityFacts::TEAM_ONLY, self::PAY_TEAM));
        $this->limit = new CountingAiCallLimit();
        $this->metering = new RecordingMetering(new OrganizationId(self::ACME_FOODS));
        $this->givenSettings(self::settings());
        $this->givenProviderScript([self::SUMMARY]);
    }

    #[Test]
    public function aSummaryComesBackCheckedAndLabeledAndIsMeteredOnceAsAnswered(): void
    {
        $this->givenProviderScript(['  '.self::SUMMARY."\r\n"]);

        $result = $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertSame(
            [AiCallStatus::Answered, self::SUMMARY, null, 'meta-llama/Llama-3.1-8B-Instruct', 'huggingface', 'summary.v1', 'AI summary', null],
            [$result->status, $result->text, $result->data, $result->model, $result->provider, $result->promptVersion, $result->label, $result->failureReason],
        );
        self::assertTrue($result->isAnswered());
        self::assertCount(1, $this->provider->calls);
        self::assertStringContainsString(self::TICKET_TEXT, $this->provider->calls[0]['prompt']->content);
        self::assertSame([[AiCallStatus::Answered, 812, 64, AiFeature::Summary, self::OMAR]], $this->meteredRows());
        self::assertSame([self::ACME_FOODS.':'.self::OMAR => 1], $this->limit->counted);
    }

    /**
     * @return iterable<string, array{AiSettings, string, PolicyRefusal}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'AI off' => [self::settings(isOn: false), self::CHECKOUT_TICKET, PolicyRefusal::AiOff];
        yield 'leads only, Omar leads no team' => [self::settings(isForLeadsOnly: true), self::CHECKOUT_TICKET, PolicyRefusal::LeadsOnly];
        yield 'a ticket Omar may not see' => [self::settings(), '0197a3c0-0000-7000-8000-00000000a8d9', PolicyRefusal::CannotSeeTicket];
        yield 'a team-only ticket kept from cloud AI' => [self::settings(), self::TEAM_ONLY_TICKET, PolicyRefusal::RestrictedTicketToCloud];
        yield 'laptop AI only' => [self::settings(where: AiSettings::WHERE_LAPTOP), self::CHECKOUT_TICKET, PolicyRefusal::LaptopNotReady];
    }

    #[Test]
    #[DataProvider('refusedRequests')]
    public function aRefusedRequestSaysWhyAndSendsCountsAndMetersNothing(AiSettings $settings, string $ticketId, PolicyRefusal $expectedRefusal): void
    {
        $this->givenSettings($settings);

        try {
            $this->gateway()->run(self::summaryOf($ticketId), self::omar());
            self::fail('The gateway sent a request the policy refuses.');
        } catch (AiNotAllowed $refusal) {
            self::assertSame($expectedRefusal, $refusal->refusal);
            self::assertSame($expectedRefusal->sentence(), $refusal->getMessage());
        }

        self::assertSame([], $this->provider->calls);
        self::assertSame([], $this->metering->rows);
        self::assertSame([], $this->limit->counted);
        self::assertSame([], $this->aiSettings->keyReads);
    }

    #[Test]
    public function aViewerRefusedByThePolicyNeverHasTheTicketRead(): void
    {
        $this->givenSettings(self::settings(isOn: false));

        $this->expectException(AiNotAllowed::class);
        try {
            $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());
        } finally {
            self::assertSame([], $this->tickets->reads);
        }
    }

    #[Test]
    public function aLeadIsLetInWhenAiIsForLeadsOnly(): void
    {
        $this->givenSettings(self::settings(isForLeadsOnly: true));

        $result = $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::lena());

        self::assertSame(self::SUMMARY, $result->text);
        self::assertCount(1, $this->provider->calls);
    }

    #[Test]
    public function aTeamOnlyTicketGoesToCloudAiWhenTheAdminAllowsIt(): void
    {
        $this->givenSettings(self::settings(isRestrictedToCloudAllowed: true));

        $result = $this->gateway()->run(self::summaryOf(self::TEAM_ONLY_TICKET), self::omar());

        self::assertTrue($result->isAnswered());
        self::assertCount(1, $this->provider->calls);
    }

    #[Test]
    public function laptopFirstGoesToTheCloudBecauseTheServerNeverReachesALaptop(): void
    {
        $this->givenSettings(self::settings(where: AiSettings::WHERE_LAPTOP_FIRST));

        $result = $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertTrue($result->isAnswered());
        self::assertCount(1, $this->provider->calls);
    }

    #[Test]
    public function titleOnlyLeavesTheTicketTextOutOfThePrompt(): void
    {
        $this->givenSettings(self::settings(isTitleOnly: true));

        $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertCount(1, $this->provider->calls);
        self::assertStringContainsString('Title: Checkout fails', $this->provider->calls[0]['prompt']->content);
        self::assertStringNotContainsString(self::TICKET_TEXT, $this->provider->calls[0]['prompt']->content);
    }

    #[Test]
    public function anUnreadableOrganizationKeyRefusesTheCallAndNeverFallsBackToTheOperatorsKey(): void
    {
        $this->givenSettings(self::settings(provider: 'anthropic'), isKeyUnreadable: true);

        try {
            $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());
            self::fail('The gateway sent a call while the organization\'s key could not be read.');
        } catch (AiUnavailable $unavailable) {
            self::assertSame('anthropic', $unavailable->provider);
            self::assertSame('The organization\'s key for the AI provider anthropic cannot be read. An admin must enter it again.', $unavailable->getMessage());
        }

        self::assertSame([self::ACME_FOODS], $this->aiSettings->keyReads);
        self::assertSame([], $this->provider->calls);
        self::assertSame([], $this->metering->rows);
        self::assertSame([], $this->limit->counted);
    }

    #[Test]
    public function theOrganizationsStoredKeyGoesToItsChosenProviderAndModel(): void
    {
        $this->givenSettings(self::settings(provider: 'anthropic', providerModel: 'claude-haiku-4-5'), providerKey: 'sk-acme-foods-7731');

        $result = $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertCount(1, $this->provider->calls);
        $choice = $this->provider->calls[0]['choice'];
        self::assertSame(['anthropic', 'claude-haiku-4-5', 'sk-acme-foods-7731'], [$choice->provider->value, $choice->model, $choice->organizationKey()]);
        self::assertSame(['anthropic', 'claude-haiku-4-5'], [$result->provider, $result->model]);
    }

    #[Test]
    public function theOperatorsKeyIsUsedWhenTheOrganizationStoredNone(): void
    {
        $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertCount(1, $this->provider->calls);
        self::assertNull($this->provider->calls[0]['choice']->organizationKey());
    }

    #[Test]
    public function aProviderTheSettingsNameButPinroundDoesNotKnowSendsNothing(): void
    {
        $this->givenSettings(self::settings(provider: 'openai'));

        try {
            $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());
            self::fail('The gateway sent a call to a provider pinround does not know.');
        } catch (UnknownAiProvider $unknown) {
            self::assertSame('The AI settings name a provider that is not huggingface, anthropic or ollama.', $unknown->getMessage());
        }

        self::assertSame([], $this->provider->calls);
        self::assertSame([], $this->limit->counted);
    }

    #[Test]
    public function aPersonAtTheLimitIsRefusedBeforeTheProviderIsAsked(): void
    {
        for ($call = 1; $call <= CountingAiCallLimit::LIMIT; ++$call) {
            $this->limit->consume(self::omar());
        }

        try {
            $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());
            self::fail('A call past the limit was sent.');
        } catch (AiRateLimited $limited) {
            self::assertSame(AiRateLimited::PER_PERSON_MESSAGE, $limited->getMessage());
            self::assertNull($limited->provider);
        }

        self::assertSame([], $this->provider->calls);
        self::assertSame([], $this->metering->rows);
        self::assertSame(CountingAiCallLimit::LIMIT, $this->limit->counted[self::ACME_FOODS.':'.self::OMAR]);
    }

    /**
     * @return iterable<string, array{AiRateLimited|AiUnavailable, string}>
     */
    public static function providerFailures(): iterable
    {
        yield 'timed out' => [AiUnavailable::failed('huggingface', \RuntimeException::class), 'The AI provider huggingface did not answer (RuntimeException).'];
        yield 'blank answer' => [AiUnavailable::emptyAnswer('huggingface'), 'The AI provider huggingface answered with no text.'];
        yield 'refused with 429' => [new AiRateLimited('huggingface', 20), 'The AI provider huggingface refused the call: too many requests.'];
    }

    #[Test]
    #[DataProvider('providerFailures')]
    public function aProviderFailureComesBackAsAFailedResultAndIsMeteredWithoutTokens(AiRateLimited|AiUnavailable $failure, string $expectedReason): void
    {
        $this->givenProviderScript([$failure]);

        $result = $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertSame(
            [AiCallStatus::Failed, null, null, $expectedReason, 'huggingface', 'meta-llama/Llama-3.1-8B-Instruct', 'summary.v1'],
            [$result->status, $result->text, $result->data, $result->failureReason, $result->provider, $result->model, $result->promptVersion],
        );
        self::assertFalse($result->isAnswered());
        self::assertSame([[AiCallStatus::Failed, null, null, AiFeature::Summary, self::OMAR]], $this->meteredRows());
    }

    #[Test]
    public function aTextAnswerThatFailsTheCheckIsRejectedMeteredAndNotRetried(): void
    {
        $this->givenProviderScript([str_repeat('word ', 61), self::SUMMARY]);

        $result = $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertSame(
            [AiCallStatus::Rejected, null, 'The AI answer to summary.v1 has more than 60 words.'],
            [$result->status, $result->text, $result->failureReason],
        );
        self::assertCount(1, $this->provider->calls);
        self::assertSame([[AiCallStatus::Rejected, 812, 64, AiFeature::Summary, self::OMAR]], $this->meteredRows());
    }

    #[Test]
    public function aDataAnswerThatIsNotJsonIsAskedOnceMoreWithTheRetryInstructionAndBothCallsAreMetered(): void
    {
        $this->givenProviderScript(['Sure! Here is the JSON you asked for.', self::INTAKE_JSON]);

        $result = $this->gateway()->run(self::intakeOf(self::draft()), self::omar());

        self::assertSame(
            [AiCallStatus::Answered, null, ['summary' => 'Pay does nothing.', 'type' => 'Bug', 'priority' => 'Major', 'duplicates' => [], 'missing' => false, 'risky' => false], 'intake.v1', 'AI suggestion'],
            [$result->status, $result->text, $result->data, $result->promptVersion, $result->label],
        );
        self::assertCount(2, $this->provider->calls);
        $firstPrompt = $this->provider->calls[0]['prompt'];
        $retryPrompt = $this->provider->calls[1]['prompt'];
        self::assertSame($firstPrompt->instructions, $retryPrompt->instructions);
        self::assertSame($firstPrompt->content."\n\n".new OutputValidator()->retryInstruction(), $retryPrompt->content);
        self::assertSame([[AiCallStatus::Rejected, 812, 64, AiFeature::Intake, self::OMAR], [AiCallStatus::Answered, 812, 64, AiFeature::Intake, self::OMAR]], $this->meteredRows());
        self::assertSame([self::ACME_FOODS.':'.self::OMAR => 1], $this->limit->counted);
    }

    #[Test]
    public function aDataAnswerRejectedTwiceIsGivenUpAfterTheOneRetry(): void
    {
        $this->givenProviderScript(['{}', '{}', self::INTAKE_JSON]);

        $result = $this->gateway()->run(self::intakeOf(self::draft()), self::omar());

        self::assertSame(
            [AiCallStatus::Rejected, null, 'The AI answer does not match the intake.v1 schema.'],
            [$result->status, $result->data, $result->failureReason],
        );
        self::assertCount(2, $this->provider->calls);
        self::assertSame([[AiCallStatus::Rejected, 812, 64, AiFeature::Intake, self::OMAR], [AiCallStatus::Rejected, 812, 64, AiFeature::Intake, self::OMAR]], $this->meteredRows());
    }

    #[Test]
    public function aRetryTheProviderFailsComesBackAsFailedWithBothCallsMeteredAndOneCounted(): void
    {
        $this->givenProviderScript(['not json', AiUnavailable::failed('huggingface', \RuntimeException::class), self::INTAKE_JSON]);

        $result = $this->gateway()->run(self::intakeOf(self::draft()), self::omar());

        self::assertSame(
            [AiCallStatus::Failed, null, 'The AI provider huggingface did not answer (RuntimeException).', 'intake.v1', 'AI suggestion'],
            [$result->status, $result->data, $result->failureReason, $result->promptVersion, $result->label],
        );
        self::assertCount(2, $this->provider->calls);
        self::assertSame([[AiCallStatus::Rejected, 812, 64, AiFeature::Intake, self::OMAR], [AiCallStatus::Failed, null, null, AiFeature::Intake, self::OMAR]], $this->meteredRows());
        self::assertSame([self::ACME_FOODS.':'.self::OMAR => 1], $this->limit->counted);
    }

    #[Test]
    public function aDraftGoesOutWithTheCandidatesOmarMaySeeAndOnlyTheirKeysSurviveTheAnswer(): void
    {
        $this->givenProviderScript([self::INTAKE_JSON_WITH_DUPLICATES]);

        $result = $this->gateway()->run(self::intakeOf(self::draft()), self::omar());

        self::assertSame([AiCallStatus::Answered, ['SHOP-5']], [$result->status, $result->data['duplicates'] ?? null]);
        self::assertCount(1, $this->provider->calls);
        $content = $this->provider->calls[0]['prompt']->content;
        self::assertStringContainsString("<candidates>\nSHOP-5: Pay button does nothing\nSHOP-3: Basket total is wrong\n</candidates>", $content);
        self::assertStringContainsString("Title: Pay does nothing\nText: Pressing Pay on the last step does nothing.\nPin 1: The button.\n</ticket>", $content);
        self::assertStringNotContainsString('SHOP-6', $content);
        self::assertSame([[AiCallStatus::Answered, 812, 64, AiFeature::Intake, self::OMAR]], $this->meteredRows());
    }

    #[Test]
    public function theRetryOfADraftSendsTheSameCandidatesAndKeepsTheSentKey(): void
    {
        $this->givenProviderScript(['Sure! Here is the JSON you asked for.', self::INTAKE_JSON_WITH_DUPLICATES]);

        $result = $this->gateway()->run(self::intakeOf(self::draft()), self::omar());

        self::assertSame([AiCallStatus::Answered, ['SHOP-5']], [$result->status, $result->data['duplicates'] ?? null]);
        self::assertCount(2, $this->provider->calls);
        self::assertStringContainsString("<candidates>\nSHOP-5: Pay button does nothing\nSHOP-3: Basket total is wrong\n</candidates>", $this->provider->calls[1]['prompt']->content);
        self::assertSame([self::ACME_FOODS.':'.self::OMAR => 1], $this->limit->counted);
    }

    #[Test]
    public function aTicketTaskGoesOutWithoutACandidatesBlock(): void
    {
        $this->gateway()->run(self::summaryOf(self::CHECKOUT_TICKET), self::omar());

        self::assertCount(1, $this->provider->calls);
        self::assertStringNotContainsString('<candidates>', $this->provider->calls[0]['prompt']->content);
        self::assertSame([], $this->candidates->reads);
    }

    /**
     * @return iterable<string, array{AiSettings}>
     */
    public static function cloudSettings(): iterable
    {
        yield 'cloud' => [self::settings()];
        yield 'cloud, team-only tickets allowed to it' => [self::settings(isRestrictedToCloudAllowed: true)];
        yield 'laptop first' => [self::settings(where: AiSettings::WHERE_LAPTOP_FIRST)];
    }

    #[Test]
    #[DataProvider('cloudSettings')]
    public function aDraftThatMentionsSecurityIsRefusedWithoutAProviderCallAUsageRowOrACountedCall(AiSettings $settings): void
    {
        $this->givenSettings($settings);
        $draft = new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay does nothing.', ['The password field is empty.']);

        try {
            $this->gateway()->run(self::intakeOf($draft), self::omar());
            self::fail('A draft that mentions security went to cloud AI.');
        } catch (AiNotAllowed $refusal) {
            self::assertSame(
                [PolicyRefusal::SecuritySensitiveDraftToCloud, 'This report mentions security, so it stays out of cloud AI.'],
                [$refusal->refusal, $refusal->getMessage()],
            );
        }

        self::assertSame([[], [], [], [], []], [$this->provider->calls, $this->metering->rows, $this->limit->counted, $this->aiSettings->keyReads, $this->candidates->reads]);
    }

    #[Test]
    public function aDraftWhenAiRunsOnlyOnTheLaptopIsRefusedWithoutSetupAdviceAProviderCallAUsageRowOrACountedCall(): void
    {
        $this->givenSettings(self::settings(where: AiSettings::WHERE_LAPTOP));
        $this->givenProviderScript([self::INTAKE_JSON]);

        try {
            $this->gateway()->run(self::intakeOf(self::draft()), self::omar());
            self::fail('A draft went to cloud AI when AI runs only on the laptop.');
        } catch (AiNotAllowed $refusal) {
            self::assertSame(
                [PolicyRefusal::IntakeNotOnLaptop, 'Your company runs AI on laptops, and “Check with AI” does not run there yet.'],
                [$refusal->refusal, $refusal->getMessage()],
            );
        }

        self::assertSame([[], [], [], [], []], [$this->provider->calls, $this->metering->rows, $this->limit->counted, $this->aiSettings->keyReads, $this->candidates->reads]);
    }

    private function gateway(): GuardedAiGateway
    {
        $admission = new AiTaskAdmission(
            $this->aiSettings,
            new PolicyEvaluator(),
            $this->tickets,
            new WordListDraftReportChecks(new CheckReasons()),
            $this->candidates,
            new MemberTicketVisibilities([self::LENA => [new TeamId(self::SHOP_TEAM), new TeamId(self::PAY_TEAM)], self::OMAR => [new TeamId(self::SHOP_TEAM)]]),
        );

        return new GuardedAiGateway($this->aiSettings, $admission, new PromptBuilder(), $this->limit, $this->provider, new OutputValidator(), $this->metering);
    }

    /**
     * @param non-empty-string|null $providerKey
     */
    private function givenSettings(AiSettings $settings, ?string $providerKey = null, bool $isKeyUnreadable = false): void
    {
        $this->aiSettings = new StoredAiSettings(new OrganizationId(self::ACME_FOODS), $settings, $providerKey, $isKeyUnreadable);
    }

    /**
     * @param list<string|AiRateLimited|AiUnavailable> $script
     */
    private function givenProviderScript(array $script): void
    {
        $this->provider = new ScriptedProvider($script);
    }

    /**
     * @return list<array{AiCallStatus, ?int, ?int, AiFeature, string}>
     */
    private function meteredRows(): array
    {
        return array_map(static fn (array $row): array => [
            $row['usage']->status, $row['usage']->inputTokens, $row['usage']->outputTokens, $row['feature'], $row['caller']->userId()->toString(),
        ], $this->metering->rows);
    }

    private static function settings(
        bool $isOn = true,
        bool $isForLeadsOnly = false,
        bool $isTitleOnly = false,
        string $where = AiSettings::WHERE_CLOUD,
        bool $isRestrictedToCloudAllowed = false,
        ?string $provider = null,
        ?string $providerModel = null,
    ): AiSettings {
        return new AiSettings($isOn, $isForLeadsOnly, $isTitleOnly, false, $where, $isRestrictedToCloudAllowed, $provider, $providerModel);
    }

    private static function summaryOf(string $ticketId): AiTask
    {
        return new AiTask(AiFeature::Summary, new TicketId($ticketId));
    }

    private static function intakeOf(AiDraft $draft): AiTask
    {
        return new AiTask(AiFeature::Intake, draft: $draft);
    }

    private static function draft(): AiDraft
    {
        return new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay on the last step does nothing.', ['The button.']);
    }

    private static function omar(): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), new Roles(false, false, [], [new TeamId(self::SHOP_TEAM)], []));
    }

    private static function facts(string $visibility, ?string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), $visibility, null === $teamId ? null : new TeamId($teamId), new UserId(self::LENA));
    }

    private static function lena(): ActorContext
    {
        return new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [], []));
    }
}
