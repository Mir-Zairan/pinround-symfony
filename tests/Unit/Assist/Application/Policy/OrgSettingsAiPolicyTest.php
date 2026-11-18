<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Policy;

use App\Assist\Api\PolicyView;
use App\Assist\Application\Policy\OrgSettingsAiPolicy;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrgSettingsAiPolicyTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a6e1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a6e2';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a6f1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a6f2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a601';

    /** @var list<string> the organization of each roles lookup, in order */
    private array $roleLookups = [];

    /** @var list<string> the organization of each settings read, in order */
    private array $settingsReads = [];

    #[Test]
    public function aViewerInAnOrganizationWithAiOffIsToldTheAdminTurnedItOffAndNoRolesAreRead(): void
    {
        // Leads only and sync stay stored on, as an admin who turns AI off leaves them; nothing summarizes then.
        $view = $this->policyWith(new AiSettings(false, true, false, true, 'laptop_first', true, null, null))
            ->for(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS));

        self::assertFalse($view->isAllowed());
        self::assertSame(['ai_off', 'Your admin turned AI help off.'], [$view->refusalCode, $view->refusalSentence]);
        self::assertFalse($view->isSummaryOnSyncOn);
        self::assertFalse($view->isLaptopAllowed());
        self::assertSame([self::ACME_FOODS], $this->settingsReads);
        self::assertSame([], $this->roleLookups);
    }

    #[Test]
    public function aiForEveryoneLetsAnyoneInWithoutReadingRoles(): void
    {
        $view = $this->policyWith(new AiSettings(true, false, false, true, 'cloud', false, null, null))
            ->for(new UserId('0197a3c0-0000-7000-8000-00000000a6f9'), new OrganizationId(self::ACME_FOODS));

        self::assertTrue($view->isAllowed());
        self::assertTrue($view->isSummaryOnSyncOn);
        self::assertSame([], $this->roleLookups);
    }

    #[Test]
    public function aMemberIsToldAiIsForLeadsOnlyAndALeadIsLetIn(): void
    {
        $policy = $this->policyWith(new AiSettings(true, true, true, true, 'laptop_first', false, null, null));

        $memberView = $policy->for(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
        $leadView = $policy->for(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS));

        self::assertSame(['leads_only', 'Your admin allows AI help for leads only.'], [$memberView->refusalCode, $memberView->refusalSentence]);
        self::assertFalse($memberView->isLaptopAllowed());
        self::assertTrue($leadView->isAllowed());
        self::assertSame([null, null, 'laptop_first', true, true, false], [
            $leadView->refusalCode, $leadView->refusalSentence, $leadView->whereAiRuns, $leadView->isTitleOnly, $leadView->isSummaryOnSyncOn, $leadView->isRestrictedToCloudAllowed,
        ]);
        self::assertTrue($leadView->isLaptopAllowed());
        self::assertSame([self::ACME_FOODS, self::ACME_FOODS], $this->roleLookups);
    }

    #[Test]
    public function theRolesAreReadInTheAskedOrganization(): void
    {
        // Lena leads in Acme Foods only; asked about Beta Bakery she has no roles there.
        $view = $this->policyWith(new AiSettings(true, true, false, false, 'cloud', false, null, null))
            ->for(new UserId(self::LENA), new OrganizationId(self::BETA_BAKERY));

        self::assertSame('leads_only', $view->refusalCode);
        self::assertSame([self::BETA_BAKERY], $this->roleLookups);
    }

    #[Test]
    public function eachPlaceWhereTheAiRunsIsShownAsStored(): void
    {
        $whereShown = [];
        foreach (AiSettings::WHERE_VALUES as $whereAiRuns) {
            $view = $this->policyWith(new AiSettings(true, false, false, false, $whereAiRuns, true, null, null))
                ->for(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
            $whereShown[] = [$view->whereAiRuns, $view->isLaptopAllowed(), $view->isRestrictedToCloudAllowed, $view->isTitleOnly];
        }

        self::assertSame([['cloud', false, true, false], ['laptop', true, true, false], ['laptop_first', true, true, false]], $whereShown);
    }

    #[Test]
    public function aPageUsesTheRolesTheRequestsActorHoldsAndReadsNoneAgain(): void
    {
        $policy = $this->policyWith(new AiSettings(true, true, false, false, 'cloud', false, null, null));

        $leadView = $policy->forTokenUser('lena in acme foods');
        $memberView = $policy->forTokenUser('omar in beta bakery');

        self::assertSame([null, 'leads_only'], [$leadView->refusalCode, $memberView->refusalCode]);
        self::assertSame([self::ACME_FOODS, self::BETA_BAKERY], $this->settingsReads);
        self::assertSame([], $this->roleLookups);
    }

    #[Test]
    public function aPageWithoutAnActorInAnOrganizationGetsAiOffWithoutReadingAnything(): void
    {
        $view = $this->policyWith(new AiSettings(true, false, false, true, 'laptop', true, null, null))->forTokenUser(null);

        self::assertSame(['ai_off', 'Your admin turned AI help off.'], [$view->refusalCode, $view->refusalSentence]);
        self::assertSame([false, false, 'cloud'], [$view->isSummaryOnSyncOn, $view->isLaptopAllowed(), $view->whereAiRuns]);
        self::assertSame([], $this->settingsReads);
        self::assertSame([], $this->roleLookups);
    }

    /**
     * @return iterable<string, array{PolicyView, bool, string|null}>
     */
    public static function serverTicketPolicies(): iterable
    {
        yield 'cloud AI for everyone, an open ticket' => [self::allowedView(), false, null];
        yield 'cloud AI for everyone, a team-only ticket the admin lets go to cloud AI' => [self::allowedView(isRestrictedToCloudAllowed: true), true, null];
        yield 'cloud AI, a team-only ticket kept from cloud AI' => [self::allowedView(), true, 'Your admin keeps team-only tickets away from cloud AI.'];
        yield 'laptop only, which the server never runs' => [self::allowedView(PolicyView::WHERE_LAPTOP), false, 'Your company runs AI on each laptop, and it is not running on yours. Use “Local AI” at the top to set it up.'];
        yield 'laptop only says so before a team-only ticket kept from cloud AI' => [self::allowedView(PolicyView::WHERE_LAPTOP), true, 'Your company runs AI on each laptop, and it is not running on yours. Use “Local AI” at the top to set it up.'];
        yield 'laptop first goes to the cloud on the server' => [self::allowedView(PolicyView::WHERE_LAPTOP_FIRST), false, null];
        yield 'laptop first, a team-only ticket kept from cloud AI' => [self::allowedView(PolicyView::WHERE_LAPTOP_FIRST), true, 'Your admin keeps team-only tickets away from cloud AI.'];
        yield 'AI off says so before the ticket' => [new PolicyView('ai_off', 'Your admin turned AI help off.', PolicyView::WHERE_CLOUD, false, false, false), true, 'Your admin turned AI help off.'];
        yield 'leads only says so before the laptop' => [new PolicyView('leads_only', 'Your admin allows AI help for leads only.', PolicyView::WHERE_LAPTOP, false, false, false), false, 'Your admin allows AI help for leads only.'];
    }

    #[Test]
    #[DataProvider('serverTicketPolicies')]
    public function aTicketIsRefusedTheServersAiInTheGatewaysOrderWithoutReadingAnything(PolicyView $view, bool $isTicketTeamOnly, ?string $expectedLine): void
    {
        $policy = $this->policyWith(new AiSettings(true, false, false, false, 'cloud', false, null, null));

        self::assertSame($expectedLine, $policy->serverTicketRefusalOf($view, $isTicketTeamOnly));
        self::assertSame([], $this->settingsReads);
        self::assertSame([], $this->roleLookups);
    }

    /**
     * @return iterable<string, array{PolicyView, string|null}>
     */
    public static function serverDraftPolicies(): iterable
    {
        yield 'cloud AI that keeps team-only tickets from it, as a draft is not team-only' => [self::allowedView(), null];
        yield 'laptop first goes to the cloud on the server' => [self::allowedView(PolicyView::WHERE_LAPTOP_FIRST), null];
        yield 'laptop only, where no laptop checks a draft yet' => [self::allowedView(PolicyView::WHERE_LAPTOP), 'Your company runs AI on laptops, and “Check with AI” does not run there yet.'];
        yield 'AI off' => [new PolicyView('ai_off', 'Your admin turned AI help off.', PolicyView::WHERE_CLOUD, false, false, false), 'Your admin turned AI help off.'];
        yield 'leads only says so before the laptop' => [new PolicyView('leads_only', 'Your admin allows AI help for leads only.', PolicyView::WHERE_LAPTOP, false, false, false), 'Your admin allows AI help for leads only.'];
    }

    #[Test]
    #[DataProvider('serverDraftPolicies')]
    public function aDraftIsRefusedTheServersAiInTheGatewaysOrderWithoutReadingAnything(PolicyView $view, ?string $expectedLine): void
    {
        $policy = $this->policyWith(new AiSettings(true, false, false, false, 'cloud', false, null, null));

        self::assertSame($expectedLine, $policy->serverDraftRefusalOf($view));
        self::assertSame([], $this->settingsReads);
        self::assertSame([], $this->roleLookups);
    }

    private static function allowedView(string $whereAiRuns = PolicyView::WHERE_CLOUD, bool $isRestrictedToCloudAllowed = false): PolicyView
    {
        return new PolicyView(null, null, $whereAiRuns, false, false, $isRestrictedToCloudAllowed);
    }

    private function policyWith(AiSettings $settings): OrgSettingsAiPolicy
    {
        $aiSettingsQueries = new readonly class($settings, $this->recordSettingsRead(...)) implements AiSettingsQueries {
            /**
             * @param \Closure(OrganizationId): void $recordRead
             */
            public function __construct(private AiSettings $settings, private \Closure $recordRead)
            {
            }

            public function aiSettingsOf(OrganizationId $organizationId): AiSettings
            {
                ($this->recordRead)($organizationId);

                return $this->settings;
            }

            public function providerKeyOf(OrganizationId $organizationId): ?string
            {
                throw new \LogicException('A page never reads the provider key.');
            }
        };

        return new OrgSettingsAiPolicy($aiSettingsQueries, self::requestActors(), $this->acmeFoodsMemberships(), new PolicyEvaluator());
    }

    private function recordSettingsRead(OrganizationId $organizationId): void
    {
        $this->settingsReads[] = $organizationId->toString();
    }

    private function recordRoleLookup(OrganizationId $organizationId): void
    {
        $this->roleLookups[] = $organizationId->toString();
    }

    /**
     * The actors a request already built: Lena, lead of the shop team in Acme Foods, and Omar, a member of a team in
     * Beta Bakery; any other token user is nobody logged in to an organization.
     */
    private static function requestActors(): CurrentActor
    {
        return new readonly class(new TeamId(self::SHOP_TEAM)) implements CurrentActor {
            public function __construct(private TeamId $shop)
            {
            }

            public function actorFor(mixed $tokenUser): ?ActorContext
            {
                return match ($tokenUser) {
                    'lena in acme foods' => new ActorContext(new UserId(OrgSettingsAiPolicyTest::lena()), new OrganizationId(OrgSettingsAiPolicyTest::acmeFoods()), new Roles(false, false, [$this->shop], [], [])),
                    'omar in beta bakery' => new ActorContext(new UserId(OrgSettingsAiPolicyTest::omar()), new OrganizationId(OrgSettingsAiPolicyTest::betaBakery()), new Roles(false, false, [], [$this->shop], [])),
                    default => null,
                };
            }
        };
    }

    /**
     * Lena leads the shop team and Omar is a member of it, in Acme Foods only; as under row-level security, anyone
     * else, or any other organization, has no roles. Each lookup is recorded.
     */
    private function acmeFoodsMemberships(): MembershipQueries
    {
        return new readonly class($this->recordRoleLookup(...), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM)) implements MembershipQueries {
            /**
             * @param \Closure(OrganizationId): void $recordLookup
             */
            public function __construct(private \Closure $recordLookup, private OrganizationId $acmeFoods, private TeamId $shop)
            {
            }

            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('Not used by the AI policy.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                ($this->recordLookup)($organizationId);
                if (!$organizationId->equals($this->acmeFoods)) {
                    return Roles::none();
                }

                return match ($userId->toString()) {
                    OrgSettingsAiPolicyTest::lena() => new Roles(false, false, [$this->shop], [], []),
                    OrgSettingsAiPolicyTest::omar() => new Roles(false, false, [], [$this->shop], []),
                    default => Roles::none(),
                };
            }

            public function membersAmong(OrganizationId $organizationId, array $userIds): array
            {
                throw new \LogicException('Not used by the AI policy.');
            }
        };
    }

    public static function lena(): string
    {
        return self::LENA;
    }

    public static function omar(): string
    {
        return self::OMAR;
    }

    public static function acmeFoods(): string
    {
        return self::ACME_FOODS;
    }

    public static function betaBakery(): string
    {
        return self::BETA_BAKERY;
    }
}
