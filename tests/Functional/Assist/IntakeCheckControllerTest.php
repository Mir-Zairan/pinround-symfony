<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeAssist;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckRefused;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\AiCallLimit;
use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\IntakeAssistService;
use App\Assist\Infrastructure\Messenger\GenerateIntakeSuggestionHandler;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\Assist\UI\Component\IntakeCheck;
use App\Assist\UI\Controller\IntakeCheckController;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Assist\Laptop\SeedsLaptopAiTickets;
use App\Tests\Functional\Assist\Laptop\StoresAiSettings;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\UI\Voter\TicketCreationVoter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Check with AI" on the New ticket form through the real firewall, voters, session, AI policy, database, check state on
 * the test Valkey, the in-memory async_ai transport and Messenger's worker with FakeProvider answering what each test
 * scripts (SeedsLaptopAiTickets, with Acme Foods running AI in the cloud for everyone unless a test says otherwise). The
 * clock is fixed on 12 Nov 2026, before today. Besides SHOP-1 (everyone) and SHOP-2 (Shop team only), SHOP-3 is open to
 * everyone with a title that holds markup, and MOB-1 is the Mobile team's team-only ticket, which Omar may not see.
 * Every refusal also proves that no job was sent and no check state was kept.
 */
final class IntakeCheckControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RunsTheWorkerUntilIdle;
    use SeedsLaptopAiTickets;
    use StoresAiSettings;

    private const string CLOUD_AI = '{"on": true, "who": "everyone", "where": "cloud"}';
    private const string FRAME = 'turbo-frame#intake-check';
    private const string STATE = '[data-controller="intake-check"]';
    private const string USAGE_LIMITER_PREFIX = 'ai_calls_per_user-';
    private const array CHECK_STATE_PREFIXES = ['intake_request', 'intake_draft', 'intake_open'];
    private const string MARKUP_TITLE = '<script>alert(1)</script> Pay is charged twice';
    private const string MOBILE_TITLE = 'Login screen freezes on start';
    private const string FAILED_LINE = 'The AI answer failed. Try again.';
    private const string SECURITY_LINE = 'This report mentions security, so it stays out of cloud AI.';
    private const string AI_OFF_LINE = 'Your admin turned AI help off.';
    private const string LIMIT_LINE = 'Too many AI requests. Try again in a minute.';
    private const int DONE_FRAME_QUERY_COUNT = 15;

    private string $shopProjectId;

    private string $mobileProjectId;

    protected function setUp(): void
    {
        $this->seedLaptopAiTickets();
        $this->storeAiSettings($this->acmeFoods, self::decoded(self::CLOUD_AI));
        $this->shopProjectId = $this->projectIdOf('SHOP');
        $this->mobileProjectId = $this->addProject($this->mobile, 'MOB', 'Mobile');
        $this->addTicket($this->shopProjectId, $this->shop, 3, 'SHOP-3', self::MARKUP_TITLE, 'all');
        $this->addTicket($this->mobileProjectId, $this->mobile, 1, 'MOB-1', self::MOBILE_TITLE, 'team');
    }

    protected function tearDown(): void
    {
        $this->forgetCheckStatesAndCountedCalls();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ast_suggestion', 'ast_usage'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [GenerateIntakeSuggestionHandler::HANDLER_NAME]);
        $this->removeLaptopAiRows();
        $this->removeUsersAndOrganizations();
    }

    /**
     * The viewer, the stored AI settings, and the line shown instead of the note and button (null: the note and button).
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function policies(): iterable
    {
        yield 'cloud AI for everyone' => ['Omar', self::CLOUD_AI, null];
        yield 'AI off' => ['Omar', '{"on": false, "where": "cloud"}', self::AI_OFF_LINE];
        yield 'leads only, for Omar' => ['Omar', '{"on": true, "who": "leads_only", "where": "cloud"}', 'Your admin allows AI help for leads only.'];
        yield 'leads only, for Lena, who leads Shop' => ['Lena', '{"on": true, "who": "leads_only", "where": "cloud"}', null];
        yield 'a laptop organization' => ['Omar', '{"on": true, "who": "everyone", "where": "laptop"}', 'AI help runs on your laptop, and Ollama is not ready there yet.'];
    }

    #[Test]
    #[DataProvider('policies')]
    public function theNewTicketFormShowsTheAiNoteAndButtonOnlyWhenThePolicyLetsThePersonCheckADraft(string $viewer, string $aiJson, ?string $expectedLine): void
    {
        $this->storeAiSettings($this->acmeFoods, self::decoded($aiJson));
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/tickets/new');

        self::assertResponseStatusCodeSame(200);
        $frame = $page->filter('form '.self::FRAME);
        self::assertCount(1, $frame, 'The frame is inside the New ticket form, so its button posts the typed fields.');
        self::assertSame('polite', $frame->attr('aria-live'));
        self::assertSame('idle', $frame->filter(self::STATE)->attr('data-intake-check-state-value'));
        self::assertSame(null === $expectedLine ? [] : [$expectedLine], self::textsOf($frame->filter('.intake-check__ai-off')));
        self::assertSame(null === $expectedLine ? [IntakeCheck::NOTE] : [], self::textsOf($frame->filter('.intake-check__line')));
        self::assertSame(null === $expectedLine ? [IntakeCheck::CHECK_LABEL] : [], self::textsOf($frame->filter('button.intake-check__button')));
        self::assertCount(0, $page->filter('[autofocus]'), 'Only a frame answer moves the focus.');
        self::assertSame(['Make ticket'], self::textsOf($page->filter('form[action="/tickets/new"] button[type="submit"]')));
    }

    #[Test]
    public function checkWithAiQueuesOneJobAndAnswersTheRunningFrameWithTheCheckId(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->postCheck();

        self::assertResponseStatusCodeSame(200);
        $checkId = $this->checkIdOf($frame);
        self::assertEquals([new GenerateIntakeSuggestion($this->acmeFoods->toString(), $checkId, $this->idOf('Omar'))], $this->sentJobs());
        self::assertSame('running', $frame->filter(self::STATE)->attr('data-intake-check-state-value'));
        self::assertSame('/tickets/new/check/'.$checkId, $frame->filter(self::STATE)->attr('data-intake-check-url-value'));
        self::assertSame([IntakeCheck::RUNNING_LINE], self::textsOf($frame->filter('.intake-check__line')));
        self::assertSame([IntakeCheck::RUNNING_LABEL], self::textsOf($frame->filter('button.intake-check__button[disabled]')));
        self::assertCount(0, $frame->filter('button.intake-check__button:not([disabled])'));
        self::assertSame(['div.intake-check__state[tabindex="-1"]'], $this->autofocusedOf($this->client->getCrawler()));
        self::assertCount(0, $this->client->getCrawler()->filter('form, input[name="title"], textarea'), 'The answer re-renders only the frame, so the typed text stays.');
        self::assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
        self::assertSame(1, $this->stateValkey()->exists('intake_request:'.$this->acmeFoods->toString().':'.$checkId));
    }

    #[Test]
    public function aViewerOfTheTeamMayCheckADraftAsTheyMayMakeTheTicket(): void
    {
        $this->addTeamRole($this->people['Mia'], $this->acmeFoods, $this->shop, 'viewer');
        $this->logIn($this->client, $this->people['Mia']);

        $this->postCheck();

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->sentJobs());
    }

    /**
     * Who asks, and for which project: none may report there, so all answer alike.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function projectsThePersonMayNotReportIn(): iterable
    {
        yield 'Omar, in the Mobile project of a team he is not in' => ['Omar', 'mobile'];
        yield 'Mia of Mobile, in the Shop project' => ['Mia', 'shop'];
        yield 'Raj of Beta Bakery, in Acme Foods\' Shop project' => ['Raj', 'shop'];
        yield 'Omar, with a project id no project has' => ['Omar', '0197a3c0-0000-7000-8000-00000000dead'];
        yield 'Omar, with a malformed project id' => ['Omar', 'shop 1%00'];
        yield 'Omar, with no project' => ['Omar', ''];
    }

    #[Test]
    #[DataProvider('projectsThePersonMayNotReportIn')]
    public function aProjectThePersonMayNotReportInIsNotFoundAndNothingIsQueued(string $person, string $project): void
    {
        $this->logIn($this->client, $this->people[$person]);

        $this->postCheck(['project_id' => match ($project) {
            'shop' => $this->shopProjectId,
            'mobile' => $this->mobileProjectId,
            default => $project,
        }]);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('title', IntakeCheckController::PROJECT_NOT_FOUND);
        self::assertStringNotContainsString(IntakeCheck::RUNNING_LINE, (string) $this->client->getResponse()->getContent());
        $this->assertNothingWasQueued();
    }

    /**
     * The project's choice reads only the person's own teams, so only a voter that refuses a team the person is in
     * proves the route asks TicketPermission::CREATE too.
     */
    #[Test]
    public function aProjectTheCreationVoterRefusesIsNotFoundAndNothingIsQueued(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->checkToken();
        self::getContainer()->set(TicketCreationVoter::class, new class extends Voter {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return TicketPermission::CREATE === $attribute && $subject instanceof TeamId;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return false;
            }
        });

        $this->postCheck(token: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('title', IntakeCheckController::PROJECT_NOT_FOUND);
        $this->assertNothingWasQueued();
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function tokensThatAreNotTheChecks(): iterable
    {
        yield 'no token' => [null];
        yield 'the New ticket form\'s own token' => ['ticketing_ticket_new'];
    }

    #[Test]
    #[DataProvider('tokensThatAreNotTheChecks')]
    public function aCheckWithoutItsOwnTokenIsRefusedWith422AndNothingIsQueued(?string $otherTokenId): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->postCheck(token: null === $otherTokenId ? '' : $this->csrfTokenFor($this->client, $otherTokenId));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([IntakeCheckController::FORM_EXPIRED], self::textsOf($frame->filter('.intake-check__notice')));
        self::assertSame([IntakeCheck::CHECK_LABEL], self::textsOf($frame->filter('button.intake-check__button')));
        $this->assertNothingWasQueued();
    }

    #[Test]
    public function aCheckWithoutATitleIsRefusedWith422AndKeepsTheButton(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->postCheck(['title' => " \t "]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([IntakeCheckController::TITLE_MISSING], self::textsOf($frame->filter('.intake-check__notice')));
        self::assertSame([IntakeCheck::CHECK_LABEL], self::textsOf($frame->filter('button.intake-check__button')));
        $this->assertNothingWasQueued();
    }

    /**
     * The fields posted over the form's own, the status, and the line shown with the button kept.
     *
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function draftsTheBackendRefuses(): iterable
    {
        yield 'a title that mentions security, as cloud AI runs' => [['title' => 'Password reset sends the old one'], 403, self::SECURITY_LINE];
        yield 'a pin note that mentions security' => [['title' => 'Pay does nothing', 'pins' => [['note' => 'The token shows in the address bar']]], 403, self::SECURITY_LINE];
        yield 'a title over the AI cap' => [['title' => str_repeat('a', 201)], 422, 'The title is too long for AI help: 200 characters at most.'];
        yield 'a description that is not UTF-8' => [['description' => "Pay \xC3\x28 does nothing"], 422, 'This report has text AI help cannot read.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('draftsTheBackendRefuses')]
    public function aDraftTheBackendRefusesAnswersItsLineAndKeepsTheButton(array $fields, int $expectedStatus, string $expectedLine): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->postCheck($fields);

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame([$expectedLine], self::textsOf($frame->filter('.intake-check__notice')));
        self::assertSame([IntakeCheck::CHECK_LABEL], self::textsOf($frame->filter('button.intake-check__button')));
        self::assertCount(0, $frame->filter('.intake-check__ai-off'));
        $this->assertNothingWasQueued();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function policyRefusals(): iterable
    {
        yield 'AI off' => ['{"on": false, "where": "cloud"}', self::AI_OFF_LINE];
        yield 'leads only' => ['{"on": true, "who": "leads_only", "where": "cloud"}', 'Your admin allows AI help for leads only.'];
    }

    #[Test]
    #[DataProvider('policyRefusals')]
    public function aCheckThePolicyRefusesAnswers403WithItsLineAndNoButton(string $aiJson, string $expectedLine): void
    {
        $this->storeAiSettings($this->acmeFoods, self::decoded($aiJson));
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->postCheck();

        self::assertResponseStatusCodeSame(403);
        self::assertSame([$expectedLine], self::textsOf($frame->filter('.intake-check__ai-off')));
        self::assertCount(0, $frame->filter('button.intake-check__button'));
        $this->assertNothingWasQueued();
    }

    #[Test]
    public function aCheckPastTheAiCallLimitIsRefusedWith429AndKeepsTheButton(): void
    {
        $this->useUpTheAiCallsOf('Omar');
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->postCheck();

        self::assertResponseStatusCodeSame(429);
        self::assertSame([self::LIMIT_LINE], self::textsOf($frame->filter('.intake-check__notice')));
        self::assertSame([IntakeCheck::CHECK_LABEL], self::textsOf($frame->filter('button.intake-check__button')));
        $this->assertNothingWasQueued();
    }

    #[Test]
    public function aFourthCheckWhileThreeRunIsRefusedWith429AndQueuesNothing(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        for ($check = 1; $check <= 3; ++$check) {
            $this->postCheck();
            self::assertResponseStatusCodeSame(200);
        }

        $frame = $this->postCheck();

        self::assertResponseStatusCodeSame(429);
        self::assertSame([IntakeAssistService::TOO_MANY_OPEN_CHECKS_SENTENCE], self::textsOf($frame->filter('.intake-check__notice')));
        self::assertSame([], $this->sentJobs());
        self::assertCount(3, $this->checkStateKeysOf('intake_request'));
    }

    /**
     * Each refusal code IntakeAssist may throw, its status, and whether the button stays (notice) or goes (refusal).
     *
     * @return iterable<string, array{string, int, string|null}>
     */
    public static function refusalCodes(): iterable
    {
        yield 'unknown project' => [IntakeCheckRefused::UNKNOWN_PROJECT, 404, null];
        yield 'draft too long' => [IntakeCheckRefused::DRAFT_TOO_LONG, 422, 'notice'];
        yield 'unreadable draft' => [IntakeCheckRefused::UNREADABLE_DRAFT, 422, 'notice'];
        yield 'too many AI requests' => [IntakeCheckRefused::TOO_MANY_AI_REQUESTS, 429, 'notice'];
        yield 'too many open checks' => [IntakeCheckRefused::TOO_MANY_OPEN_CHECKS, 429, 'notice'];
        yield 'security words in the cloud' => [IntakeCheckRefused::SECURITY_SENSITIVE_DRAFT_TO_CLOUD, 403, 'notice'];
        yield 'not a member' => [IntakeCheckRefused::NOT_A_MEMBER, 403, 'refusal'];
        yield 'a policy code' => ['ai_off', 403, 'refusal'];
        yield 'a code the page does not know, failing closed' => ['a_code_added_later', 403, 'refusal'];
    }

    #[Test]
    #[DataProvider('refusalCodes')]
    public function eachRefusalCodeAnswersItsStatusAndLineAndNeverA500(string $code, int $expectedStatus, ?string $expectedKind): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->checkToken();
        self::getContainer()->set(IntakeAssistService::class, self::intakeAssistRefusingWith($code));

        $frame = $this->postCheck(token: $token, expectFrame: null !== $expectedKind);

        self::assertResponseStatusCodeSame($expectedStatus);
        if (null === $expectedKind) {
            self::assertSelectorTextContains('title', IntakeCheckController::PROJECT_NOT_FOUND);
            self::assertCount(0, $this->client->getCrawler()->filter(self::FRAME));
            self::assertSame([], $this->sentJobs());

            return;
        }
        $lineSelector = 'notice' === $expectedKind ? '.intake-check__notice' : '.intake-check__ai-off';
        self::assertSame(['AI help is not available for this draft.'], self::textsOf($frame->filter($lineSelector)));
        self::assertCount('notice' === $expectedKind ? 1 : 0, $frame->filter('button.intake-check__button'));
    }

    #[Test]
    public function withoutTurboACheckGoesBackToTheFormAndQueuesNothing(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/tickets/new/check', $this->checkFields($this->checkToken()));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/tickets/new');
        $this->assertNothingWasQueued();
    }

    #[Test]
    public function aVisitorIsSentToTheLoginPageAndNothingIsQueued(): void
    {
        $this->client->request('POST', '/tickets/new/check', ['project_id' => $this->shopProjectId, 'title' => 'Pay does nothing'], server: ['HTTP_TURBO_FRAME' => IntakeCheck::FRAME_ID]);

        self::assertResponseRedirects('/login', 302);
        $this->assertNothingWasQueued();
    }

    #[Test]
    public function aVisitorPollingACheckIsSentToTheLoginPage(): void
    {
        $checkId = $this->storedCheckOf('Omar', self::intakeResult(['SHOP-1']));

        $this->client->request('GET', '/tickets/new/check/'.$checkId, server: ['HTTP_TURBO_FRAME' => IntakeCheck::FRAME_ID]);

        self::assertResponseRedirects('/login', 302);
    }

    /**
     * The report text goes through as typed: CR LF line ends, a byte order mark, multibyte letters, no final newline.
     */
    #[Test]
    public function aCheckedDraftIsAnsweredWithItsSummaryTypeAndPriorityAndTheDuplicatesOmarMaySee(): void
    {
        $description = "\u{FEFF}Größe prüfen\r\nZahlung endet ohne Antwort";
        $this->logIn($this->client, $this->people['Omar']);
        $checkId = $this->checkIdOf($this->postCheck(['description' => $description]));
        self::assertResponseStatusCodeSame(200);

        $this->fakeProvider()->answerNextCallsWith(json_encode(self::intakeResult(['SHOP-1']), \JSON_THROW_ON_ERROR));
        self::runTheWorkerUntilIdle('async_ai');

        self::assertCount(1, $this->fakeProvider()->calls());
        $prompt = $this->fakeProvider()->calls()[0]['prompt']->content;
        self::assertStringContainsString('Größe prüfen', $prompt);
        self::assertStringContainsString('Zahlung endet ohne Antwort', $prompt);
        $frame = $this->frameOf($checkId);
        self::assertResponseStatusCodeSame(200);
        $state = $frame->filter(self::STATE);
        self::assertSame(['done', 'bug', 'major'], [$state->attr('data-intake-check-state-value'), $state->attr('data-intake-check-type-value'), $state->attr('data-intake-check-priority-value')]);
        self::assertSame(['In short: Pay does nothing on the last step.'], self::textsOf($frame->filter('.intake-check__summary')));
        self::assertSame([IntakeCheck::CHECK_AGAIN_LABEL], self::textsOf($frame->filter('button.intake-check__button:not([disabled])')));
        self::assertSame([['SHOP-1', 'Checkout fails', '/t/SHOP-1']], $this->duplicatesOf($frame));
        self::assertSame(0, $this->stateValkey()->exists('intake_draft:'.$this->acmeFoods->toString().':'.$checkId), 'The ended check drops its draft.');
    }

    /**
     * Stored as the job would never store it (a key Omar may not see), so only the frame's own read can drop it; in the
     * AI's order, not the tickets'.
     */
    #[Test]
    public function theDoneFrameDropsAKeyOmarMayNotSeeAndShowsAMarkupTitleEscaped(): void
    {
        $checkId = $this->storedCheckOf('Omar', self::intakeResult(['SHOP-3', 'MOB-1', 'SHOP-1', 'SHOP-2']));
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frameOf($checkId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([IntakeCheck::DUPLICATES_HEADING], self::textsOf($frame->filter('.intake-check__duplicates h3')));
        self::assertSame([
            ['SHOP-3', self::MARKUP_TITLE, '/t/SHOP-3'],
            ['SHOP-1', 'Checkout fails', '/t/SHOP-1'],
            ['SHOP-2', 'Checkout fails', '/t/SHOP-2'],
        ], $this->duplicatesOf($frame));
        // Inside the frame, a link without its own target would load the ticket into the frame.
        self::assertSame(['_top', '_top', '_top'], $frame->filter('.intake-check__duplicate a')->each(static fn (Crawler $link): ?string => $link->attr('data-turbo-frame')));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::MOBILE_TITLE, $content);
        self::assertStringNotContainsString('MOB-1', $content);
        self::assertCount(0, $frame->filter('script'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Pay is charged twice', $content);
    }

    #[Test]
    public function aDoneCheckWithNoSummaryAndNoDuplicatesSaysWhereTheSuggestionsAreMarked(): void
    {
        $checkId = $this->storedCheckOf('Omar', ['summary' => ' ', 'type' => 'Story', 'priority' => 'Minor', 'duplicates' => [], 'missing' => false, 'risky' => false]);
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frameOf($checkId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([IntakeCheck::NO_SUMMARY_LINE], self::textsOf($frame->filter('.intake-check__summary')));
        self::assertSame(['story', 'minor'], [$frame->filter(self::STATE)->attr('data-intake-check-type-value'), $frame->filter(self::STATE)->attr('data-intake-check-priority-value')]);
        self::assertCount(0, $frame->filter('.intake-check__duplicates'));
    }

    /**
     * Three candidates Omar may see (SHOP-3, SHOP-1, SHOP-2) and one he may not (MOB-1) cost what one does: one
     * visibility read and one ticket read, with the check, the membership and the transaction around them.
     */
    #[Test]
    public function theDoneFramesQueryCountDoesNotGrowWithItsDuplicates(): void
    {
        $oneDuplicate = $this->storedCheckOf('Omar', self::intakeResult(['SHOP-1']));
        $fourDuplicates = $this->storedCheckOf('Omar', self::intakeResult(['SHOP-3', 'MOB-1', 'SHOP-1', 'SHOP-2']));
        $this->logIn($this->client, $this->people['Omar']);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        // The session's first request does not refresh the user yet; every later poll does, as these two.
        $this->frameOf($oneDuplicate);

        $debugData->reset();
        $this->frameOf($oneDuplicate);
        self::assertResponseStatusCodeSame(200);
        $queriesForOne = self::queriesOf($debugData->getData());
        $debugData->reset();
        $this->frameOf($fourDuplicates);
        self::assertResponseStatusCodeSame(200);
        $queriesForFour = self::queriesOf($debugData->getData());

        self::assertCount(self::DONE_FRAME_QUERY_COUNT, $queriesForFour, implode("\n", $queriesForFour));
        self::assertSame($queriesForOne, $queriesForFour);
        $ticketReads = preg_grep('/\bFROM tkt_ticket\b/', $queriesForFour);
        self::assertIsArray($ticketReads);
        self::assertCount(1, $ticketReads, implode("\n", $queriesForFour));
    }

    #[Test]
    public function aPollWhileTheCheckRunsAnswersTheRunningFrame(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $checkId = $this->checkIdOf($this->postCheck());

        $frame = $this->frameOf($checkId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('running', $frame->filter(self::STATE)->attr('data-intake-check-state-value'));
        self::assertSame([IntakeCheck::RUNNING_LABEL], self::textsOf($frame->filter('button.intake-check__button[disabled]')));
    }

    #[Test]
    public function aCheckWhoseAnswerFailsSaysSoAndGivesTheButtonBack(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $checkId = $this->checkIdOf($this->postCheck());
        $this->fakeProvider()->answerNextCallsWith('not json', 'still not json');
        self::runTheWorkerUntilIdle('async_ai');

        $frame = $this->frameOf($checkId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('failed', $frame->filter(self::STATE)->attr('data-intake-check-state-value'));
        self::assertSame([self::FAILED_LINE], self::textsOf($frame->filter('.intake-check__failed')));
        self::assertSame([IntakeCheck::CHECK_LABEL], self::textsOf($frame->filter('button.intake-check__button:not([disabled])')));
    }

    /**
     * Who polls Omar's check: none is its asker, so all answer alike.
     *
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoDidNotAsk(): iterable
    {
        yield 'Lena, who leads his team' => ['Lena'];
        yield 'Sara, Acme Foods\' admin' => ['Sara'];
        yield 'Raj of Beta Bakery' => ['Raj'];
    }

    #[Test]
    #[DataProvider('peopleWhoDidNotAsk')]
    public function anotherPersonsCheckIsNotFound(string $person): void
    {
        $checkId = $this->storedCheckOf('Omar', self::intakeResult(['SHOP-1']));
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('GET', '/tickets/new/check/'.$checkId, server: ['HTTP_TURBO_FRAME' => IntakeCheck::FRAME_ID]);

        self::assertResponseStatusCodeSame(404);
        // The test's debug error page quotes this file's source, so the check's text is looked for in a frame only.
        self::assertSelectorTextContains('title', IntakeCheckController::CHECK_NOT_FOUND);
        self::assertCount(0, $this->client->getCrawler()->filter(self::FRAME));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function checkIdsOfNoCheck(): iterable
    {
        yield 'a malformed id' => ['not-a-uuid'];
        yield 'a version 4 uuid' => ['3f1c2b9e-8d4a-4c5b-9e7f-1a2b3c4d5e6f'];
        yield 'an id no check has' => ['0197a3c0-0000-7000-8000-00000000beef'];
    }

    #[Test]
    #[DataProvider('checkIdsOfNoCheck')]
    public function aCheckIdOfNoCheckIsNotFound(string $checkId): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/tickets/new/check/'.rawurlencode($checkId), server: ['HTTP_TURBO_FRAME' => IntakeCheck::FRAME_ID]);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('title', IntakeCheckController::CHECK_NOT_FOUND);
    }

    #[Test]
    public function withoutTurboAPollGoesBackToTheForm(): void
    {
        $checkId = $this->storedCheckOf('Omar', self::intakeResult(['SHOP-1']));
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/tickets/new/check/'.$checkId);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/tickets/new');
    }

    /**
     * @param array<string, mixed> $fields over the defaults
     */
    private function postCheck(array $fields = [], ?string $token = null, bool $expectFrame = true): Crawler
    {
        $answer = $this->client->request(
            'POST',
            '/tickets/new/check',
            [...$this->checkFields($token ?? $this->checkToken()), ...$fields],
            server: ['HTTP_TURBO_FRAME' => IntakeCheck::FRAME_ID],
        );
        $frame = $answer->filter(self::FRAME);
        if ($expectFrame && 404 !== $this->client->getResponse()->getStatusCode()) {
            self::assertCount(1, $frame);
        }

        return $frame;
    }

    /**
     * @return array<string, string> the form's fields as the button posts them; an empty token posts none
     */
    private function checkFields(string $token): array
    {
        $fields = ['project_id' => $this->shopProjectId, 'title' => 'Pay does nothing', 'description' => 'Pressing Pay on the last step does nothing.'];
        if ('' !== $token) {
            $fields[IntakeCheckController::CSRF_FIELD] = $token;
        }

        return $fields;
    }

    private function checkToken(): string
    {
        return $this->csrfTokenFor($this->client, IntakeCheckController::CSRF_ID);
    }

    private function frameOf(string $checkId): Crawler
    {
        $answer = $this->client->request('GET', '/tickets/new/check/'.$checkId, server: ['HTTP_TURBO_FRAME' => IntakeCheck::FRAME_ID]);
        $frame = $answer->filter(self::FRAME);
        self::assertCount(1, $frame);

        return $frame;
    }

    private function checkIdOf(Crawler $frame): string
    {
        $checkId = $frame->filter(self::STATE)->attr('data-intake-check-id-value');
        self::assertIsString($checkId);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $checkId);

        return $checkId;
    }

    /**
     * @return list<array{string, string, string|null}> each duplicate's key, linked title and link
     */
    private function duplicatesOf(Crawler $frame): array
    {
        return $frame->filter('.intake-check__duplicate')->each(static fn (Crawler $duplicate): array => [
            $duplicate->filter('.intake-check__duplicate-key')->text(),
            $duplicate->filter('a')->text(),
            $duplicate->filter('a')->attr('href'),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function textsOf(Crawler $elements): array
    {
        return $elements->each(static fn (Crawler $element): string => $element->text());
    }

    /**
     * @return list<string> each [autofocus] element of the answer as tag.class[tabindex], which Turbo focuses after its frame render
     */
    private function autofocusedOf(Crawler $answer): array
    {
        return $answer->filter('[autofocus]')->each(static fn (Crawler $element): string => \sprintf('%s.%s[tabindex="%s"]', $element->nodeName(), $element->attr('class'), $element->attr('tabindex')));
    }

    private function assertNothingWasQueued(): void
    {
        self::assertSame([], $this->sentJobs());
        foreach (self::CHECK_STATE_PREFIXES as $prefix) {
            self::assertSame([], $this->checkStateKeysOf($prefix), 'A refused check left '.$prefix.' state.');
        }
    }

    /**
     * @return list<string>
     */
    private function checkStateKeysOf(string $prefix): array
    {
        $keys = $this->stateValkey()->keys($prefix.':'.$this->acmeFoods->toString().':*');
        self::assertIsArray($keys);

        return array_values(array_filter($keys, is_string(...)));
    }

    /**
     * The jobs sent to async_ai by the last request: the client's kernel resets the in-memory transport before each.
     *
     * @return list<object>
     */
    private function sentJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async_ai');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(static fn ($envelope): object => $envelope->getMessage(), array_values($transport->getSent()));
    }

    /**
     * A done check of $person as the job stores it, through the migrator.
     *
     * @param array<string, mixed> $result
     *
     * @return string the check's id
     */
    private function storedCheckOf(string $person, array $result): string
    {
        $checkId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $checkId, $this->idOf($person), json_encode($result, \JSON_THROW_ON_ERROR)];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ast_suggestion (organization_id, id, user_id, purpose, result, model, prompt_version, created_at)
             VALUES (?, ?, ?, 'intake', ?::jsonb, 'test-model', 'intake.v1', TIMESTAMPTZ '2026-11-12 12:00:10+00')",
            $parameters,
        ));

        return $checkId;
    }

    /**
     * @param list<string> $duplicateKeys
     *
     * @return array{summary: string, type: string, priority: string, duplicates: list<string>, missing: bool, risky: bool}
     */
    private static function intakeResult(array $duplicateKeys): array
    {
        return ['summary' => 'Pay does nothing on the last step.', 'type' => 'Bug', 'priority' => 'Major', 'duplicates' => $duplicateKeys, 'missing' => false, 'risky' => false];
    }

    private static function intakeAssistRefusingWith(string $code): IntakeAssist
    {
        return new readonly class($code) implements IntakeAssist {
            public function __construct(private string $code)
            {
            }

            public function suggest(DraftReport $draft, UserId $requesterId, OrganizationId $organizationId): IntakeCheckId
            {
                throw new IntakeCheckRefused($this->code, 'AI help is not available for this draft.');
            }

            public function checkOf(IntakeCheckId $checkId, UserId $viewerId, OrganizationId $organizationId): ?IntakeCheckView
            {
                return null;
            }
        };
    }

    private function useUpTheAiCallsOf(string $person): void
    {
        $limit = self::getContainer()->get('test.assist_ai_call_limit');
        self::assertInstanceOf(AiCallLimit::class, $limit);
        $actor = new ActorContext($this->people[$person]->id(), $this->acmeFoods, Roles::none());
        for ($call = 1; $call <= 30; ++$call) {
            $limit->consume($actor);
        }
    }

    private function projectIdOf(string $key): string
    {
        $projectId = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$projectId): int {
            $projectId = $migrator->fetchOne('SELECT id FROM tkt_project WHERE key = ?', [$key]);

            return 0;
        });
        self::assertIsString($projectId);

        return $projectId;
    }

    /**
     * @param 'all'|'team' $visibility
     */
    private function addTicket(string $projectId, TeamId $teamId, int $number, string $key, string $title, string $visibility): void
    {
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $projectId, $teamId->toString(), $number, $key, $title,
            self::TICKET_TEXT, $this->people['Lena']->id()->toString(), $visibility,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'bug', 'major', 2, (SELECT id FROM wf_step WHERE core_code = 'todo' AND team_id IS NULL), ?, ?, 1, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));
    }

    private function forgetCheckStatesAndCountedCalls(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            foreach (self::CHECK_STATE_PREFIXES as $prefix) {
                $keys = $this->stateValkey()->keys($prefix.':'.$organizationId.':*');
                if (\is_array($keys) && [] !== $keys) {
                    $this->stateValkey()->del($keys);
                }
            }
            foreach ($this->people as $person) {
                self::getContainer()->get(ValkeyLimiterStorage::class)->delete(self::USAGE_LIMITER_PREFIX.$organizationId.':'.$person->id()->toString());
            }
        }
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }

    private function fakeProvider(): FakeProvider
    {
        return self::getContainer()->get(FakeProvider::class);
    }

    /**
     * @return array<string, bool|string>
     */
    private static function decoded(string $aiJson): array
    {
        /** @var array<string, bool|string> $ai */
        $ai = json_decode($aiJson, true, 2, \JSON_THROW_ON_ERROR);

        return $ai;
    }

    /**
     * @param array<mixed> $debugData doctrine.debug_data_holder's queries by connection
     *
     * @return list<string> the SQL of every query
     */
    private static function queriesOf(array $debugData): array
    {
        $sql = [];
        foreach ($debugData as $queriesOfOneConnection) {
            foreach (\is_array($queriesOfOneConnection) ? $queriesOfOneConnection : [] as $query) {
                $sql[] = \is_array($query) && \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        return $sql;
    }
}
