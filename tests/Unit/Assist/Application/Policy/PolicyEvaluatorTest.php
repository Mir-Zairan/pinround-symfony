<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Policy;

use App\Assist\Application\Policy\AiRoute;
use App\Assist\Application\Policy\PolicyDecision;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyEvaluatorTest extends TestCase
{
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a501';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-00000000a502';

    private const string AI_OFF = 'Your admin turned AI help off.';
    private const string LEADS_ONLY = 'Your admin allows AI help for leads only.';
    private const string CANNOT_SEE = 'AI help works only on tickets you can see.';
    private const string RESTRICTED = 'Your admin keeps team-only tickets away from cloud AI.';
    private const string LAPTOP_NOT_READY = 'Your company runs AI on each laptop, and it is not running on yours. Use “Local AI” at the top to set it up.';
    private const string SECURITY_SENSITIVE_DRAFT = 'This report mentions security, so it stays out of cloud AI.';
    private const string CLOUD_ONLY = 'Your admin runs AI help in the company cloud, not on laptops.';
    private const string INTAKE_NOT_ON_LAPTOP = 'Your company runs AI on laptops, and Check with AI does not run there yet.';

    /**
     * Each row: the settings (on, leads only, where, restricted tickets to cloud, title only), the viewer, can see the
     * ticket, the ticket restricted, the laptop ready; then the route, whether it is sent, the sentence and the filter.
     *
     * @return iterable<string, array{array{bool, bool, string, bool, bool}, string, bool, bool, bool, ?string, bool, ?string, bool}>
     */
    public static function policyMatrix(): iterable
    {
        // AI off wins over everything else, even a viewer who may do all of it.
        yield 'off, lead, cloud' => [[false, false, 'cloud', true, false], 'lead', true, false, true, null, false, self::AI_OFF, true];
        yield 'off, leads only, non-lead who cannot see, laptop' => [[false, true, 'laptop', false, false], 'member', false, true, false, null, false, self::AI_OFF, true];
        yield 'off, laptop first, laptop ready' => [[false, false, 'laptop_first', true, false], 'member', true, false, true, null, false, self::AI_OFF, true];

        // Who may use it comes before the ticket.
        yield 'leads only, a member' => [[true, true, 'cloud', false, false], 'member', true, false, false, null, false, self::LEADS_ONLY, true];
        yield 'leads only, a member who cannot see' => [[true, true, 'cloud', false, false], 'member', false, false, false, null, false, self::LEADS_ONLY, true];
        yield 'leads only, a viewer' => [[true, true, 'laptop', false, false], 'viewer', true, false, true, null, false, self::LEADS_ONLY, true];
        yield 'leads only, an admin who leads no team' => [[true, true, 'cloud', false, false], 'admin', true, false, false, null, false, self::LEADS_ONLY, true];
        yield 'leads only, no team' => [[true, true, 'cloud', false, false], 'nobody', true, false, false, null, false, self::LEADS_ONLY, true];
        yield 'leads only, a lead of another team' => [[true, true, 'cloud', false, false], 'lead of another team', true, false, false, 'cloud', true, null, false];
        yield 'leads only, a lead' => [[true, true, 'cloud', false, false], 'lead', true, false, false, 'cloud', true, null, false];
        yield 'everyone, a member' => [[true, false, 'cloud', false, false], 'member', true, false, false, 'cloud', true, null, false];
        yield 'everyone, no team' => [[true, false, 'cloud', false, false], 'nobody', true, false, false, 'cloud', true, null, false];

        // The ticket voter comes before where.
        yield 'cannot see, cloud' => [[true, false, 'cloud', true, false], 'lead', false, false, false, null, false, self::CANNOT_SEE, true];
        yield 'cannot see, laptop ready' => [[true, false, 'laptop', false, false], 'member', false, false, true, null, false, self::CANNOT_SEE, true];
        yield 'cannot see, laptop first, laptop ready' => [[true, false, 'laptop_first', false, false], 'member', false, true, true, null, false, self::CANNOT_SEE, true];

        // Cloud: the laptop does not matter; restricted tickets need the opt-in.
        yield 'cloud, laptop ready' => [[true, false, 'cloud', false, false], 'member', true, false, true, 'cloud', true, null, false];
        yield 'cloud, restricted, no opt-in' => [[true, false, 'cloud', false, false], 'member', true, true, false, null, false, self::RESTRICTED, true];
        yield 'cloud, restricted, no opt-in, laptop ready' => [[true, false, 'cloud', false, false], 'member', true, true, true, null, false, self::RESTRICTED, true];
        yield 'cloud, restricted, opt-in' => [[true, false, 'cloud', true, false], 'member', true, true, false, 'cloud', true, null, false];
        yield 'cloud, title only' => [[true, false, 'cloud', false, true], 'member', true, false, false, 'cloud', true, null, true];

        // Laptop: never the cloud; restricted tickets may go to the laptop.
        yield 'laptop, ready' => [[true, false, 'laptop', false, false], 'member', true, false, true, 'laptop', true, null, false];
        yield 'laptop, not ready' => [[true, false, 'laptop', false, false], 'member', true, false, false, 'setup_sheet', false, self::LAPTOP_NOT_READY, true];
        yield 'laptop, restricted, no opt-in, ready' => [[true, false, 'laptop', false, false], 'member', true, true, true, 'laptop', true, null, false];
        yield 'laptop, restricted, opt-in, not ready' => [[true, false, 'laptop', true, false], 'member', true, true, false, 'setup_sheet', false, self::LAPTOP_NOT_READY, true];
        yield 'laptop, title only' => [[true, false, 'laptop', false, true], 'lead', true, false, true, 'laptop', true, null, true];

        // Laptop first: the laptop when it is ready, otherwise the cloud with its restricted rule.
        yield 'laptop first, ready' => [[true, false, 'laptop_first', false, false], 'member', true, false, true, 'laptop', true, null, false];
        yield 'laptop first, ready, restricted, no opt-in' => [[true, false, 'laptop_first', false, false], 'member', true, true, true, 'laptop', true, null, false];
        yield 'laptop first, not ready' => [[true, false, 'laptop_first', false, false], 'member', true, false, false, 'cloud', true, null, false];
        yield 'laptop first, not ready, restricted, no opt-in' => [[true, false, 'laptop_first', false, false], 'member', true, true, false, null, false, self::RESTRICTED, true];
        yield 'laptop first, not ready, restricted, opt-in' => [[true, false, 'laptop_first', true, true], 'member', true, true, false, 'cloud', true, null, true];
        yield 'laptop first, leads only, a lead, not ready' => [[true, true, 'laptop_first', false, false], 'lead', true, false, false, 'cloud', true, null, false];
    }

    /**
     * @param array{bool, bool, string, bool, bool} $settings
     */
    #[Test]
    #[DataProvider('policyMatrix')]
    public function eachRequestGetsTheRouteOrTheReasonOfTheFlow(
        array $settings,
        string $viewer,
        bool $canSeeTicket,
        bool $isTicketRestricted,
        bool $isLaptopReady,
        ?string $expectedRoute,
        bool $expectedSendAllowed,
        ?string $expectedReason,
        bool $expectedTitleOnly,
    ): void {
        [$isOn, $isForLeadsOnly, $whereAiRuns, $isRestrictedToCloudAllowed, $isTitleOnly] = $settings;
        $aiSettings = new AiSettings($isOn, $isForLeadsOnly, $isTitleOnly, true, $whereAiRuns, $isRestrictedToCloudAllowed, null, null);

        $decision = new PolicyEvaluator()->decide($aiSettings, self::viewer($viewer), $canSeeTicket, $isTicketRestricted, $isLaptopReady);

        self::assertSame(
            [$expectedRoute, $expectedSendAllowed, $expectedReason, $expectedTitleOnly],
            [$decision->route?->value, $decision->isSendAllowed(), $decision->reason(), $decision->isTitleOnly],
        );
    }

    #[Test]
    public function theViewerChecksAloneRefuseAsTheFullFlowDoes(): void
    {
        $evaluator = new PolicyEvaluator();
        $leadsOnly = new AiSettings(true, true, false, false, 'cloud', false, null, null);

        self::assertSame(PolicyRefusal::AiOff, $evaluator->refusalForViewer(AiSettings::off(), self::viewer('lead')));
        self::assertSame(PolicyRefusal::LeadsOnly, $evaluator->refusalForViewer($leadsOnly, self::viewer('member')));
        self::assertNull($evaluator->refusalForViewer($leadsOnly, self::viewer('lead')));
    }

    #[Test]
    public function onlyLeadsOnlyWithAiOnNeedsTheViewersRoles(): void
    {
        $evaluator = new PolicyEvaluator();
        $needs = [];
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$isOn, $isForLeadsOnly]) {
            $needs[] = $evaluator->needsViewerRoles(new AiSettings($isOn, $isForLeadsOnly, false, false, 'cloud', false, null, null));
        }

        self::assertSame([false, false, false, true], $needs);
    }

    #[Test]
    public function theSetupSheetIsNeverARouteToSendOn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The setup sheet sends nothing.');

        PolicyDecision::send(AiRoute::SetupSheet, false);
    }

    #[Test]
    public function everyRefusalHasItsOwnSentence(): void
    {
        $sentences = array_map(static fn (PolicyRefusal $refusal): string => $refusal->sentence(), PolicyRefusal::cases());

        self::assertSame([self::AI_OFF, self::LEADS_ONLY, self::CANNOT_SEE, self::RESTRICTED, self::LAPTOP_NOT_READY, self::SECURITY_SENSITIVE_DRAFT, self::CLOUD_ONLY, self::INTAKE_NOT_ON_LAPTOP], $sentences);
    }

    #[Test]
    public function aDraftIsRefusedWithoutTheLaptopSetupAdviceAndOtherwiseForTheSameReason(): void
    {
        $draftRefusals = [];
        foreach (PolicyRefusal::cases() as $refusal) {
            $draftRefusals[$refusal->value] = $refusal->forDraft()->value;
        }

        self::assertSame([
            'ai_off' => 'ai_off',
            'leads_only' => 'leads_only',
            'cannot_see_ticket' => 'cannot_see_ticket',
            'restricted_ticket_to_cloud' => 'restricted_ticket_to_cloud',
            'laptop_not_ready' => 'intake_not_on_laptop',
            'security_sensitive_draft_to_cloud' => 'security_sensitive_draft_to_cloud',
            'cloud_only' => 'cloud_only',
            'intake_not_on_laptop' => 'intake_not_on_laptop',
        ], $draftRefusals);
    }

    private static function viewer(string $who): ActorContext
    {
        $shop = new TeamId(self::SHOP_TEAM);
        $roles = match ($who) {
            'lead' => new Roles(false, false, [$shop], [], []),
            'lead of another team' => new Roles(false, false, [new TeamId(self::OTHER_TEAM)], [$shop], []),
            'member' => new Roles(false, false, [], [$shop], []),
            'viewer' => new Roles(false, false, [], [], [$shop]),
            'admin' => new Roles(true, true, [], [$shop], []),
            'nobody' => Roles::none(),
            default => throw new \InvalidArgumentException($who),
        };

        return new ActorContext(new UserId('0197a3c0-0000-7000-8000-00000000a5f1'), new OrganizationId('0197a3c0-0000-7000-8000-00000000a5e1'), $roles);
    }
}
