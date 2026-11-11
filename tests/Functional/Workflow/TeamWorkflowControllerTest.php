<?php

declare(strict_types=1);

namespace App\Tests\Functional\Workflow;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Infrastructure\Workflow\TicketStepOccupancy;
use App\Workflow\Api\StepOccupancy;
use App\Workflow\UI\TeamWorkflowController;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The team page's step and rule actions, POST /teams/{slug}/steps, /teams/{slug}/steps/remove and
 * /teams/{slug}/rules, on Acme Foods' team Shop through the real firewall, voters, session and database: who may do
 * each under which step policy, what is changed and which toast the team page then shows. Test organizations have no
 * core steps, so each test inserts the steps it needs.
 */
final class TeamWorkflowControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string ADD_STEP = '/teams/shop/steps';
    private const string REMOVE_STEP = '/teams/shop/steps/remove';
    private const string SET_RULE = '/teams/shop/rules';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
    }

    protected function tearDown(): void
    {
        $this->removeCommittedWorkflow();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theLeadAddsAStepToAGroupOfHerTeamUnderTheDefaultPolicy(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $this->client->request('POST', self::ADD_STEP, $this->addStepForm('  Design check ', 'check'));

        self::assertSame('Added “Design check” to Checking. Managers still see it as Checking.', $this->toastOfTheTeamPage());
        self::assertSame([['name' => 'Design check', 'team_id' => $this->shop->toString(), 'grp' => 'check', 'origin' => 'team', 'archived_at' => null]], $this->committedSteps());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoAreNotShopsLead(): iterable
    {
        yield 'Omar, a member' => ['Omar'];
        yield 'Mia, a viewer' => ['Mia'];
        yield 'Raj, a Manager' => ['Raj'];
        yield 'Sara, an Admin' => ['Sara'];
    }

    /**
     * Under the policy team, which lets the lead do all three, so only the person's role refuses them.
     */
    #[Test]
    #[DataProvider('peopleWhoAreNotShopsLead')]
    public function nobodyButTheLeadAddsOrRemovesStepsOrSetsRules(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $this->setStepPolicy(StepPolicy::Team);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $stepsBefore = $this->committedSteps();

        $this->client->request('POST', self::ADD_STEP, $this->addStepForm('Design check', 'check'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm($readyToMerge));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('no_self_review', TeamWorkflowController::IS_OFF));
        self::assertResponseStatusCodeSame(403);

        self::assertSame($stepsBefore, $this->committedSteps());
        self::assertSame([], $this->committedTeamRuleSets());
    }

    #[Test]
    public function theLeadOfAnotherTeamMayNotChangeShopsStepsOrRules(): void
    {
        $people = $this->createAcmeFoodsPeople(miaTeamRole: null);
        $this->createTeam($this->acmeFoods, 'Stock', $people['Mia']);
        $this->logIn($this->client, $people['Mia']);
        $this->setStepPolicy(StepPolicy::Team);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $stepsBefore = $this->committedSteps();

        $this->client->request('POST', self::ADD_STEP, $this->addStepForm('Design check', 'check'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm($readyToMerge));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('no_self_review', TeamWorkflowController::IS_OFF));
        self::assertResponseStatusCodeSame(403);

        self::assertSame($stepsBefore, $this->committedSteps());
        self::assertSame([], $this->committedTeamRuleSets());
    }

    #[Test]
    public function underTheLockedPolicyTheLeadMayNotAddOrRemoveSteps(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Locked);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $stepsBefore = $this->committedSteps();

        $this->client->request('POST', self::ADD_STEP, $this->addStepForm('Design check', 'check'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm($readyToMerge));
        self::assertResponseStatusCodeSame(403);

        self::assertSame($stepsBefore, $this->committedSteps());
    }

    /**
     * @return iterable<string, array{?StepPolicy}>
     */
    public static function policiesThatKeepTheRulesTheAdmins(): iterable
    {
        yield 'extend, as no settings row says' => [null];
        yield 'extend, as chosen' => [StepPolicy::Extend];
        yield 'locked' => [StepPolicy::Locked];
    }

    #[Test]
    #[DataProvider('policiesThatKeepTheRulesTheAdmins')]
    public function onlyUnderTheTeamPolicyDoesTheLeadSetRules(?StepPolicy $stepPolicy): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        if (null !== $stepPolicy) {
            $this->setStepPolicy($stepPolicy);
        }

        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('no_self_review', TeamWorkflowController::IS_OFF));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->committedTeamRuleSets());
    }

    #[Test]
    public function aPersonTheVoterRefusesGetsForbiddenBeforeTheFormTokenIsChecked(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);

        $this->client->request('POST', self::ADD_STEP, ['name' => 'Design check', 'group' => 'check', '_csrf_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->committedSteps());
    }

    #[Test]
    public function aFormTokenThatIsNotValidChangesNothing(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Team);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $stepsBefore = $this->committedSteps();
        // Each action has a token id of its own, so the token of another action is refused too.
        $addStepToken = $this->csrfTokenFor($this->client, TeamWorkflowController::ADD_STEP_CSRF_ID);

        $this->client->request('POST', self::ADD_STEP, ['name' => 'Design check', 'group' => 'check', '_csrf_token' => 'not-the-token']);
        self::assertSame(TeamWorkflowController::FORM_EXPIRED, $this->toastOfTheTeamPage());
        $this->client->request('POST', self::REMOVE_STEP, ['step_id' => $readyToMerge, '_csrf_token' => $addStepToken]);
        self::assertSame(TeamWorkflowController::FORM_EXPIRED, $this->toastOfTheTeamPage());
        $this->client->request('POST', self::SET_RULE, ['rule' => 'no_self_review', 'is_on' => '0', '_csrf_token' => $addStepToken]);
        self::assertSame(TeamWorkflowController::FORM_EXPIRED, $this->toastOfTheTeamPage());

        self::assertSame($stepsBefore, $this->committedSteps());
        self::assertSame([], $this->committedTeamRuleSets());
    }

    #[Test]
    public function aTeamOfAnotherOrganizationIsNotFound(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $bakeryLead = $this->createUserWithoutOrganization('Lena');
        $this->addMembership($bakeryLead, $betaBakery);
        $bread = $this->createTeam($betaBakery, 'Bread', $bakeryLead);
        $this->setStepPolicy(StepPolicy::Team, $betaBakery);
        $this->setStepPolicy(StepPolicy::Team);
        $breadStep = $this->insertStep($bread, 'Taste check', 'team', $betaBakery);

        $this->client->request('POST', '/teams/bread/steps', $this->addStepForm('Design check', 'check'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/teams/bread/steps/remove', $this->removeStepForm($breadStep));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/teams/bread/rules', $this->setRuleForm('no_self_review', TeamWorkflowController::IS_OFF));
        self::assertResponseStatusCodeSame(404);

        self::assertSame([['name' => 'Taste check', 'team_id' => $bread->toString(), 'grp' => 'check', 'origin' => 'team', 'archived_at' => null]], $this->committedSteps($betaBakery));
        self::assertSame([], $this->committedTeamRuleSets($betaBakery));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'add step' => [self::ADD_STEP];
        yield 'remove step' => [self::REMOVE_STEP];
        yield 'set rule' => [self::SET_RULE];
    }

    #[Test]
    #[DataProvider('actions')]
    public function anAnonymousVisitorIsSentToLogIn(string $action): void
    {
        $this->createAcmeFoodsPeople();
        $this->setStepPolicy(StepPolicy::Team);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $stepsBefore = $this->committedSteps();

        $this->client->request('POST', $action, ['name' => 'Design check', 'group' => 'check', 'step_id' => $readyToMerge, 'rule' => 'no_self_review', 'is_on' => '0']);

        self::assertResponseRedirects('/login', 302);
        self::assertSame($stepsBefore, $this->committedSteps());
        self::assertSame([], $this->committedTeamRuleSets());
    }

    #[Test]
    public function theLeadRemovesAStepOfHerTeamWhichIsArchivedAtTheTimeOfTheRequest(): void
    {
        $this->client->disableReboot();
        // An hour back, not a fixed date: the firewall ends a session that seems idle for longer than its limit.
        $clock = new MockClock(new \DateTimeImmutable('@'.(time() - 3600)));
        self::getContainer()->set('clock', $clock);
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $this->insertStep($this->shop, 'Design check', 'team');

        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm(strtoupper($readyToMerge)));

        self::assertSame(TeamWorkflowController::STEP_REMOVED, $this->toastOfTheTeamPage());
        self::assertSame([
            ['name' => 'Design check', 'team_id' => $this->shop->toString(), 'grp' => 'check', 'origin' => 'team', 'archived_at' => null],
            ['name' => 'Ready to merge', 'team_id' => $this->shop->toString(), 'grp' => 'check', 'origin' => 'team', 'archived_at' => $clock->now()->format('Y-m-d H:i:s')],
        ], $this->committedSteps());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepsThatAreNotShopsOwn(): iterable
    {
        yield 'a company step' => ['company'];
        yield 'a step of Mobile, which Lena leads too' => ['mobile'];
        yield 'a step of another organization' => ['other organization'];
        yield 'an id that is no step id' => ['no step id'];
    }

    #[Test]
    #[DataProvider('stepsThatAreNotShopsOwn')]
    public function aStepThatIsNotOneOfTheTeamsOwnIsNotRemoved(string $whichStep): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $stepId = match ($whichStep) {
            'company' => $this->insertStep(null, 'Design check', 'company'),
            'mobile' => $this->insertStep($this->mobile, 'Device check', 'team'),
            'other organization' => $this->insertStep(null, 'Taste check', 'company', $betaBakery),
            default => 'shop',
        };
        $stepsBefore = [$this->committedSteps(), $this->committedSteps($betaBakery)];

        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm($stepId));

        self::assertSame(TeamWorkflowController::STEP_NOT_OF_TEAM, $this->toastOfTheTeamPage());
        self::assertSame($stepsBefore, [$this->committedSteps(), $this->committedSteps($betaBakery)]);
    }

    #[Test]
    public function aCoreStepIsNotRemoved(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $codeReview = $this->insertStep(null, 'Code review', 'core', coreCode: 'review');
        $stepsBefore = $this->committedSteps();

        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm($codeReview));

        self::assertSame('Code review is a core step, which every team keeps.', $this->toastOfTheTeamPage());
        self::assertSame($stepsBefore, $this->committedSteps());
    }

    #[Test]
    public function aStepWithOpenTicketsIsNotRemoved(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(TicketStepOccupancy::class, new readonly class implements StepOccupancy {
            public function openTicketsIn(string $stepId): int
            {
                return 2;
            }
        });
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $readyToMerge = $this->insertStep($this->shop, 'Ready to merge', 'team');
        $stepsBefore = $this->committedSteps();

        $this->client->request('POST', self::REMOVE_STEP, $this->removeStepForm($readyToMerge));

        self::assertSame('2 open tickets are still in this step. Move them first.', $this->toastOfTheTeamPage());
        self::assertSame($stepsBefore, $this->committedSteps());
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function ruleSwitches(): iterable
    {
        yield 'a rule turned off' => [false, TeamWorkflowController::IS_OFF, 'Turned off: Nobody approves their own code review'];
        yield 'a rule turned on' => [true, TeamWorkflowController::IS_ON, 'Turned on: Nobody approves their own code review'];
    }

    /**
     * The organization's rules have the rule the other way round and tester_moves_done off, so the team's first own
     * rule set is seen to start as their copy.
     */
    #[Test]
    #[DataProvider('ruleSwitches')]
    public function underTheTeamPolicyTheLeadSetsARuleOfHerTeam(bool $isOn, string $isOnValue, string $toast): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Team);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, NULL, false, ?, true)',
            [$this->acmeFoods->toString(), !$isOn],
            [ParameterType::STRING, ParameterType::BOOLEAN],
        ));

        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('no_self_review', $isOnValue));

        self::assertSame($toast, $this->toastOfTheTeamPage());
        self::assertSame(
            [$this->shop->toString() => ['tester_moves_done' => false, 'no_self_review' => $isOn, 'review_before_test' => true]],
            $this->committedTeamRuleSets(),
        );
    }

    #[Test]
    public function anUnknownRuleIsToldAndNothingIsSaved(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Team);

        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('tester_moves_done_twice', TeamWorkflowController::IS_ON));

        self::assertSame(TeamWorkflowController::RULE_UNKNOWN, $this->toastOfTheTeamPage());
        self::assertSame([], $this->committedTeamRuleSets());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function onOffValuesThatAreNeither(): iterable
    {
        yield 'yes' => ['yes'];
        yield 'true' => ['true'];
        yield 'nothing' => [''];
    }

    #[Test]
    #[DataProvider('onOffValuesThatAreNeither')]
    public function anOnOffValueThatIsNeitherOneNorZeroIsABadRequest(string $isOn): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Team);

        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('no_self_review', $isOn));

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->committedTeamRuleSets());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedNewSteps(): iterable
    {
        yield 'a blank name' => ['   ', 'check', TeamWorkflowController::NAME_MISSING];
        yield 'an unknown group' => ['Design check', 'review', TeamWorkflowController::GROUP_UNKNOWN];
        yield 'the group done' => ['Design check', 'done', TeamWorkflowController::DONE_REFUSED];
    }

    #[Test]
    #[DataProvider('refusedNewSteps')]
    public function aRefusedNewStepIsToldAndNothingIsSaved(string $name, string $groupCode, string $toast): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $this->client->request('POST', self::ADD_STEP, $this->addStepForm($name, $groupCode));

        self::assertSame($toast, $this->toastOfTheTeamPage());
        self::assertSame([], $this->committedSteps());
    }

    #[Test]
    public function aStepPolicyLockedAfterTheVoterReadItIsToldAndNothingIsSaved(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(DbalSettingsQueries::class, self::settingsThatChangeAfterTheFirstRead(StepPolicy::Extend, StepPolicy::Locked));
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $this->client->request('POST', self::ADD_STEP, $this->addStepForm('Design check', 'check'));

        self::assertSame('Your admin has locked the steps, so teams cannot add their own.', $this->toastOfTheTeamPage());
        self::assertSame([], $this->committedSteps());
    }

    #[Test]
    public function aStepPolicyChangedFromTeamAfterTheVoterReadItIsToldAndNoRuleIsSaved(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(DbalSettingsQueries::class, self::settingsThatChangeAfterTheFirstRead(StepPolicy::Team, StepPolicy::Extend));
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $this->client->request('POST', self::SET_RULE, $this->setRuleForm('no_self_review', TeamWorkflowController::IS_OFF));

        self::assertSame('Only when your admin lets teams decide does a team set its own rules.', $this->toastOfTheTeamPage());
        self::assertSame([], $this->committedTeamRuleSets());
    }

    /**
     * The first read, the voter's, gives $first; every later one, the command's and the team page's, gives $later.
     */
    private static function settingsThatChangeAfterTheFirstRead(StepPolicy $first, StepPolicy $later): SettingsQueries
    {
        return new class($first, $later) implements SettingsQueries {
            private bool $wasRead = false;

            public function __construct(private readonly StepPolicy $first, private readonly StepPolicy $later)
            {
            }

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                $stepPolicy = $this->wasRead ? $this->later : $this->first;
                $this->wasRead = true;

                return $stepPolicy;
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private function addStepForm(string $name, string $groupCode): array
    {
        return ['name' => $name, 'group' => $groupCode, '_csrf_token' => $this->csrfTokenFor($this->client, TeamWorkflowController::ADD_STEP_CSRF_ID)];
    }

    /**
     * @return array<string, string>
     */
    private function removeStepForm(string $stepId): array
    {
        return ['step_id' => $stepId, '_csrf_token' => $this->csrfTokenFor($this->client, TeamWorkflowController::REMOVE_STEP_CSRF_ID)];
    }

    /**
     * @return array<string, string>
     */
    private function setRuleForm(string $ruleCode, string $isOn): array
    {
        return ['rule' => $ruleCode, 'is_on' => $isOn, '_csrf_token' => $this->csrfTokenFor($this->client, TeamWorkflowController::SET_RULE_CSRF_ID)];
    }

    /**
     * Follows the action's redirect to the team page and reads the toast it shows.
     */
    private function toastOfTheTeamPage(): string
    {
        self::assertResponseRedirects('/teams/shop', 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value');
    }

    /**
     * Without a settings row an organization has the policy extend.
     */
    private function setStepPolicy(StepPolicy $stepPolicy, ?OrganizationId $organizationId = null): void
    {
        $organizationId ??= $this->acmeFoods;
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, ?, '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), json_encode(['step_policy' => $stepPolicy->value], \JSON_THROW_ON_ERROR)],
        ));
    }

    /**
     * A step in the group Checking.
     *
     * @param ?TeamId                 $teamId null for a company or core step
     * @param 'core'|'company'|'team' $origin
     *
     * @return string the step's id
     */
    private function insertStep(?TeamId $teamId, string $name, string $origin, ?OrganizationId $organizationId = null, ?string $coreCode = null): string
    {
        $organizationId ??= $this->acmeFoods;
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, ?, ?, 'check', ?, ?, 0, NULL)",
            [$organizationId->toString(), $stepId, $teamId?->toString(), $name, $coreCode, $origin],
        ));

        return $stepId;
    }

    /**
     * Through the migrator, as committed, under the organization as tenant, ordered by name.
     *
     * @return list<array<string, mixed>>
     */
    private function committedSteps(?OrganizationId $organizationId = null): array
    {
        return $this->committedRows(
            $organizationId ?? $this->acmeFoods,
            "SELECT name, team_id, grp, origin, to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS archived_at FROM wf_step ORDER BY name",
        );
    }

    /**
     * @return array<string, array<string, mixed>> the rules of each team rule set, by team id
     */
    private function committedTeamRuleSets(?OrganizationId $organizationId = null): array
    {
        $ruleSets = [];
        foreach ($this->committedRows($organizationId ?? $this->acmeFoods, 'SELECT team_id, tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE team_id IS NOT NULL ORDER BY team_id') as $row) {
            $teamId = $row['team_id'];
            self::assertIsString($teamId);
            unset($row['team_id']);
            $ruleSets[$teamId] = $row;
        }

        return $ruleSets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function committedRows(OrganizationId $organizationId, string $query): array
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->migrator()->transactional(static fn (Connection $migrator): array => $migrator->fetchAllAssociative($query));
        } finally {
            $tenantContext->reset();
        }

        return $rows;
    }

    /**
     * Through the migrator, as the app may not delete steps or rule sets; must run before LogsInUsers' tearDown
     * deletes the organizations. The outbox rows are the events the actions published, found by organization id.
     */
    private function removeCommittedWorkflow(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_rule_set WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
    }
}
