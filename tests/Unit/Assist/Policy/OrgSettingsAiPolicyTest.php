<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Policy;

use App\Assist\Api\PolicyView;
use App\Assist\Application\Policy\OrgSettingsAiPolicy;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrgSettingsAiPolicyTest extends TestCase
{
    public const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a6e1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a6e2';
    public const string LENA = '0197a3c0-0000-7000-8000-00000000a6f1';
    public const string OMAR = '0197a3c0-0000-7000-8000-00000000a6f2';
    public const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a601';

    #[Test]
    public function aViewerInAnOrganizationWithAiOffIsToldTheAdminTurnedItOff(): void
    {
        // Sync stays stored on, as an admin who turns AI off leaves it; nothing summarizes then.
        $view = $this->policyWith(new AiSettings(false, false, false, true, 'laptop_first', true, null, null, null))
            ->for(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS));

        self::assertFalse($view->isAllowed());
        self::assertSame(['ai_off', 'Your admin turned AI help off.'], [$view->refusalCode, $view->refusalSentence]);
        self::assertFalse($view->isSummaryOnSyncOn);
        self::assertFalse($view->isLaptopAllowed());
    }

    #[Test]
    public function aMemberIsToldAiIsForLeadsOnlyAndALeadIsLetIn(): void
    {
        $policy = $this->policyWith(new AiSettings(true, true, true, true, 'laptop_first', false, null, null, null));

        $memberView = $policy->for(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
        $leadView = $policy->for(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS));

        self::assertSame(['leads_only', 'Your admin allows AI help for leads only.'], [$memberView->refusalCode, $memberView->refusalSentence]);
        self::assertFalse($memberView->isLaptopAllowed());
        self::assertTrue($leadView->isAllowed());
        self::assertSame([null, null, 'laptop_first', true, true, false], [
            $leadView->refusalCode, $leadView->refusalSentence, $leadView->whereAiRuns, $leadView->isTitleOnly, $leadView->isSummaryOnSyncOn, $leadView->isRestrictedToCloudAllowed,
        ]);
        self::assertTrue($leadView->isLaptopAllowed());
    }

    #[Test]
    public function theRolesAreReadInTheAskedOrganization(): void
    {
        // Lena leads in Acme Foods only; asked about Beta Bakery she has no roles there.
        $view = $this->policyWith(new AiSettings(true, true, false, false, 'cloud', false, null, null, null))
            ->for(new UserId(self::LENA), new OrganizationId(self::BETA_BAKERY));

        self::assertSame('leads_only', $view->refusalCode);
    }

    #[Test]
    public function eachPlaceWhereTheAiRunsIsShownAsStored(): void
    {
        $whereShown = [];
        foreach (AiSettings::WHERE_VALUES as $whereAiRuns) {
            $view = $this->policyWith(new AiSettings(true, false, false, false, $whereAiRuns, true, null, null, null))
                ->for(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS));
            $whereShown[] = [$view->whereAiRuns, $view->isLaptopAllowed(), $view->isRestrictedToCloudAllowed, $view->isTitleOnly];
        }

        self::assertSame([['cloud', false, true, false], ['laptop', true, true, false], ['laptop_first', true, true, false]], $whereShown);
    }

    #[Test]
    public function aRefusalNeedsBothItsCodeAndItsSentence(): void
    {
        foreach ([['ai_off', null], [null, 'Your admin turned AI help off.']] as [$code, $sentence]) {
            try {
                new PolicyView($code, $sentence, 'cloud', false, false, false);
                self::fail('A policy view took half a refusal.');
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame('A refusal needs both its code and its sentence.', $refusal->getMessage());
            }
        }
    }

    #[Test]
    public function aPolicyViewRefusesAnUnknownPlaceWhereTheAiRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A policy view needs a known place where the AI runs.');

        new PolicyView(null, null, 'moon', false, false, false);
    }

    #[Test]
    public function aiSettingsRefuseAnUnknownPlaceWhereTheAiRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('AI settings need a known place where the AI runs.');

        new AiSettings(true, false, false, false, 'moon', false, null, null, null);
    }

    private function policyWith(AiSettings $settings): OrgSettingsAiPolicy
    {
        $aiSettingsQueries = new readonly class($settings) implements AiSettingsQueries {
            public function __construct(private AiSettings $settings)
            {
            }

            public function aiSettingsOf(OrganizationId $organizationId): AiSettings
            {
                return $this->settings;
            }
        };

        return new OrgSettingsAiPolicy($aiSettingsQueries, self::acmeFoodsMemberships(), new PolicyEvaluator());
    }

    /**
     * Lena leads the shop team and Omar is a member of it, in Acme Foods only; as under row-level security, anyone
     * else, or any other organization, has no roles.
     */
    private static function acmeFoodsMemberships(): MembershipQueries
    {
        return new class implements MembershipQueries {
            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('Not used by the AI policy.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                if (!$organizationId->equals(new OrganizationId(OrgSettingsAiPolicyTest::ACME_FOODS))) {
                    return Roles::none();
                }

                $shop = new TeamId(OrgSettingsAiPolicyTest::SHOP_TEAM);

                return match ($userId->toString()) {
                    OrgSettingsAiPolicyTest::LENA => new Roles(false, false, [$shop], [], []),
                    OrgSettingsAiPolicyTest::OMAR => new Roles(false, false, [], [$shop], []),
                    default => Roles::none(),
                };
            }

            public function membersAmong(OrganizationId $organizationId, array $userIds): array
            {
                throw new \LogicException('Not used by the AI policy.');
            }
        };
    }
}
