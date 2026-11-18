<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist;

use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\UI\Component\TheAsk;
use App\Assist\UI\Controller\LaptopController;
use App\Assist\UI\Controller\SummaryController;
use App\Assist\UI\LocalAiChip;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Assist\Laptop\SeedsLaptopAiTickets;
use App\Tests\Functional\Assist\Laptop\StoresAiSettings;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * The laptop summary proposal under "The ask" and "Use as main summary" (P08.T9 step 4) through the real firewall,
 * voters, session, AI policy and database. SHOP-1 (for everyone) and SHOP-2 (team-only) are Shop's, which Lena leads
 * and Omar is a member of; Mia is a member of Mobile, Sara an Admin with no team role, Raj a member of Beta Bakery. The
 * clock is fixed on 12 Nov 2026, before today. SHOP-1's current summary was written on 10 Nov, and its proposal is
 * Omar's result of 11 Nov 09:00, among results the proposal must leave out.
 */
final class LaptopSummaryProposalTest extends WebTestCase
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

    private const string PROPOSAL = '0197a3c0-0000-7000-8000-00000000a001';
    private const string OLDER_RESULT = '0197a3c0-0000-7000-8000-00000000a002';
    private const string RESULT_BEFORE_THE_SUMMARY = '0197a3c0-0000-7000-8000-00000000a003';
    private const string OTHER_TICKETS_RESULT = '0197a3c0-0000-7000-8000-00000000a004';
    private const string MADE_MAIN_ALREADY = '0197a3c0-0000-7000-8000-00000000a005';
    private const string OTHER_ORGANIZATIONS_RESULT = '0197a3c0-0000-7000-8000-00000000a006';
    private const string TOO_LONG_RESULT = '0197a3c0-0000-7000-8000-00000000a007';
    private const string RESULT_TICKETING_REFUSES = '0197a3c0-0000-7000-8000-00000000a008';
    private const string NEXT_PROPOSAL = '0197a3c0-0000-7000-8000-00000000a009';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-00000000a0ff';

    /** Markup a page must show as text, with a line end, a multibyte word and an ampersand. */
    private const string PROPOSAL_TEXT = "<b>Pay</b> does nothing on the last step & the Größe shows <script>alert(1)</script>.\nIt fails for every card.";
    private const string LABEL = 'posted by Omar using local model qwen3:4b (unverified)';
    private const string CURRENT_SUMMARY = 'Checkout fails on the last step.';

    protected function setUp(): void
    {
        $this->seedLaptopAiTickets();
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->addSummary($this->shopTicketId, self::CURRENT_SUMMARY, '2026-11-10 10:00:00+00');
        $this->addResult($this->acmeFoods, self::RESULT_BEFORE_THE_SUMMARY, $this->shopTicketId, '2026-11-09 10:00:00+00', 'Written before the summary.');
        $this->addResult($this->acmeFoods, self::OLDER_RESULT, $this->shopTicketId, '2026-11-11 08:00:00+00', 'An older result.');
        $this->addResult($this->acmeFoods, self::PROPOSAL, $this->shopTicketId, '2026-11-11 09:00:00+00', self::PROPOSAL_TEXT);
        $this->addResult($this->acmeFoods, self::OTHER_TICKETS_RESULT, $this->teamOnlyTicketId, '2026-11-11 10:00:00+00', 'Another ticket’s result.');
        $this->addResult($this->acmeFoods, self::MADE_MAIN_ALREADY, $this->shopTicketId, '2026-11-11 11:00:00+00', 'Made the main summary before.', '2026-11-11 11:30:00+00');
        $this->addResult($this->betaBakery, self::OTHER_ORGANIZATIONS_RESULT, $this->shopTicketId, '2026-11-11 12:00:00+00', 'Another organization’s result.');
    }

    protected function tearDown(): void
    {
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
    public function omarSeesTheNewestResultAfterTheSummaryAsAProposalWithItsMarkupAsTextAndNoButton(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        $proposals = $page->filter('turbo-frame#ticket-ask .ticket__ask-proposal');
        self::assertCount(1, $proposals);
        self::assertSame(TheAsk::PROPOSAL_HEADING.' · '.self::LABEL, $proposals->filter('.ticket__ask-source')->text());
        self::assertSame(self::PROPOSAL_TEXT, $proposals->filter('.ticket__ask-proposal-text')->text(normalizeWhitespace: false));
        self::assertCount(0, $proposals->filter('b, script'));
        self::assertSame(self::CURRENT_SUMMARY, $page->filter('.ticket__ask--summary .ticket__ask-summary-text')->text());
        self::assertCount(0, $proposals->filter('form'));
    }

    #[Test]
    public function aTicketWithNoSummaryShowsItsProposalUnderTheAsk(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/SHOP-2');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.ticket__ask--summary'));
        self::assertSame(['Another ticket’s result.'], $page->filter('.ticket__ask-proposal-text')->each(static fn (Crawler $text): string => $text->text()));
        self::assertSame('/t/SHOP-2/summary/laptop/'.self::OTHER_TICKETS_RESULT, $page->filter('.ticket__ask-proposal form')->attr('action'));
    }

    #[Test]
    public function lenaWhoLeadsShopMakesTheProposalTheMainSummaryAndTheOldOneIsKeptInHistory(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', '/t/SHOP-1');
        self::assertResponseStatusCodeSame(200);
        $form = $page->filter('.ticket__ask-proposal form')->form();
        self::assertSame('https://localhost/t/SHOP-1/summary/laptop/'.self::PROPOSAL, $form->getUri());

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/SHOP-1');
        self::assertSame([[self::PROPOSAL_TEXT, 'laptop', true, $this->idOf('Lena')], [self::CURRENT_SUMMARY, 'ai', false, null]], $this->summaryRows());
        self::assertSame([self::PROPOSAL, self::MADE_MAIN_ALREADY], $this->madeMainPackageIds());
        self::assertSame([], $this->sentAiJobs(), 'A person clicks; no AI runs.');
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(SummaryController::MADE_MAIN_SUMMARY, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame('In short AI SUMMARY posted by Omar using local model qwen3:4b (unverified), made the main summary by Lena', $page->filter('.ticket__ask--summary > .ticket__ask-source')->text());
        self::assertSame('Show 1 earlier summary', $page->filter('.ticket__ask-earlier .ticket__ask-show')->text());
        // The result older than the new summary is no proposal any more.
        self::assertCount(0, $page->filter('.ticket__ask-proposal'));
    }

    #[Test]
    public function theReporterMayMakeItTheMainSummaryWithAiOffAsNoAiRuns(): void
    {
        $this->storeAiSettings($this->acmeFoods, ['on' => false, 'where' => 'laptop']);
        $this->setReporterOfShop1('Mia');
        $this->logIn($this->client, $this->people['Mia']);

        $this->postChoice('SHOP-1', self::PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID));

        self::assertResponseStatusCodeSame(303);
        self::assertSame([[self::PROPOSAL_TEXT, 'laptop', true, $this->idOf('Mia')], [self::CURRENT_SUMMARY, 'ai', false, null]], $this->summaryRows());
    }

    #[Test]
    public function aViewerOfShopWhoReportedTheTicketSeesNoButtonAndChoosingAnswers404(): void
    {
        $this->addTeamRole($this->people['Mia'], $this->acmeFoods, $this->shop, 'viewer');
        $this->setReporterOfShop1('Mia');
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', '/t/SHOP-1');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.ticket__ask-proposal'));
        self::assertCount(0, $page->filter('.ticket__ask-proposal form'));

        $this->postChoice('SHOP-1', self::PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID));

        self::assertResponseStatusCodeSame(404);
        $this->assertNothingChanged();
    }

    /**
     * @return iterable<string, array{string, string, string}> the person, the ticket key and the package id
     */
    public static function refusedChoices(): iterable
    {
        yield 'Omar, a member of Shop who neither leads it nor reported the ticket' => ['Omar', 'SHOP-1', self::PROPOSAL];
        yield 'Sara, an Admin with no team role' => ['Sara', 'SHOP-1', self::PROPOSAL];
        yield 'Mia, of another team, on a team-only ticket' => ['Mia', 'SHOP-2', self::OTHER_TICKETS_RESULT];
        yield 'Raj, of another organization' => ['Raj', 'SHOP-1', self::PROPOSAL];
        yield 'a malformed package id' => ['Lena', 'SHOP-1', 'not-a-uuid'];
        yield 'an unknown package id' => ['Lena', 'SHOP-1', self::UNKNOWN];
        yield 'a result made the main summary already' => ['Lena', 'SHOP-1', self::MADE_MAIN_ALREADY];
        yield 'an older result than the proposal' => ['Lena', 'SHOP-1', self::OLDER_RESULT];
        yield 'a result older than the current summary' => ['Lena', 'SHOP-1', self::RESULT_BEFORE_THE_SUMMARY];
        yield 'another ticket’s result' => ['Lena', 'SHOP-1', self::OTHER_TICKETS_RESULT];
        yield 'another organization’s result' => ['Lena', 'SHOP-1', self::OTHER_ORGANIZATIONS_RESULT];
    }

    #[Test]
    #[DataProvider('refusedChoices')]
    public function aChoiceThePersonMayNotMakeOrOfNoProposalAnswers404AndChangesNothing(string $person, string $key, string $packageId): void
    {
        $this->logIn($this->client, $this->people[$person]);

        $this->postChoice($key, $packageId, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID));

        self::assertResponseStatusCodeSame(404);
        $this->assertNothingChanged();
    }

    #[Test]
    public function aVisitorIsSentToLogInAndNothingChanges(): void
    {
        $this->client->request('POST', '/t/SHOP-1/summary/laptop/'.self::PROPOSAL);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login', 302);
        $this->assertNothingChanged();
    }

    #[Test]
    public function anExpiredFormAnswers422WithItsLineAndChangesNothing(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->postChoice('SHOP-1', self::PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::CSRF_ID), asTurboStream: true);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(SummaryController::FORM_EXPIRED, (string) $this->client->getResponse()->getContent());
        $this->assertNothingChanged();
    }

    #[Test]
    public function anExpiredFormWithoutTurboGoesToTheTicketWithItsToastAndChangesNothing(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->postChoice('SHOP-1', self::PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::CSRF_ID));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/SHOP-1');
        $this->assertNothingChanged();
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(SummaryController::FORM_EXPIRED, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
    }

    /** The ticket's 404 comes before the form check, so a person who may not choose learns nothing from the token. */
    #[Test]
    public function omarWhoMayNotChooseGets404EvenWithAnExpiredFormAndNothingChanges(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postChoice('SHOP-1', self::PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::CSRF_ID), asTurboStream: true);

        self::assertResponseStatusCodeSame(404);
        $this->assertNothingChanged();
    }

    #[Test]
    public function aReplacedLaptopSummaryListsItsPosterModelAndChooserAmongTheEarlierSummaries(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $this->postChoice('SHOP-1', self::PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID));
        self::assertResponseStatusCodeSame(303);
        $this->clock->modify('+1 minute');
        $this->addResult($this->acmeFoods, self::NEXT_PROPOSAL, $this->shopTicketId, '2026-11-12 12:00:40+00', 'The next laptop result.');
        $this->setReporterOfShop1('Omar');
        $this->logIn($this->client, $this->people['Omar']);
        $this->postChoice('SHOP-1', self::NEXT_PROPOSAL, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID));
        self::assertResponseStatusCodeSame(303);

        $list = $this->client->request('GET', '/t/SHOP-1/summary/earlier', server: ['HTTP_TURBO_FRAME' => 'ticket-ask-earlier']);

        self::assertResponseStatusCodeSame(200);
        $items = $list->filter('.ticket__ask-earlier-item')->each(static fn (Crawler $item): array => [$item->filter('.ticket__ask-source')->text(), $item->filter('.ticket__ask-summary-text')->text(normalizeWhitespace: false)]);
        self::assertCount(2, $items);
        self::assertSame(['Earlier summary 1 · '.self::LABEL.', made the main summary by Lena', self::PROPOSAL_TEXT], $items[0]);
        self::assertSame(self::CURRENT_SUMMARY, $items[1][1]);
    }

    /**
     * A result stored with a control character stands for one Ticketing refuses as a summary, as accept() lets none
     * through.
     */
    #[Test]
    public function aResultTicketingRefusesAnswers422WithTheRuleAndStaysTheProposal(): void
    {
        $this->addResult($this->acmeFoods, self::RESULT_TICKETING_REFUSES, $this->shopTicketId, '2026-11-11 11:45:00+00', "Pay does nothing\u{0007} on the last step.");
        $this->logIn($this->client, $this->people['Lena']);

        $this->postChoice('SHOP-1', self::RESULT_TICKETING_REFUSES, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID), asTurboStream: true);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A summary is plain UTF-8 text; this one holds a control character or is not UTF-8.', (string) $this->client->getResponse()->getContent());
        $this->assertNothingChanged();
        $page = $this->client->request('GET', '/t/SHOP-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('/t/SHOP-1/summary/laptop/'.self::RESULT_TICKETING_REFUSES, $page->filter('.ticket__ask-proposal form')->attr('action'));
    }

    /**
     * A result kept before accept() capped a summary result at a summary's length is never offered, so the one before
     * it is, and choosing it answers as for no proposal.
     */
    #[Test]
    public function aStoredResultLongerThanASummaryIsNotOfferedAndChoosingItAnswers404(): void
    {
        $this->addResult($this->acmeFoods, self::TOO_LONG_RESULT, $this->shopTicketId, '2026-11-11 11:45:00+00', str_repeat('a', 2001));
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/SHOP-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('/t/SHOP-1/summary/laptop/'.self::PROPOSAL, $page->filter('.ticket__ask-proposal form')->attr('action'));

        $this->postChoice('SHOP-1', self::TOO_LONG_RESULT, $this->csrfTokenFor($this->client, SummaryController::MAIN_SUMMARY_CSRF_ID));

        self::assertResponseStatusCodeSame(404);
        $this->assertNothingChanged();
    }

    /**
     * The organization's AI settings, the ticket, and whether the Summarize with AI form is hidden, its line, and whether
     * the laptop-summary controller wraps them. SHOP-1 is for everyone, SHOP-2 team-only.
     *
     * @return iterable<string, array{array<string, bool|string>, string, bool, string|null, bool}>
     */
    public static function placesWhereAiRuns(): iterable
    {
        yield 'laptop only' => [['on' => true, 'who' => 'everyone', 'where' => 'laptop'], 'SHOP-1', true, PolicyRefusal::LaptopNotReady->sentence(), true];
        yield 'laptop first' => [['on' => true, 'who' => 'everyone', 'where' => 'laptop_first'], 'SHOP-1', false, null, true];
        yield 'the cloud' => [['on' => true, 'who' => 'everyone', 'where' => 'cloud'], 'SHOP-1', false, null, false];
        yield 'laptop only with AI off' => [['on' => false, 'where' => 'laptop'], 'SHOP-1', true, 'Your admin turned AI help off.', false];
        yield 'laptop only, a team-only ticket' => [['on' => true, 'who' => 'everyone', 'where' => 'laptop'], 'SHOP-2', true, PolicyRefusal::LaptopNotReady->sentence(), true];
        // The laptop may still run it, so the browser gets what it needs and shows the form once the laptop is ready.
        yield 'laptop first, a team-only ticket kept from cloud AI' => [['on' => true, 'who' => 'everyone', 'where' => 'laptop_first'], 'SHOP-2', true, PolicyRefusal::RestrictedTicketToCloud->sentence(), true];
    }

    /**
     * @param array<string, bool|string> $ai
     */
    #[Test]
    #[DataProvider('placesWhereAiRuns')]
    public function summarizeWithAiCarriesWhatTheLaptopNeedsOnlyWhereAiRunsOnLaptops(array $ai, string $key, bool $isHidden, ?string $line, bool $hasLaptopAttributes): void
    {
        $this->storeAiSettings($this->acmeFoods, $ai);
        $organizationId = $this->acmeFoods->toString();
        // The button shows only on a ticket with no summary.
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM tkt_summary WHERE organization_id = ?', [$organizationId]));
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $forms = $page->filter('form.ticket__ask-actions:has(.ticket__summarize)');
        self::assertSame(null === $line ? [] : [$line], $page->filter('.ticket__ask-ai-off')->each(static fn (Crawler $text): string => $text->text()));
        if (!$hasLaptopAttributes && $isHidden) {
            self::assertCount(0, $forms);

            return;
        }
        self::assertCount(1, $forms);
        self::assertSame($isHidden, null !== $forms->attr('hidden'));
        $laptopParts = $page->filter('[data-controller="laptop-summary"]');
        if (!$hasLaptopAttributes) {
            self::assertCount(0, $laptopParts);
            self::assertNull($forms->attr('data-action'));

            return;
        }
        self::assertCount(1, $laptopParts);
        self::assertCount(1, $laptopParts->filter('form.ticket__ask-actions[data-laptop-summary-target="form"][data-action="laptop-summary#summarize"]'));
        $ticketId = 'SHOP-1' === $key ? $this->shopTicketId : $this->teamOnlyTicketId;
        self::assertSame(
            ['/ai/laptop/package', '/ai/laptop/result', '/t/'.$key.'/summary', LocalAiChip::OLLAMA_ORIGIN.'/api/generate', LaptopController::CSRF_HEADER, $ticketId, true],
            [
                $laptopParts->attr('data-laptop-summary-package-url-value'),
                $laptopParts->attr('data-laptop-summary-result-url-value'),
                $laptopParts->attr('data-laptop-summary-frame-url-value'),
                $laptopParts->attr('data-laptop-summary-generate-url-value'),
                $laptopParts->attr('data-laptop-summary-csrf-header-value'),
                $laptopParts->attr('data-laptop-summary-ticket-id-value'),
                '' !== (string) $laptopParts->attr('data-laptop-summary-token-value'),
            ],
        );
        // Every line that keeps the server's AI from a laptop person's ticket is one a ready laptop cures.
        self::assertSame(null === $line ? [] : [$line], $laptopParts->filter('.ticket__ask-ai-off[data-laptop-summary-target="notReadyLine"]')->each(static fn (Crawler $text): string => $text->text()));
    }

    private function assertNothingChanged(): void
    {
        self::assertSame([[self::CURRENT_SUMMARY, 'ai', true, null]], $this->summaryRows());
        self::assertSame([self::MADE_MAIN_ALREADY], $this->madeMainPackageIds());
    }

    private function postChoice(string $key, string $packageId, string $token, bool $asTurboStream = false): void
    {
        $this->client->request(
            'POST',
            '/t/'.$key.'/summary/laptop/'.$packageId,
            [SummaryController::CSRF_FIELD => $token],
            server: $asTurboStream ? ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'] : [],
        );
    }

    private function setReporterOfShop1(string $person): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET reporter_id = ? WHERE key = 'SHOP-1'",
            [$this->idOf($person)],
        ));
    }

    private function addSummary(string $ticketId, string $text, string $createdAt): void
    {
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, author_id, model, prompt_version, is_current, created_at)
             VALUES (?, '0197a3c0-0000-7000-8000-00000000b001', ?, ?, 'ai', ?, 'test-model', 'summary.v1', true, CAST(? AS timestamptz))",
            [$organizationId, $ticketId, $text, $this->idOf('Omar'), $createdAt],
        ));
    }

    private function addResult(OrganizationId $organization, string $packageId, string $ticketId, string $usedAt, string $text, ?string $madeMainAt = null): void
    {
        $organizationId = $organization->toString();
        $omarId = $this->idOf('Omar');
        $this->committedAs($organization, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at, used_at, result_text, model, result_label, made_main_at)
             VALUES (?, ?, ?, ?, 'summary', 'summary.v1', CAST(? AS timestamptz) - interval '1 minute', CAST(? AS timestamptz) + interval '4 minutes', CAST(? AS timestamptz), ?, 'qwen3:4b', ?, CAST(? AS timestamptz))",
            [$organizationId, $packageId, $omarId, $ticketId, $usedAt, $usedAt, $usedAt, $text, self::LABEL, $madeMainAt],
        ));
    }

    /**
     * @return list<array{string, string, bool, string|null}> SHOP-1's summaries, current first: text, source, whether
     *                                                        current and who made it the main summary
     */
    private function summaryRows(): array
    {
        $rows = [];
        $ticketId = $this->shopTicketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ticketId, &$rows): int {
            $rows = $migrator->fetchAllNumeric('SELECT text, source, is_current, chosen_by_id FROM tkt_summary WHERE ticket_id = ? ORDER BY is_current DESC, created_at DESC', [$ticketId]);

            return \count($rows);
        });

        return array_map(static fn (array $row): array => [self::textOf($row[0]), self::textOf($row[1]), (bool) $row[2], null === $row[3] ? null : self::textOf($row[3])], $rows);
    }

    /**
     * @return list<string> Acme Foods' package ids made the main summary, in id order
     */
    private function madeMainPackageIds(): array
    {
        $ids = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$ids): int {
            $ids = $migrator->fetchFirstColumn('SELECT id FROM ast_local_package WHERE made_main_at IS NOT NULL AND organization_id = ? ORDER BY id', [$organizationId]);

            return \count($ids);
        });

        return array_map(self::textOf(...), $ids);
    }

    private static function textOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<object> the jobs the last request sent to async_ai
     */
    private function sentAiJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async_ai');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(static fn ($envelope): object => $envelope->getMessage(), array_values($transport->getSent()));
    }
}
