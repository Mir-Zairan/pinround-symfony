<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The policy check a summary request asks before it queues anything, with the real policy evaluator and doubles of the
 * settings and ticket reads. It has no provider and no limit to reach, so what it may never do is read the
 * organization's provider key, which only the gateway needs once a call is about to go out.
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

    private StoredAiSettings $aiSettings;
    private ListedAskedTickets $tickets;

    protected function setUp(): void
    {
        $this->tickets = new ListedAskedTickets();
        $this->tickets->add(new TicketId(self::CHECKOUT_TICKET), new AskedTicket(new PromptTicket('Checkout fails', 'Pay does nothing.'), false), [self::LENA, self::OMAR]);
        // Mia is in Acme Foods but not in Shop, so she may not see the team-only ticket.
        $this->tickets->add(new TicketId(self::TEAM_ONLY_TICKET), new AskedTicket(new PromptTicket('Card numbers in the log', 'Card numbers show.'), true), [self::LENA, self::OMAR]);
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

        self::assertSame(['Card numbers in the log', true], [$admitted->ticket->promptTicket->title, $admitted->ticket->isTeamOnly]);
        // The server never reaches a laptop, so "laptop first" goes to the cloud.
        self::assertSame([AiRoute::Cloud, null, true], [$admitted->decision->route, $admitted->decision->refusal, $admitted->decision->isTitleOnly]);
        self::assertSame([], $this->aiSettings->keyReads);
    }

    #[Test]
    public function aTaskWithoutATicketIsRefusedBeforeAnythingIsRead(): void
    {
        try {
            $this->admission()->refusalFor(new AiTask(AiFeature::Intake, null, 'Pay does nothing.'), self::omar());
            self::fail('A task without a ticket was decided.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A server AI task needs a ticket.', $refusal->getMessage());
        }

        self::assertSame([[], []], [$this->tickets->reads, $this->aiSettings->keyReads]);
    }

    private function admission(): AiTaskAdmission
    {
        return new AiTaskAdmission($this->aiSettings, new PolicyEvaluator(), $this->tickets);
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

    private static function omar(): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none());
    }
}
