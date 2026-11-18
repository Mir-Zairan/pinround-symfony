<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist;

use App\Assist\Api\Summaries;
use App\Assist\Api\SummaryRequestRefused;
use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\AiCallLimit;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\QueuedSummaries;
use App\Assist\Application\Summaries\SummaryRequests;
use App\Assist\UI\Controller\SummaryController;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Assist\Laptop\SeedsLaptopAiTickets;
use App\Tests\Functional\Assist\Laptop\StoresAiSettings;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\UI\Voter\TicketVoter;
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
 * "The ask" on the ticket page and its summary frame through the real firewall, voters, session, AI policy, database,
 * request state on the test Valkey and the in-memory async_ai transport (SeedsLaptopAiTickets, with Acme Foods running
 * AI in the cloud for everyone unless a test says otherwise). The clock is fixed on 12 Nov 2026, before today. Every
 * refusal also proves that no job was sent and no request state was kept.
 */
final class SummaryControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsLaptopAiTickets;
    use StoresAiSettings;

    private const string CLOUD_AI = '{"on": true, "who": "everyone", "where": "cloud"}';
    private const string AI_SETTINGS_QUERY = "/^SELECT CASE WHEN jsonb_typeof\\(data->'ai'\\)/";
    private const string USAGE_LIMITER_PREFIX = 'ai_calls_per_user-';
    private const string RUNNING = '[data-summary-poll-state-value="running"]';
    private const string FAILED_LINE = 'The AI answer failed. Try again.';

    protected function setUp(): void
    {
        $this->seedLaptopAiTickets();
        $this->storeAiSettings($this->acmeFoods, self::cloudAi());
    }

    protected function tearDown(): void
    {
        $this->forgetRequestStatesAndCountedCalls();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM tkt_summary WHERE organization_id = ?',
                [$organizationId],
            ));
        }
        $this->removeLaptopAiRows();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function omarSeesALongAskCutAtAWordEndWithSummarizeWithAiInAPoliteFrame(): void
    {
        // 3 + 49 × 14 characters reach line 50, whose "prüfen" holds the 700th; CR LF line ends, no final newline.
        $this->setDescriptionOf('SHOP-1', 'Ab '.str_repeat("Größe prüfen\r\n", 50).'Zahlung endet');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        $frame = $page->filter('turbo-frame#ticket-ask');
        self::assertCount(1, $frame);
        self::assertSame('polite', $frame->attr('aria-live'));
        self::assertSame('Original text from the report · not summarized yet', $frame->filter('.ticket__ask-source')->text());
        // The HTML parser reads CR LF as LF.
        self::assertSame('Ab '.str_repeat("Größe prüfen\n", 49).'Größe …', $frame->filter('.ticket__ask-text--short')->text(normalizeWhitespace: false));
        self::assertSame('Ab '.str_repeat("Größe prüfen\n", 50).'Zahlung endet', $frame->filter('.ticket__ask-text--full')->text(normalizeWhitespace: false));
        self::assertSame(['Show the full ask', 'Show less'], [$frame->filter('.ticket__ask-full .ticket__ask-show')->text(), $frame->filter('.ticket__ask-full .ticket__ask-hide')->text()]);
        self::assertSame('Asked by Lena · 4 Nov', $frame->filter('.ticket__asked')->text());
        self::assertSame(['Summarize with AI'], $frame->filter('form .ticket__summarize')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('Summarizing…', $frame->filter('.ticket__summarize')->attr('data-turbo-submits-with'));
        self::assertSame('/t/SHOP-1/summary', $frame->filter('form')->attr('action'));
        $state = $frame->filter('[data-controller="summary-poll"]');
        self::assertSame(['idle', '/t/SHOP-1/summary'], [$state->attr('data-summary-poll-state-value'), $state->attr('data-summary-poll-url-value')]);
        self::assertCount(0, $frame->filter('.ticket__ask-ai-off'));
    }

    /**
     * The stored AI settings, the viewer, the ticket, and the line shown instead of the button (null: the button).
     *
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function policies(): iterable
    {
        yield 'AI off' => ['{"on": false, "where": "cloud"}', 'Omar', 'SHOP-1', 'Your admin turned AI help off.'];
        yield 'leads only, for Omar' => ['{"on": true, "who": "leads_only", "where": "cloud"}', 'Omar', 'SHOP-1', 'Your admin allows AI help for leads only.'];
        yield 'leads only, for Lena, who leads Shop' => ['{"on": true, "who": "leads_only", "where": "cloud"}', 'Lena', 'SHOP-1', null];
        yield 'a laptop organization' => ['{"on": true, "who": "everyone", "where": "laptop"}', 'Omar', 'SHOP-1', 'AI help runs on your laptop, and Ollama is not ready there yet.'];
        yield 'laptop first goes to the cloud' => ['{"on": true, "who": "everyone", "where": "laptop_first"}', 'Omar', 'SHOP-1', null];
        yield 'a team-only ticket kept from cloud AI' => [self::CLOUD_AI, 'Omar', 'SHOP-2', 'Your admin keeps team-only tickets away from cloud AI.'];
        yield 'a team-only ticket the admin lets go to cloud AI' => ['{"on": true, "where": "cloud", "restricted_to_cloud": true}', 'Omar', 'SHOP-2', null];
    }

    #[Test]
    #[DataProvider('policies')]
    public function theButtonShowsOnlyWhenThePolicyLetsThePersonSummarizeTheTicket(string $aiJson, string $viewer, string $key, ?string $expectedLine): void
    {
        $this->storeAiSettings($this->acmeFoods, self::decoded($aiJson));
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $frame = $page->filter('turbo-frame#ticket-ask');
        self::assertSame(null === $expectedLine ? [] : [$expectedLine], $frame->filter('.ticket__ask-ai-off')->each(static fn (Crawler $line): string => $line->text()));
        self::assertCount(null === $expectedLine ? 1 : 0, $frame->filter('.ticket__summarize'));
        self::assertCount(null === $expectedLine ? 1 : 0, $frame->filter('form'));
    }

    #[Test]
    public function aTicketPageReadsTheAiSettingsOnceForTheHeaderAndTheAsk(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('turbo-frame#ticket-ask .ticket__summarize'));
        $aiSettingsReads = preg_grep(self::AI_SETTINGS_QUERY, self::queriesOf($debugData->getData()));
        self::assertIsArray($aiSettingsReads);
        self::assertCount(1, $aiSettingsReads);
    }

    #[Test]
    public function summarizeWithAiQueuesOneJobAndASecondPressWhileItRunsQueuesNone(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postSummaryRequest('SHOP-1', $this->summaryToken());

        self::assertResponseStatusCodeSame(200);
        self::assertEquals([new GenerateSummary($this->acmeFoods->toString(), $this->shopTicketId, $this->idOf('Omar'))], $this->sentJobs());
        $frame = $this->client->getCrawler()->filter('turbo-frame#ticket-ask');
        self::assertCount(1, $frame->filter(self::RUNNING));
        self::assertSame('Summarizing…', $frame->filter('button.ticket__summarize[disabled]')->text());
        self::assertCount(0, $frame->filter('button.ticket__summarize:not([disabled])'));
        $queued = $this->stateOf($this->shopTicketId);
        self::assertNotNull($queued);
        self::assertSame([SummaryRequestView::QUEUED, $this->idOf('Omar')], [$queued->status, $queued->requesterId->toString()]);

        $this->logIn($this->client, $this->people['Lena']);
        $this->postSummaryRequest('SHOP-1', $this->summaryToken());

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->sentJobs());
        self::assertCount(1, $this->client->getCrawler()->filter('turbo-frame#ticket-ask '.self::RUNNING));
        $stillQueued = $this->stateOf($this->shopTicketId);
        self::assertNotNull($stillQueued);
        self::assertSame($this->idOf('Omar'), $stillQueued->requesterId->toString(), 'The refused press replaced the queued request.');
    }

    #[Test]
    public function withoutTurboAPressQueuesTheJobAndGoesBackToTheTicketPage(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/summary', [SummaryController::CSRF_FIELD => $this->summaryToken()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/SHOP-1');
        self::assertCount(1, $this->sentJobs());
    }

    #[Test]
    public function aPressPastTheAiCallLimitIsRefusedWith429AndKeepsTheButton(): void
    {
        $limit = self::getContainer()->get('test.assist_ai_call_limit');
        self::assertInstanceOf(AiCallLimit::class, $limit);
        $omar = new ActorContext($this->people['Omar']->id(), $this->acmeFoods, Roles::none());
        for ($call = 1; $call <= 30; ++$call) {
            $limit->consume($omar);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $this->postSummaryRequest('SHOP-1', $this->summaryToken());

        self::assertResponseStatusCodeSame(429);
        $frame = $this->client->getCrawler()->filter('turbo-frame#ticket-ask');
        self::assertSame('Too many AI requests. Try again in a minute.', $frame->filter('.ticket__ask-notice')->text());
        self::assertCount(1, $frame->filter('form .ticket__summarize'));
        self::assertSame([], $this->sentJobs());
        self::assertNull($this->stateOf($this->shopTicketId));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function policyRefusals(): iterable
    {
        yield 'AI off' => ['{"on": false}', 'SHOP-1', 'Your admin turned AI help off.'];
        yield 'a team-only ticket kept from cloud AI' => [self::CLOUD_AI, 'SHOP-2', 'Your admin keeps team-only tickets away from cloud AI.'];
    }

    #[Test]
    #[DataProvider('policyRefusals')]
    public function aPressThePolicyRefusesAnswers403WithItsLineAndNoButton(string $aiJson, string $key, string $expectedLine): void
    {
        $this->storeAiSettings($this->acmeFoods, self::decoded($aiJson));
        $this->logIn($this->client, $this->people['Omar']);

        $this->postSummaryRequest($key, $this->summaryToken());

        self::assertResponseStatusCodeSame(403);
        $frame = $this->client->getCrawler()->filter('turbo-frame#ticket-ask');
        self::assertSame($expectedLine, $frame->filter('.ticket__ask-ai-off')->text());
        self::assertCount(0, $frame->filter('.ticket__summarize'));
        self::assertSame([], $this->sentJobs());
        self::assertNull($this->stateOf('SHOP-1' === $key ? $this->shopTicketId : $this->teamOnlyTicketId));
    }

    #[Test]
    public function aRefusalCodeThePageDoesNotKnowAnswers403WithItsLineAndNoButton(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->summaryToken();
        self::getContainer()->set(QueuedSummaries::class, new readonly class implements Summaries {
            public function request(TicketId $ticketId, UserId $requesterId, OrganizationId $organizationId): void
            {
                throw new SummaryRequestRefused('a_code_added_later', 'AI help is not available for this ticket.');
            }

            public function latestRequestOf(TicketId $ticketId, UserId $viewerId, OrganizationId $organizationId): ?SummaryRequestView
            {
                return null;
            }
        });

        $this->postSummaryRequest('SHOP-1', $token);

        self::assertResponseStatusCodeSame(403);
        $frame = $this->client->getCrawler()->filter('turbo-frame#ticket-ask');
        self::assertSame('AI help is not available for this ticket.', $frame->filter('.ticket__ask-ai-off')->text());
        self::assertCount(0, $frame->filter('.ticket__summarize'));
        self::assertSame([], $this->sentJobs());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function tokensThatAreNotTheForms(): iterable
    {
        yield 'no token' => [null];
        yield 'another form\'s token' => ['ai_laptop'];
    }

    #[Test]
    #[DataProvider('tokensThatAreNotTheForms')]
    public function aPressWithoutTheFormsTokenQueuesNothing(?string $otherTokenId): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postSummaryRequest('SHOP-1', null === $otherTokenId ? null : $this->csrfTokenFor($this->client, $otherTokenId));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(SummaryController::FORM_EXPIRED, $this->client->getCrawler()->filter('turbo-frame#ticket-ask .ticket__ask-notice')->text());
        self::assertSame([], $this->sentJobs());
        self::assertNull($this->stateOf($this->shopTicketId));
    }

    #[Test]
    public function theFrameOfARunningRequestCarriesThePoll(): void
    {
        self::assertTrue($this->requests()->claim($this->acmeFoods, new TicketId($this->shopTicketId), $this->people['Lena']->id()));
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frameOf('/t/SHOP-1/summary');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ticket-ask', $frame->attr('id'));
        $state = $frame->filter(self::RUNNING);
        self::assertCount(1, $state);
        self::assertSame(
            ['/t/SHOP-1/summary', 'Summary saved for everyone.', 'Summarize with AI'],
            [$state->attr('data-summary-poll-url-value'), $state->attr('data-summary-poll-saved-toast-value'), $state->attr('data-summary-poll-ready-label-value')],
        );
        $button = $frame->filter('form[action="/t/SHOP-1/summary"] button.ticket__summarize[disabled]');
        self::assertSame(['Summarizing…', 'true', 'button'], [$button->text(), $button->attr('aria-busy'), $button->attr('data-summary-poll-target')]);
        self::assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
    }

    #[Test]
    public function theFrameOfAFailedRequestSaysSoAndGivesTheButtonBack(): void
    {
        $this->requests()->recordFailure($this->acmeFoods, new TicketId($this->shopTicketId), $this->people['Omar']->id(), self::FAILED_LINE);
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frameOf('/t/SHOP-1/summary');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::FAILED_LINE, $frame->filter('.ticket__ask-failed')->text());
        self::assertCount(1, $frame->filter('[data-summary-poll-state-value="failed"]'));
        self::assertCount(1, $frame->filter('form .ticket__summarize'));
    }

    #[Test]
    public function theFrameOfAStoredSummaryShowsItEscapedWithItsNoteAndNoPoll(): void
    {
        $this->addSummary('ai', '<script>alert(1)</script> Pay does nothing.', 'Omar', true, '2026-11-12 11:59:50+00');
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frameOf('/t/SHOP-1/summary');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $frame->filter('[data-summary-poll-state-value="done"]'));
        self::assertCount(0, $frame->filter(self::RUNNING));
        self::assertSame('In short AI SUMMARY written just now at Omar’s request', $frame->filter('.ticket__ask--summary .ticket__ask-source')->first()->text());
        self::assertSame('<script>alert(1)</script> Pay does nothing.', $frame->filter('.ticket__ask-summary-text')->text());
        self::assertCount(0, $frame->filter('script'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Pay does nothing.', (string) $this->client->getResponse()->getContent());
        self::assertSame(['Show original text', 'Hide original text'], [$frame->filter('.ticket__ask-original .ticket__ask-show')->text(), $frame->filter('.ticket__ask-original .ticket__ask-hide')->text()]);
        self::assertSame(self::TICKET_TEXT, $frame->filter('.ticket__ask-original .ticket__ask-text')->text());
        self::assertCount(0, $frame->filter('.ticket__summarize, .ticket__ask-earlier'));
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function storedSummaries(): iterable
    {
        yield 'asked for an hour ago' => ['ai', 'Omar', 'In short AI SUMMARY written on 12 Nov at Omar’s request'];
        yield 'written on sync' => ['sync', null, 'In short AI SUMMARY written from the Jira text when the ticket was synced'];
        yield 'written by a person' => ['human', 'Lena', 'In short'];
    }

    #[Test]
    #[DataProvider('storedSummaries')]
    public function theTicketPageShowsTheCurrentSummaryWithTheNoteItsSourceGets(string $source, ?string $author, string $expectedLine): void
    {
        $this->addSummary($source, 'Pay does nothing on the last step.', $author, true, '2026-11-12 11:00:00+00');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        $frame = $page->filter('turbo-frame#ticket-ask');
        self::assertSame($expectedLine, $frame->filter('.ticket__ask--summary > .ticket__ask-source')->text());
        self::assertSame('Pay does nothing on the last step.', $frame->filter('.ticket__ask-summary-text')->text());
        self::assertSame('Asked by Lena · 4 Nov', $frame->filter('.ticket__asked')->text());
    }

    #[Test]
    public function twoEarlierSummariesLoadNewestFirstInTheirLazyFrame(): void
    {
        // Inserted out of order; a third summary of SHOP-2 must not be listed.
        $this->addSummary('ai', 'The second take.', 'Omar', false, '2026-11-10 09:00:00+00');
        $this->addSummary('human', 'The current one.', 'Lena', true, '2026-11-11 09:00:00+00');
        $this->addSummary('sync', 'The first take.', null, false, '2026-11-08 09:00:00+00');
        $this->addSummary('human', 'Another ticket\'s.', 'Lena', false, '2026-11-09 09:00:00+00', $this->teamOnlyTicketId);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        $earlier = $page->filter('turbo-frame#ticket-ask details.ticket__ask-earlier');
        self::assertSame(['Show 2 earlier summaries', 'Hide 2 earlier summaries'], [$earlier->filter('.ticket__ask-show')->text(), $earlier->filter('.ticket__ask-hide')->text()]);
        $lazyFrame = $earlier->filter('turbo-frame#ticket-ask-earlier');
        self::assertSame(['/t/SHOP-1/summary/earlier', 'lazy'], [$lazyFrame->attr('src'), $lazyFrame->attr('loading')]);

        $list = $this->frameOf('/t/SHOP-1/summary/earlier', 'ticket-ask-earlier');

        self::assertResponseStatusCodeSame(200);
        $items = $list->filter('.ticket__ask-earlier-item')->each(static fn (Crawler $item): array => [$item->filter('.ticket__ask-source')->text(), $item->filter('.ticket__ask-summary-text')->text()]);
        self::assertSame([
            ['Earlier summary 1 · written on 10 Nov at Omar’s request', 'The second take.'],
            ['Earlier summary 2 · written from the Jira text when the ticket was synced', 'The first take.'],
        ], $items);
        self::assertCount(0, $list->filter('.ticket__ask-earlier-more'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function framePaths(): iterable
    {
        yield 'the summary frame' => ['GET', '/summary'];
        yield 'the earlier summaries' => ['GET', '/summary/earlier'];
    }

    #[Test]
    #[DataProvider('framePaths')]
    public function aFrameRouteWithoutTurboGoesToTheTicketPage(string $method, string $path): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request($method, '/t/SHOP-1'.$path);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/SHOP-1');
    }

    /**
     * Who may not see the ticket, the ticket's key, and each route.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function hiddenTickets(): iterable
    {
        foreach (['GET /summary' => ['GET', '/summary'], 'GET /summary/earlier' => ['GET', '/summary/earlier'], 'POST /summary' => ['POST', '/summary']] as $route => [$method, $path]) {
            yield 'Mia of Mobile, on the Shop team\'s team-only ticket, '.$route => ['Mia', 'SHOP-2', $method, $path];
            yield 'Raj of Beta Bakery, on Acme Foods\' ticket, '.$route => ['Raj', 'SHOP-1', $method, $path];
            yield 'Omar, on a key no ticket has, '.$route => ['Omar', 'SHOP-99', $method, $path];
            yield 'Omar, on a malformed key, '.$route => ['Omar', 'shop 1%00', $method, $path];
        }
    }

    #[Test]
    #[DataProvider('hiddenTickets')]
    public function aTicketThePersonMayNotSeeIsNotFoundAndNothingIsQueued(string $viewer, string $key, string $method, string $path): void
    {
        $this->logIn($this->client, $this->people[$viewer]);
        $token = $this->summaryToken();

        $this->client->request($method, '/t/'.rawurlencode($key).$path, 'POST' === $method ? [SummaryController::CSRF_FIELD => $token] : [], server: ['HTTP_TURBO_FRAME' => 'ticket-ask']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TICKET_TEXT, (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->sentJobs());
        self::assertNull($this->stateOf($this->shopTicketId));
        self::assertNull($this->stateOf($this->teamOnlyTicketId));
    }

    /**
     * pageOf already leaves out a ticket the viewer may not see, so only a voter that refuses one pageOf finds proves
     * each route asks TicketPermission::VIEW too.
     */
    #[Test]
    #[DataProvider('routes')]
    public function aTicketTheVoterRefusesToShowIsNotFoundAndNothingIsQueued(string $method, string $path): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->summaryToken();
        self::getContainer()->set(TicketVoter::class, new class extends Voter {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return TicketPermission::VIEW === $attribute && $subject instanceof TicketVisibilityFacts;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return false;
            }
        });

        $this->client->request($method, '/t/SHOP-1'.$path, 'POST' === $method ? [SummaryController::CSRF_FIELD => $token] : [], server: ['HTTP_TURBO_FRAME' => 'ticket-ask']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TICKET_TEXT, (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->sentJobs());
        self::assertNull($this->stateOf($this->shopTicketId));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'GET /summary' => ['GET', '/summary'];
        yield 'GET /summary/earlier' => ['GET', '/summary/earlier'];
        yield 'POST /summary' => ['POST', '/summary'];
    }

    #[Test]
    #[DataProvider('routes')]
    public function aVisitorIsSentToTheLoginPageAndNothingIsQueued(string $method, string $path): void
    {
        $this->client->request($method, '/t/SHOP-1'.$path, server: ['HTTP_TURBO_FRAME' => 'ticket-ask']);

        self::assertResponseRedirects('/login', 302);
        self::assertSame([], $this->sentJobs());
        self::assertNull($this->stateOf($this->shopTicketId));
    }

    private function postSummaryRequest(string $key, ?string $token): void
    {
        $this->client->request(
            'POST',
            '/t/'.$key.'/summary',
            null === $token ? [] : [SummaryController::CSRF_FIELD => $token],
            server: ['HTTP_TURBO_FRAME' => 'ticket-ask'],
        );
    }

    /**
     * The frame of that id in the answer to Turbo's frame request.
     */
    private function frameOf(string $uri, string $frameId = 'ticket-ask'): Crawler
    {
        $answer = $this->client->request('GET', $uri, server: ['HTTP_TURBO_FRAME' => $frameId]);
        $frame = $answer->filter('turbo-frame#'.$frameId);
        self::assertCount(1, $frame);

        return $frame;
    }

    private function summaryToken(): string
    {
        return $this->csrfTokenFor($this->client, SummaryController::CSRF_ID);
    }

    private function setDescriptionOf(string $key, string $description): void
    {
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET description = ? WHERE key = ?',
            [$description, $key],
        ));
    }

    private function addSummary(string $source, string $text, ?string $author, bool $isCurrent, string $createdAt, ?string $ticketId = null): void
    {
        $isAi = 'ai' === $source;
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $ticketId ?? $this->shopTicketId, $text, $source,
            null === $author ? null : $this->idOf($author), $isAi ? 'test-model' : null, $isAi ? 'summary.v1' : null,
            $isCurrent ? 'true' : 'false', $createdAt,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, author_id, model, prompt_version, is_current, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $parameters,
        ));
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

    private function stateOf(string $ticketId): ?SummaryRequestView
    {
        return $this->requests()->latestOf($this->acmeFoods, new TicketId($ticketId));
    }

    private function requests(): SummaryRequests
    {
        $requests = self::getContainer()->get('test.assist_summary_requests');
        self::assertInstanceOf(SummaryRequests::class, $requests);

        return $requests;
    }

    private function forgetRequestStatesAndCountedCalls(): void
    {
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        foreach ($this->createdOrganizationIds as $organizationId) {
            $keys = $stateValkey->keys('summary_request:'.$organizationId.':*');
            if (\is_array($keys) && [] !== $keys) {
                $stateValkey->del($keys);
            }
            foreach ($this->people as $person) {
                self::getContainer()->get(ValkeyLimiterStorage::class)->delete(self::USAGE_LIMITER_PREFIX.$organizationId.':'.$person->id()->toString());
            }
        }
    }

    /**
     * @return array<string, bool|string>
     */
    private static function cloudAi(): array
    {
        return self::decoded(self::CLOUD_AI);
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
