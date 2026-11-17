<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\AiDraft;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiTask;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\Gateway\AskedTicket;
use App\Assist\Application\Policy\AiRoute;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Application\PromptTicket;
use App\Assist\Domain\AiFeature;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Application\Intake\WordListDraftReportChecks;
use App\Ticketing\Domain\Intake\CheckReasons;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The policy check a summary request asks before it queues anything, with the real policy evaluator and security word
 * check, and doubles of the settings, ticket and candidate reads. It has no provider and no limit to reach, so what it
 * may never do is read the organization's provider key, which only the gateway needs once a call is about to go out.
 *
 * Omar drafts reports in the SHOP project. He may see SHOP-3 and SHOP-5, for everyone, and SHOP-4, a team-only ticket
 * he is on; SHOP-6 is team-only and not his, and OPS-1 is in another project.
 */
final class AiTaskAdmissionTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c7f01';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c7f11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c7f12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c7f13';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000c7f21';
    private const string CHECKOUT_TICKET = '0197a3c0-0000-7000-8000-0000000c7f31';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000c7f32';
    private const string MISSING_TICKET = '0197a3c0-0000-7000-8000-0000000c7f39';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c7f41';
    private const string OPS_PROJECT = '0197a3c0-0000-7000-8000-0000000c7f42';

    private StoredAiSettings $aiSettings;
    private ListedAskedTickets $tickets;
    private ListedIntakeCandidates $candidates;
    private MemberTicketVisibilities $visibilities;

    protected function setUp(): void
    {
        $this->tickets = new ListedAskedTickets();
        $this->tickets->add(new TicketId(self::CHECKOUT_TICKET), new AskedTicket(new PromptTicket('Checkout fails', 'Pay does nothing.'), false), [self::LENA, self::OMAR]);
        // Mia is in Acme Foods but not in Shop, so she may not see the team-only ticket.
        $this->tickets->add(new TicketId(self::TEAM_ONLY_TICKET), new AskedTicket(new PromptTicket('Card numbers in the log', 'Card numbers show.'), true), [self::LENA, self::OMAR]);
        $this->candidates = new ListedIntakeCandidates(new OrganizationId(self::ACME_FOODS));
        $this->candidates->add(self::OPS_PROJECT, new IntakeCandidate('OPS-1', 'Backups run late'), false, [self::LENA, self::OMAR]);
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-3', 'Basket total is wrong'), false, [self::LENA, self::OMAR]);
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-4', 'Card form shows the full number'), true, [self::LENA, self::OMAR]);
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-5', 'Pay button does nothing'), false, [self::LENA, self::OMAR]);
        $this->candidates->add(self::SHOP_PROJECT, new IntakeCandidate('SHOP-6', 'Refunds stay pending'), true, [self::LENA]);
        $this->visibilities = new MemberTicketVisibilities();
        $this->givenSettings(self::settings());
    }

    #[Test]
    public function aTaskThePolicyAllowsHasNoRefusalAndNoKeyIsRead(): void
    {
        self::assertNull($this->admission()->refusalFor(self::summaryOf(self::CHECKOUT_TICKET), self::omar()));

        self::assertSame([self::CHECKOUT_TICKET], $this->tickets->reads);
        self::assertSame([], $this->aiSettings->keyReads);
    }

    /**
     * @return iterable<string, array{AiSettings, string, string, PolicyRefusal}>
     */
    public static function refusedTasks(): iterable
    {
        yield 'AI off' => [self::settings(isOn: false), self::OMAR, self::CHECKOUT_TICKET, PolicyRefusal::AiOff];
        yield 'leads only, Omar leads no team' => [self::settings(isForLeadsOnly: true), self::OMAR, self::CHECKOUT_TICKET, PolicyRefusal::LeadsOnly];
        yield 'a team-only ticket Mia may not see' => [self::settings(), self::MIA, self::TEAM_ONLY_TICKET, PolicyRefusal::CannotSeeTicket];
        yield 'a ticket that does not exist' => [self::settings(), self::OMAR, self::MISSING_TICKET, PolicyRefusal::CannotSeeTicket];
        yield 'a team-only ticket kept from cloud AI' => [self::settings(), self::OMAR, self::TEAM_ONLY_TICKET, PolicyRefusal::RestrictedTicketToCloud];
        yield 'laptop AI only' => [self::settings(where: AiSettings::WHERE_LAPTOP), self::OMAR, self::CHECKOUT_TICKET, PolicyRefusal::LaptopNotReady];
    }

    #[Test]
    #[DataProvider('refusedTasks')]
    public function aRefusedTaskComesBackAsItsRefusalWithoutReadingTheKey(AiSettings $settings, string $viewerId, string $ticketId, PolicyRefusal $expectedRefusal): void
    {
        $this->givenSettings($settings);
        $viewer = new ActorContext(new UserId($viewerId), new OrganizationId(self::ACME_FOODS), Roles::none());

        self::assertSame($expectedRefusal, $this->admission()->refusalFor(self::summaryOf($ticketId), $viewer));
        self::assertSame([], $this->aiSettings->keyReads);
    }

    #[Test]
    #[DataProvider('refusedTasks')]
    public function admitThrowsTheSameRefusalAsRefusalForReturns(AiSettings $settings, string $viewerId, string $ticketId, PolicyRefusal $expectedRefusal): void
    {
        $this->givenSettings($settings);
        $viewer = new ActorContext(new UserId($viewerId), new OrganizationId(self::ACME_FOODS), Roles::none());

        try {
            $this->admission()->admit(self::summaryOf($ticketId), $viewer);
            self::fail('A task the policy refuses was admitted.');
        } catch (AiNotAllowed $refusal) {
            self::assertSame([$expectedRefusal, $expectedRefusal->sentence()], [$refusal->refusal, $refusal->getMessage()]);
        }
    }

    #[Test]
    public function aViewerThePolicyRefusesNeverHasTheTicketRead(): void
    {
        $this->givenSettings(self::settings(isForLeadsOnly: true));

        self::assertSame(PolicyRefusal::LeadsOnly, $this->admission()->refusalFor(self::summaryOf(self::CHECKOUT_TICKET), self::omar()));
        self::assertSame([], $this->tickets->reads);
    }

    #[Test]
    public function aLeadIsAdmittedWhenAiIsForLeadsOnly(): void
    {
        $this->givenSettings(self::settings(isForLeadsOnly: true));
        $lena = new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [], []));

        self::assertNull($this->admission()->refusalFor(self::summaryOf(self::CHECKOUT_TICKET), $lena));
    }

    #[Test]
    public function anAdmittedTaskCarriesTheTicketAsTheViewerSeesItAndTheCloudRoute(): void
    {
        $this->givenSettings(self::settings(isTitleOnly: true, where: AiSettings::WHERE_LAPTOP_FIRST, isRestrictedToCloudAllowed: true));

        $admitted = $this->admission()->admit(self::summaryOf(self::TEAM_ONLY_TICKET), self::omar());

        self::assertSame(['Card numbers in the log', []], [$admitted->promptTicket->title, $admitted->duplicateCandidates]);
        // The server never reaches a laptop, so "laptop first" goes to the cloud.
        self::assertSame([AiRoute::Cloud, null, true], [$admitted->decision->route, $admitted->decision->refusal, $admitted->decision->isTitleOnly]);
        self::assertSame([], $this->aiSettings->keyReads);
        self::assertSame([], $this->candidates->reads);
    }

    /**
     * @return iterable<string, array{\Closure(): AiTask, string}>
     */
    public static function tasksWithTheWrongSubject(): iterable
    {
        yield 'intake without a draft' => [static fn (): AiTask => new AiTask(AiFeature::Intake, null, 'Pay does nothing.'), 'An intake task needs a draft report and no ticket.'];
        yield 'intake with a ticket as well' => [static fn (): AiTask => new AiTask(AiFeature::Intake, new TicketId(self::CHECKOUT_TICKET), null, self::draft()), 'An intake task needs a draft report and no ticket.'];
        yield 'summary of a draft' => [static fn (): AiTask => new AiTask(AiFeature::Summary, null, null, self::draft()), 'A summary task needs a ticket and no draft report.'];
        yield 'summary of a ticket and a draft' => [static fn (): AiTask => new AiTask(AiFeature::Summary, new TicketId(self::CHECKOUT_TICKET), null, self::draft()), 'A summary task needs a ticket and no draft report.'];
    }

    /**
     * @param \Closure(): AiTask $task
     */
    #[Test]
    #[DataProvider('tasksWithTheWrongSubject')]
    public function aTaskWithTheWrongSubjectIsRefusedBeforeAnythingIsRead(\Closure $task, string $expectedMessage): void
    {
        try {
            $this->admission()->refusalFor($task(), self::omar());
            self::fail('A task with the wrong subject was decided.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame($expectedMessage, $refusal->getMessage());
        }

        self::assertSame([[], [], [], []], [$this->tickets->reads, $this->candidates->reads, $this->visibilities->reads, $this->aiSettings->keyReads]);
    }

    /**
     * @return iterable<string, array{AiDraft}>
     */
    public static function draftsThatMentionSecurity(): iterable
    {
        yield 'in the title' => [new AiDraft(self::SHOP_PROJECT, 'Password reset mail never comes', 'I asked twice.', ['Nothing here.'])];
        yield 'in the detail' => [new AiDraft(self::SHOP_PROJECT, 'Order page is slow', 'Other tenants show up in the list.', ['Nothing here.'])];
        yield 'in a pin note' => [new AiDraft(self::SHOP_PROJECT, 'Order page is slow', 'It takes ten seconds.', ['Fine.', 'The card number leaks here.'])];
    }

    #[Test]
    #[DataProvider('draftsThatMentionSecurity')]
    public function aDraftThatMentionsSecurityIsKeptFromCloudAiBeforeItsCandidatesAreRead(AiDraft $draft): void
    {
        self::assertSame(PolicyRefusal::SecuritySensitiveDraftToCloud, $this->admission()->refusalFor(self::intakeOf($draft), self::omar()));

        try {
            $this->admission()->admit(self::intakeOf($draft), self::omar());
            self::fail('A draft that mentions security was admitted to cloud AI.');
        } catch (AiNotAllowed $refusal) {
            self::assertSame(
                [PolicyRefusal::SecuritySensitiveDraftToCloud, 'This report mentions security, so it stays out of cloud AI.'],
                [$refusal->refusal, $refusal->getMessage()],
            );
        }
        self::assertSame([[], [], []], [$this->candidates->reads, $this->visibilities->reads, $this->aiSettings->keyReads]);
    }

    /**
     * @return iterable<string, array{AiSettings, AiDraft, PolicyRefusal}>
     */
    public static function refusedDrafts(): iterable
    {
        $securityDraft = self::draft(title: 'Password reset mail never comes');
        yield 'a security draft, AI off' => [self::settings(isOn: false), $securityDraft, PolicyRefusal::AiOff];
        yield 'a security draft, leads only' => [self::settings(isForLeadsOnly: true), $securityDraft, PolicyRefusal::LeadsOnly];
        yield 'a security draft, laptop AI only' => [self::settings(where: AiSettings::WHERE_LAPTOP), $securityDraft, PolicyRefusal::LaptopNotReady];
        yield 'a plain draft, laptop AI only' => [self::settings(where: AiSettings::WHERE_LAPTOP), self::draft(), PolicyRefusal::LaptopNotReady];
        yield 'a security draft, team-only tickets allowed to cloud AI' => [self::settings(isRestrictedToCloudAllowed: true), $securityDraft, PolicyRefusal::SecuritySensitiveDraftToCloud];
        yield 'a security draft, laptop first' => [self::settings(where: AiSettings::WHERE_LAPTOP_FIRST), $securityDraft, PolicyRefusal::SecuritySensitiveDraftToCloud];
    }

    #[Test]
    #[DataProvider('refusedDrafts')]
    public function aRefusedDraftGetsTheSameRefusalFromAdmitAndRefusalFor(AiSettings $settings, AiDraft $draft, PolicyRefusal $expectedRefusal): void
    {
        $this->givenSettings($settings);

        self::assertSame($expectedRefusal, $this->admission()->refusalFor(self::intakeOf($draft), self::omar()));
        try {
            $this->admission()->admit(self::intakeOf($draft), self::omar());
            self::fail('A draft the policy refuses was admitted.');
        } catch (AiNotAllowed $refusal) {
            self::assertSame([$expectedRefusal, $expectedRefusal->sentence()], [$refusal->refusal, $refusal->getMessage()]);
        }
        self::assertSame([[], [], []], [$this->candidates->reads, $this->aiSettings->keyReads, $this->tickets->reads]);
    }

    #[Test]
    public function anAdmittedDraftCarriesItsTextAndTheOpenTicketsOmarMaySeeWithoutTeamOnlyOnes(): void
    {
        $draft = new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay on the last step does nothing.', ['The button.', '', 'Still on this page.']);

        $admitted = $this->admission()->admit(self::intakeOf($draft), self::omar());

        self::assertSame('Pay does nothing', $admitted->promptTicket->title);
        self::assertSame("Pressing Pay on the last step does nothing.\nPin 1: The button.\nPin 3: Still on this page.", $admitted->promptTicket->text);
        self::assertSame(['SHOP-5' => 'Pay button does nothing', 'SHOP-3' => 'Basket total is wrong'], $admitted->duplicateCandidates);
        self::assertSame([AiRoute::Cloud, null, false], [$admitted->decision->route, $admitted->decision->refusal, $admitted->decision->isTitleOnly]);
        self::assertSame([['projectId' => self::SHOP_PROJECT, 'viewerId' => self::OMAR, 'includeTeamOnly' => false]], $this->candidates->reads);
        self::assertSame([], $this->aiSettings->keyReads);
    }

    #[Test]
    public function theTeamOnlyTicketsOmarMaySeeAreCandidatesWhenTheAdminLetsThemGoToCloudAi(): void
    {
        $this->givenSettings(self::settings(isRestrictedToCloudAllowed: true));

        $admitted = $this->admission()->admit(self::intakeOf(self::draft()), self::omar());

        self::assertSame(
            ['SHOP-5' => 'Pay button does nothing', 'SHOP-4' => 'Card form shows the full number', 'SHOP-3' => 'Basket total is wrong'],
            $admitted->duplicateCandidates,
        );
        self::assertSame([['projectId' => self::SHOP_PROJECT, 'viewerId' => self::OMAR, 'includeTeamOnly' => true]], $this->candidates->reads);
    }

    #[Test]
    public function titleOnlyLeavesTheDetailAndPinNotesOutOfAnAdmittedDraft(): void
    {
        $this->givenSettings(self::settings(isTitleOnly: true));

        $admitted = $this->admission()->admit(self::intakeOf(self::draft()), self::omar());

        self::assertSame(['Pay does nothing', null, true], [$admitted->promptTicket->title, $admitted->promptTicket->text, $admitted->decision->isTitleOnly]);
        self::assertNotSame([], $admitted->duplicateCandidates);
    }

    private function admission(): AiTaskAdmission
    {
        return new AiTaskAdmission(
            $this->aiSettings,
            new PolicyEvaluator(),
            $this->tickets,
            new WordListDraftReportChecks(new CheckReasons()),
            $this->candidates,
            $this->visibilities,
        );
    }

    private function givenSettings(AiSettings $settings): void
    {
        $this->aiSettings = new StoredAiSettings(new OrganizationId(self::ACME_FOODS), $settings, 'sk-acme-foods-7c7f');
    }

    private static function settings(
        bool $isOn = true,
        bool $isForLeadsOnly = false,
        bool $isTitleOnly = false,
        string $where = AiSettings::WHERE_CLOUD,
        bool $isRestrictedToCloudAllowed = false,
    ): AiSettings {
        return new AiSettings($isOn, $isForLeadsOnly, $isTitleOnly, false, $where, $isRestrictedToCloudAllowed, null, null);
    }

    private static function summaryOf(string $ticketId): AiTask
    {
        return new AiTask(AiFeature::Summary, new TicketId($ticketId));
    }

    private static function intakeOf(AiDraft $draft): AiTask
    {
        return new AiTask(AiFeature::Intake, draft: $draft);
    }

    private static function draft(string $title = 'Pay does nothing'): AiDraft
    {
        return new AiDraft(self::SHOP_PROJECT, $title, 'Pressing Pay on the last step does nothing.', ['The button.']);
    }

    private static function omar(): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none());
    }
}
